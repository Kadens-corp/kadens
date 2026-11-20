<?php

namespace App\Controller;

use App\Enum\ActivityType;
use App\Form\PlanInstantiationType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Service\CalendarWeek;
use App\Service\PlanScheduler;
use App\Service\WorkoutMetrics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue calendrier des séances planifiées (instances datées). Rendu serveur,
 * navigation mois par mois via liens (Turbo Drive assure la fluidité) : la page
 * reste auto-suffisante, sans AJAX post-chargement.
 *
 * Les mutations (poser / instancier / déplacer / supprimer) sont portées par
 * ScheduledWorkoutController ; les formulaires d'ajout sont rendus ici mais
 * postent vers ce contrôleur, puis redirigent vers le mois concerné.
 */
#[Route('/calendar')]
final class CalendarController extends AbstractController
{
    public function __construct(
        private readonly WorkoutMetrics $workoutMetrics,
    ) {
    }

    #[Route('', name: 'app_calendar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $now = new \DateTimeImmutable('today');

        // « Placer dans mon agenda » depuis la page d'un plan (#68) arrive ici avec
        // `place` : on le reconduit, la vue ouvrira la modale sur ce plan.
        $carry = $request->query->getInt('place') > 0 ? ['place' => $request->query->getInt('place')] : [];

        // Respecte la dernière vue choisie (cookie posé au rendu mois/semaine) :
        // l'entrée « Calendrier » et un refresh retombent sur la bonne vue.
        if ('week' === $request->cookies->get('kd_calview')) {
            return $this->redirectToRoute('app_calendar_week', ['date' => $now->format('Y-m-d'), ...$carry]);
        }

        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $now->format('Y'),
            'month' => (int) $now->format('n'),
            ...$carry,
        ]);
    }

    #[Route('/{year}/{month}', name: 'app_calendar_month', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'])]
    public function month(
        int $year,
        int $month,
        Request $request,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        \App\Repository\GoalRepository $goalRepository,
        \App\Service\PlanFlattener $planFlattener,
        PlanScheduler $planScheduler,
    ): Response {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mois invalide.');
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $weeks = $this->buildWeeks($first, $month, $scheduledWorkoutRepository);

        // Objectifs tombant dans la fenêtre affichée (grille dense mois débords
        // compris) + prochain objectif pour le bandeau de compte à rebours.
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $gridStart = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $last = $first->modify('last day of this month');
        $gridEnd = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));
        $goalsByDate = CalendarWeek::goalsByDate($goalRepository->findByOwnerBetween($user, $gridStart, $gridEnd));
        $nextGoal = $goalRepository->findNextForOwner($user);

        // Aperçu au survol : une mise à plat par séance distincte du mois,
        // indexée par id de Workout (source unique PlanFlattener, cf. plans).
        // Une séance datée sans source (séance libre) n'a rien à aplatir : la
        // pastille retombe sur son titre.
        $flattened = [];
        $instructions = [];
        foreach ($weeks as $week) {
            foreach ($week as $cell) {
                // Consigne de case dans la modale (#95), même source que la page.
                $instructions += $planFlattener->planInstructions($cell['scheduled']);
                foreach ($cell['scheduled'] as $scheduled) {
                    $workout = $scheduled->getWorkout();
                    if (null !== $workout) {
                        $flattened[(int) $workout->getId()] ??= $planFlattener->flattenWorkout($workout);
                    }
                }
            }
        }

        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        // Pivot de la bascule « Semaine » : la semaine ouverte est celle contenant
        // aujourd'hui si le mois affiché est le mois courant, sinon son 1er jour.
        $today = new \DateTimeImmutable('today');
        $weekPivot = ((int) $today->format('Y') === $year && (int) $today->format('n') === $month)
            ? $today : $first;

        return $this->rememberView($this->render('calendar/index.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => CalendarWeek::MONTH_NAMES[$month].' '.$year,
            'weeks' => $weeks,
            'flattened' => $flattened,
            'instructions' => $instructions,
            'goalsByDate' => $goalsByDate,
            'nextGoal' => $nextGoal,
            'weekPivot' => $weekPivot->format('Y-m-d'),
            // Le cookie de vue est httpOnly, donc invisible au JS : c'est le
            // serveur qui dit si l'utilisateur a déjà choisi une vue. Tant qu'il
            // n'a rien choisi, un petit écran bascule sur la semaine (la grille
            // de 7 colonnes n'a pas de sens au téléphone). Un choix explicite
            // prime ensuite définitivement.
            'viewRemembered' => $request->cookies->has('kd_calview'),
            'prev' => ['year' => (int) $prev->format('Y'), 'month' => (int) $prev->format('n')],
            'next' => ['year' => (int) $next->format('Y'), 'month' => (int) $next->format('n')],
            ...$this->buildAddContext($request, $workoutRepository, $planTemplateRepository, $scheduledWorkoutRepository, $planScheduler),
        ]), 'month');
    }

    /**
     * Active ou régénère le jeton d'abonnement calendrier (ICS). Régénérer révoque
     * l'ancien lien (nouveau secret). Sous /calendar donc protégé ; le flux lui-même
     * vit sous /feed (hors auth), cf. PublicCalendarController.
     */
    #[Route('/feed/token', name: 'app_calendar_feed_token', methods: ['POST'])]
    public function generateFeedToken(
        Request $request,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('calendar_feed_token', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $regenerate = null !== $user->getCalendarFeedToken();
        $user->setCalendarFeedToken(bin2hex(random_bytes(32)));
        $entityManager->flush();

        $this->addFlash('success', $regenerate
            ? 'Lien d\'abonnement régénéré. L\'ancien lien ne fonctionne plus.'
            : 'Abonnement calendrier activé. Copie le lien pour l\'ajouter à ton agenda.');

        return $this->redirectToRoute('app_calendar_index');
    }

    #[Route('/week', name: 'app_calendar_week_index', methods: ['GET'])]
    public function weekIndex(): Response
    {
        return $this->redirectToRoute('app_calendar_week', [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
    }

    /**
     * Vue semaine : 7 jours lundi→dimanche autour de la date passée, cartes de
     * séance détaillées et bandeau de synthèse (observance + volume de la semaine).
     */
    #[Route('/week/{date}', name: 'app_calendar_week', methods: ['GET'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function week(
        string $date,
        Request $request,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        CalendarWeek $calendarWeek,
        PlanScheduler $planScheduler,
    ): Response {
        try {
            $ref = new \DateTimeImmutable($date);
        } catch (\Exception) {
            throw $this->createNotFoundException('Date invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // La semaine se construit au nom d'un propriétaire : ici soi, sur la
        // fiche athlète le coach la lit au nom de l'athlète (#77).
        return $this->rememberView($this->render('calendar/week.html.twig', [
            ...$calendarWeek->for($user, $ref),
            ...$this->buildAddContext($request, $workoutRepository, $planTemplateRepository, $scheduledWorkoutRepository, $planScheduler),
        ]), 'week');
    }

    /**
     * Mémorise la vue courante ('month' | 'week') dans un cookie lu par
     * app_calendar_index et par les redirections de mutation
     * (ScheduledWorkoutController) : la vue choisie survit à un refresh et aux
     * actions de planning.
     */
    private function rememberView(Response $response, string $view): Response
    {
        $response->headers->setCookie(
            Cookie::create('kd_calview', $view, new \DateTimeImmutable('+1 year'), '/', null, false, true, false, Cookie::SAMESITE_LAX)
        );

        return $response;
    }

    /**
     * Contexte commun aux vues mois et semaine : cartes de séances (modale « poser
     * une séance » ouverte par le « + » d'un jour), formulaire + cartes de plans
     * (modale « placer un plan dans l'agenda »), plans déjà posés (retrait rapide)
     * et statuts.
     *
     * Deux paramètres de query, tous deux des réglages d'affichage (une valeur
     * inconnue est ignorée, pas une erreur) :
     * - `place` : id d'un plan à présélectionner, la modale s'ouvre dessus (#68,
     *   bouton « Placer dans mon agenda » de la page du plan) ;
     * - `focus` : date (Y-m-d) à faire défiler à l'écran, posée par la redirection
     *   qui suit une pose de plan (la première séance).
     *
     * @return array<string, mixed>
     */
    private function buildAddContext(
        Request $request,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        PlanScheduler $planScheduler,
    ): array {
        $plans = $planTemplateRepository->findBy(['owner' => $this->getUser()], ['title' => 'ASC']);

        $instantiateForm = $this->createForm(PlanInstantiationType::class, null, [
            'action' => $this->generateUrl('app_scheduled_workout_instantiate'),
            'planTemplates' => $plans,
        ]);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $place = $request->query->getInt('place');
        $placeable = array_filter($plans, static fn ($plan): bool => $plan->getId() === $place);
        $focus = $request->query->getString('focus');

        return [
            'instantiateForm' => $instantiateForm,
            'instantiatedPlans' => $scheduledWorkoutRepository->findInstantiatedPlansForOwner($user),
            // Aperçu des dates dans la modale : la règle d'ancrage reste au service.
            'planPlacement' => $planScheduler->placementPreviews($plans, $user),
            'preselectPlanId' => [] !== $placeable ? $place : null,
            'focusDate' => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $focus) ? $focus : null,
            'statuses' => \App\Enum\ScheduledStatus::cases(),
            // Abonnement ICS : jeton (null tant que non activé) consommé par la
            // modale « S'abonner » ; les URLs de flux sont bâties côté Twig via url().
            'feedToken' => $user->getCalendarFeedToken(),
            ...$this->buildPickerCards($workoutRepository, $plans),
        ];
    }

    /**
     * Repères de carte pour les deux modales de sélection : séances de bibliothèque
     * (activités distinctes, nb d'exos, texte de recherche) et plans (durée, nb de
     * séances). Contenu fetch-joint pour les séances (anti N+1), calqué sur la
     * palette de l'éditeur de trame.
     *
     * @param list<\App\Entity\PlanTemplate> $plans
     *
     * @return array<string, mixed>
     */
    private function buildPickerCards(WorkoutRepository $workoutRepository, array $plans): array
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $workouts = $workoutRepository->findLibraryForOwnerWithContent($user);

        $cards = [];
        $countsByActivity = [];
        foreach ($workouts as $workout) {
            $activities = $this->workoutMetrics->distinctActivities($workout);

            $filterText = (string) $workout->getTitle();
            foreach ($activities as $activity) {
                $countsByActivity[$activity->value] = ($countsByActivity[$activity->value] ?? 0) + 1;
                $filterText .= ' '.$activity->getLabel();
            }

            $cards[] = [
                'workout' => $workout,
                'activities' => $activities,
                'exerciseCount' => $this->workoutMetrics->exerciseCount($workout),
                'filterText' => $filterText,
            ];
        }

        $activityFilters = [];
        foreach (ActivityType::cases() as $activity) {
            if (isset($countsByActivity[$activity->value])) {
                $activityFilters[] = [
                    'value' => $activity->value,
                    'label' => $activity->getLabel(),
                    'count' => $countsByActivity[$activity->value],
                ];
            }
        }

        $planCards = [];
        foreach ($plans as $plan) {
            $planCards[] = [
                'plan' => $plan,
                'weeks' => $plan->getDurationWeeks() ?? 0,
                'sessionCount' => $plan->getPlanItems()->count(),
            ];
        }

        return [
            'workoutCards' => $cards,
            'workoutActivities' => $activityFilters,
            'planCards' => $planCards,
        ];
    }

    /**
     * Construit la grille dense du mois : semaines ISO (lundi→dimanche) couvrant
     * le mois, débords des mois voisins compris pour remplir les cases. Les
     * séances planifiées sont chargées d'un seul coup sur toute la fenêtre puis
     * indexées par date.
     *
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, overdue: bool, scheduled: list<\App\Entity\ScheduledWorkout>}>>
     */
    private function buildWeeks(\DateTimeImmutable $first, int $month, ScheduledWorkoutRepository $repository): array
    {
        $gridStart = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $last = $first->modify('last day of this month');
        $gridEnd = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));

        $byDate = [];
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        foreach ($repository->findByOwnerBetween($user, $gridStart, $gridEnd) as $scheduled) {
            $byDate[$scheduled->getScheduledDate()->format('Y-m-d')][] = $scheduled;
        }

        $todayKey = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                $key = $cursor->format('Y-m-d');
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => (int) $cursor->format('n') === $month,
                    'isToday' => $key === $todayKey,
                    'overdue' => $key < $todayKey,
                    'scheduled' => $byDate[$key] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
