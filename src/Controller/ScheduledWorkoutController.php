<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Form\PlanInstantiationType;
use App\Repository\ImportedActivityRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Security\Voter\WorkoutVoter;
use App\Service\ImportedActivityPresenter;
use App\Service\LogComparator;
use App\Service\LogMetrics;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use App\Service\SessionRating;
use App\Service\TrackableSchedule;
use App\Service\VolumePanel;
use App\Service\WorkoutMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Mutations des séances planifiées (instances datées). Les actions redirigent
 * vers le calendrier concerné (dans la vue mémorisée, cf. preferredCalendarView) ;
 * le rendu du planning reste porté par CalendarController. Exception : le
 * changement de statut répond en Turbo Stream (re-render du seul fragment
 * concerné, sans rechargement), avec repli redirection sans JS. Le fragment
 * dépend de la page appelante : pastille de calendrier, ou pastille + section
 * « Réalisé » de la page de séance datée (qui poste `return=schedule`).
 */
#[Route('/schedule')]
final class ScheduledWorkoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ScheduledWorkoutRepository $scheduledWorkouts,
        private readonly TrackableSchedule $trackable,
        private readonly LogMetrics $logMetrics,
        private readonly ImportedActivityRepository $importedActivities,
    ) {
    }

    /**
     * Pose une séance de bibliothèque sur une date précise, hors de tout plan.
     * Endpoint lean posté par la modale « poser une séance » du calendrier (une
     * carte cliquée = un submit workoutId+date, calqué sur la palette de plan) :
     * pas de formulaire Symfony, la date vient du « + » du jour choisi.
     */
    #[Route('/place', name: 'app_scheduled_workout_place', methods: ['POST'])]
    public function place(Request $request, WorkoutRepository $workoutRepository): Response
    {
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('schedule_place', $payload->getString('_token'))) {
            return $this->redirectToCurrentMonth();
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('date')) ?: null;
        $workout = $workoutRepository->find($payload->getInt('workoutId'));

        // planLocal = copie privée portée par un plan : jamais posable seule.
        if (null === $date || null === $workout || $workout->isPlanLocal()) {
            $this->addFlash('error', 'Impossible de planifier cette séance.');

            return null === $date ? $this->redirectToCurrentMonth() : $this->redirectToMonth($date);
        }

        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        // Un jour passé, on ne planifie plus : on consigne ce qui a eu lieu
        // (#109). La modale y propose donc le statut tout de suite, Faite par
        // défaut. Un statut posté pour aujourd'hui ou le futur est ignoré : à
        // venir, une séance ne peut être que prévue.
        $past = $date < new \DateTimeImmutable('today');
        $status = $past ? ScheduledStatus::tryFrom($payload->getString('status')) : null;

        $scheduled = new ScheduledWorkout();
        $scheduled->setWorkout($workout);
        $scheduled->setScheduledDate($date);
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $scheduled->setOwner($user);
        $scheduled->setStatus($status ?? ScheduledStatus::PLANNED);
        $this->entityManager->persist($scheduled);
        $this->entityManager->flush();

        $this->addFlash('success', $this->placedMessage($date, $past, $status));

        return $this->redirectToMonth($date);
    }

    /**
     * « Séance planifiée » ne se dit que d'un jour à venir. Un jour passé, le
     * message dit où la séance est tombée et ce qu'elle est devenue (#109).
     */
    private function placedMessage(\DateTimeImmutable $date, bool $past, ?ScheduledStatus $status): string
    {
        $day = $date->format('d/m');

        if (!$past) {
            return sprintf('Séance planifiée le %s.', $day);
        }

        return match ($status) {
            ScheduledStatus::DONE => sprintf('Séance ajoutée au %s, marquée faite.', $day),
            ScheduledStatus::MISSED => sprintf('Séance ajoutée au %s, marquée manquée.', $day),
            default => sprintf('Séance ajoutée au %s. Dis si elle est faite ou manquée : elle compte en retard tant qu\'elle reste prévue.', $day),
        };
    }

    /**
     * Une séance faite porte le jour où elle a été faite (#109). La marquer
     * faite alors qu'elle est prévue plus tard, c'est l'avoir faite en avance
     * (le geste passe par une confirmation qui le dit) : elle revient à
     * aujourd'hui. Sinon elle compterait dans l'observance d'une semaine qui n'a
     * pas encore eu lieu, et dans la régularité d'un jour à venir.
     *
     * Rend la date d'origine quand la séance a été ramenée, null sinon.
     */
    private function bringDoneToToday(ScheduledWorkout $scheduled): ?\DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        $planned = $scheduled->getScheduledDate();

        if (ScheduledStatus::DONE !== $scheduled->getStatus() || $planned <= $today) {
            return null;
        }

        $scheduled->setScheduledDate($today);

        return $planned;
    }

    private function broughtToTodayMessage(\DateTimeImmutable $planned): string
    {
        return sprintf('Séance marquée faite et ramenée à aujourd\'hui : elle était prévue le %s.', $planned->format('d/m'));
    }

    /**
     * Place un plan complet dans l'agenda à partir d'une date : PlanScheduler
     * projette la trame sur des dates réelles et crée N ScheduledWorkout. Relancer
     * sur un plan déjà placé n'ajoute que les nouvelles cases (resync add-only).
     *
     * Ouvre ensuite l'agenda sur la première séance posée (#68) : avant, on
     * restait en haut du mois de la date de départ, les séances plus bas.
     */
    #[Route('/plan', name: 'app_scheduled_workout_instantiate', methods: ['POST'])]
    public function instantiate(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $form = $this->createForm(PlanInstantiationType::class, null, [
            'planTemplates' => $planTemplateRepository->findBy(['owner' => $user]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlanTemplate $template */
            $template = $form->get('planTemplate')->getData();
            /** @var \DateTimeImmutable $startDate */
            $startDate = $form->get('startDate')->getData();

            $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

            $alreadyOnCalendar = $planScheduler->isInstantiated($template, $user);
            $created = $planScheduler->instantiate($template, $user, $startDate);

            $first = null;
            foreach ($created as $scheduled) {
                if (null === $first || $scheduled->getScheduledDate() < $first) {
                    $first = $scheduled->getScheduledDate();
                }
            }

            $count = \count($created);
            if (!$alreadyOnCalendar) {
                $this->addFlash('success', 0 === $count
                    ? 'Ce plan n\'a encore aucune séance : rien n\'a été placé dans ton agenda.'
                    : sprintf(
                        'Plan placé dans ton agenda : %d séance%s, la première le %s.',
                        $count,
                        $count > 1 ? 's' : '',
                        $first->format('d/m/Y'),
                    ));
            } else {
                $this->addFlash('success', 0 === $count
                    ? 'Ce plan est déjà dans ton agenda et à jour : aucune séance ajoutée.'
                    : sprintf(
                        'Ce plan était déjà dans ton agenda : %d nouvelle%s séance%s ajoutée%s, les autres n\'ont pas bougé.',
                        $count,
                        $count > 1 ? 's' : '',
                        $count > 1 ? 's' : '',
                        $count > 1 ? 's' : '',
                    ));
                // Rien d'ajouté : on ouvre là où le plan commence déjà.
                $first ??= $planScheduler->firstScheduledDate($template, $user);
            }

            return $this->redirectToDay($first ?? $startDate);
        }

        $this->addFlash('error', 'Impossible de placer ce plan dans l\'agenda.');

        return $this->redirectToCurrentMonth();
    }

    /**
     * Consultation d'une séance dans son contexte daté. Distincte de
     * `app_workout_show`, qui montre la séance de **bibliothèque** : celle-ci ne
     * connaît aucune date, donc aucun statut à basculer — une même séance peut
     * être posée sur dix jours différents. C'est cette page qui porte la boucle
     * prévu vs réalisé, et c'est la cible du clic sur une pastille du calendrier.
     *
     * Contexte de rendu identique à WorkoutController::show (même composant de
     * lecture, mêmes services), plus les trois entrées du réalisé — que cette
     * page est la SEULE à passer au composant : c'est la portée, et non une
     * condition d'affichage, qui garde le réalisé hors de la bibliothèque, de la
     * page publique, de l'export Excel et du flux ICS. Le réalisé n'entre jamais
     * dans PlanFlattener ; il est lu par LogComparator et LogMetrics, appelés
     * ici et nulle part ailleurs.
     */
    #[Route('/{id}', name: 'app_scheduled_workout_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        ScheduledWorkout $scheduled,
        PlanFlattener $planFlattener,
        WorkoutMetrics $metrics,
        LogComparator $comparator,
        VolumePanel $volumePanel,
        ImportedActivityPresenter $activityPresenter,
    ): Response {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::VIEW, $scheduled);

        $workout = $scheduled->getWorkout();
        $viewer = $this->getUser();

        // Vide quand la séance ne porte aucun réalisé : la colonne « Réalisé » et
        // son onglet n'apparaissent pas, plutôt que d'apparaître vides.
        $comparison = $comparator->compare($scheduled);

        $activities = $this->importedActivityContext($scheduled, $activityPresenter);
        $summaryContext = $this->summaryContext($scheduled, \count($activities['importedActivities']));

        // Séance libre, ou séance de bibliothèque supprimée depuis : il n'y a pas
        // de prescrit à rendre, la page se réduit à sa date, son statut, son titre
        // (snapshot) et son réalisé. Le contexte de lecture passe donc à null.
        return $this->render('scheduled_workout/show.html.twig', [
            'scheduled' => $scheduled,
            'flat' => null === $workout ? null : $planFlattener->flattenWorkout($workout),
            'summary' => null === $workout ? null : $metrics->summary($workout),
            'blockStats' => null === $workout ? [] : $metrics->blockBreakdown($workout),
            'comparison' => $comparison,
            'logSummary' => $summaryContext['logSummary'],
            'defaultTab' => $this->defaultTab($scheduled, $comparison),
            'summaryContext' => $summaryContext,
            'statuses' => ScheduledStatus::cases(),
            // La consigne de la case du plan (#95), qui n'était lisible que dans
            // l'éditeur de plan : l'athlète ne l'aurait jamais vue.
            'planInstruction' => $planFlattener->planInstruction($scheduled),
        ] + $activities + [
            // Le volume PRÉVU, dans le HTML initial comme le reste de la page :
            // une vue de consultation ne charge rien après coup (cache offline).
            // Sans prescrit, il n'y a pas de volume prévu à annoncer.
        ] + (null === $workout ? [] : $volumePanel->for($workout, $viewer instanceof User ? $viewer : null)));
    }

    /**
     * Le réalisé cardio importé (Intervals.icu) : les activités rattachées, lues
     * par le propriétaire comme par son coach (VIEW), et les activités du même
     * jour encore libres, proposées au rattachement au **seul** propriétaire —
     * rattacher, c'est consigner (LOG). Au coach, la liste n'est même pas chargée.
     *
     * @return array{importedActivities: list<array<string, mixed>>, activityComparison: list<array<string, mixed>>, attachableActivities: list<\App\Entity\ImportedActivity>}
     */
    private function importedActivityContext(ScheduledWorkout $scheduled, ImportedActivityPresenter $presenter): array
    {
        $repository = $this->importedActivities;
        $attached = $repository->findForScheduledWorkout($scheduled);
        $owner = $scheduled->getOwner();

        return [
            'importedActivities' => array_map($presenter->present(...), $attached),
            'activityComparison' => $presenter->comparison($scheduled->getWorkout(), $attached),
            'attachableActivities' => null !== $owner && $this->isGranted(ScheduledWorkoutVoter::LOG, $scheduled)
                ? $repository->findUnattachedForOwnerOn($owner, $scheduled->getScheduledDate())
                : [],
        ];
    }

    /**
     * Ce que lit le bilan en tête de /schedule/{id} (#91) : statut, commentaire,
     * ressenti, réalisé. Partagé par la page et par le flux Turbo renvoyé à
     * l'enregistrement, pour que le bilan re-rendu dise exactement la même chose.
     *
     * @param int|null $importedCount activités rattachées, si l'appelant les a déjà
     *
     * @return array{scheduled: ScheduledWorkout, rating: SessionRating|null, logSummary: array<string, mixed>|null, importedCount: int, strength: bool}
     */
    private function summaryContext(ScheduledWorkout $scheduled, ?int $importedCount = null): array
    {
        return [
            'scheduled' => $scheduled,
            'rating' => $this->ratingOf($scheduled),
            'logSummary' => $this->logMetrics->summary($scheduled),
            'importedCount' => $importedCount ?? \count($this->importedActivities->findForScheduledWorkout($scheduled)),
            'strength' => $this->trackable->prescribesStrength($scheduled->getWorkout()),
        ];
    }

    /**
     * Quel panneau s'ouvre à l'arrivée. Même URL, même page : c'est le STATUT qui
     * décide de la lecture. Une séance faite s'ouvre sur ce qui a été fait, une
     * séance à venir sur ce qu'il y a à faire — et une séance manquée aussi, il
     * n'y a rien à y lire du côté du réalisé.
     *
     * @param list<array<string, mixed>> $comparison sortie de LogComparator::compare
     */
    private function defaultTab(ScheduledWorkout $scheduled, array $comparison): string
    {
        if ([] !== $comparison && ScheduledStatus::DONE === $scheduled->getStatus()) {
            return 'realise';
        }

        return 'programme';
    }

    /**
     * Déplace une séance planifiée sur une autre date (référence vivante : seule
     * la date change, la séance reste la même).
     */
    #[Route('/{id}/move', name: 'app_scheduled_workout_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $raw = $request->getPayload()->getString('scheduledDate');
        $newDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: null;

        if (null !== $newDate && $this->isCsrfTokenValid('move'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $scheduled->setScheduledDate($newDate);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance déplacée.');

            return $this->redirectAfterMutation($request, $scheduled, $newDate);
        }

        return $this->redirectAfterMutation($request, $scheduled, $scheduled->getScheduledDate());
    }

    /**
     * Boucle « prévu vs réalisé » (Phase 7) : marque une séance planifiée comme
     * faite / manquée / à nouveau prévue, avec une note d'écart léger optionnelle.
     * C'est **programmer**, pas consigner (d'où EDIT, ouvert au coach) : le détail
     * série par série vit à côté, en LoggedExercise / LoggedSet, et ne s'écrit
     * qu'avec l'attribut LOG (cf. deleteLog et ScheduledWorkoutVoter).
     */
    #[Route('/{id}/status', name: 'app_scheduled_workout_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('status'.$scheduled->getId(), $payload->getString('_token'))) {
            $status = ScheduledStatus::tryFrom($payload->getString('status'));

            if (null !== $status) {
                $scheduled->setStatus($status);

                $notes = trim($payload->getString('completionNotes'));
                $scheduled->setCompletionNotes('' === $notes ? null : $notes);
                $this->applyRating($payload, $scheduled);
                $planned = $this->bringDoneToToday($scheduled);

                $this->entityManager->flush();

                // Ramenée à aujourd'hui : la pastille change de jour, et la date
                // de la page change aussi. Le flux remplace le fragment puis
                // demande un refresh par morphing (cf. les gabarits de flux).
                $refresh = null !== $planned;
                if (null !== $planned) {
                    $this->addFlash('success', $this->broughtToTodayMessage($planned));
                }

                // Réponse asynchrone : on re-rend le seul fragment concerné, la
                // page n'est pas rechargée. Repli sans JS = redirection classique.
                // Le fragment dépend de la page d'origine : un stream dont la
                // cible est absente du DOM ne fait rien, et `#cal-event-{id}`
                // n'existe pas sur la page de la séance datée.
                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    return 'schedule' === $payload->getString('return')
                        ? $this->streamScheduleStatus($request, $scheduled, $refresh)
                        : $this->streamCalEvent($request, $scheduled, $planFlattener, $refresh);
                }

                if (!$refresh) {
                    $this->addFlash('success', 'Statut mis à jour.');
                }
            } else {
                $this->addFlash('error', 'Statut invalide.');
            }
        }

        return $this->redirectAfterMutation($request, $scheduled, $scheduled->getScheduledDate());
    }

    /**
     * Écrit le ressenti posté, axe par axe, **si le formulaire le porte** et si
     * le lecteur a le droit de consigner. Un formulaire sans sélecteur (celui du
     * coach) ne touche donc à rien. Vide = « non renseigné », la seule façon de
     * retirer une note, qui n'existe que sur le web ; une valeur hors échelle
     * est ignorée plutôt que de retirer la note en silence.
     *
     * @param InputBag<bool|float|int|string> $payload
     */
    private function applyRating(InputBag $payload, ScheduledWorkout $scheduled): void
    {
        if (!$this->isGranted(ScheduledWorkoutVoter::LOG, $scheduled)) {
            return;
        }

        if ($payload->has('feeling')) {
            $raw = $payload->getString('feeling');
            $value = SessionRating::boundFeeling('' === $raw ? null : (int) $raw);
            if ('' === $raw || null !== $value) {
                $scheduled->setFeeling($value);
            }
        }

        if ($payload->has('sessionRpe')) {
            $raw = $payload->getString('sessionRpe');
            $value = SessionRating::boundRpe('' === $raw ? null : (int) $raw);
            if ('' === $raw || null !== $value) {
                $scheduled->setSessionRpe($value);
            }
        }
    }

    /** Le ressenti effectif (Kadens, sinon la montre) d'une séance faite, null sinon. */
    private function ratingOf(ScheduledWorkout $scheduled): ?SessionRating
    {
        $id = $scheduled->getId();

        return null === $id ? null : ($this->scheduledWorkouts->ratingsFor([$id])[$id] ?? null);
    }

    /**
     * Où retomber après une mutation. Par défaut le calendrier, dans la vue
     * mémorisée. Mais ces endpoints servent aussi la page de la séance datée :
     * elle poste `return=schedule` pour qu'on y revienne au lieu d'éjecter
     * l'utilisateur vers le planning à chaque changement de statut.
     */
    private function redirectAfterMutation(Request $request, ScheduledWorkout $scheduled, \DateTimeImmutable $date): Response
    {
        if ('schedule' === $request->getPayload()->getString('return')) {
            return $this->redirectToRoute('app_scheduled_workout_show', ['id' => $scheduled->getId()]);
        }

        return $this->redirectToCalendarOf($scheduled->getOwner(), $date);
    }

    /**
     * Statut express, depuis le petit menu de la pastille au calendrier (#89) :
     * le statut posté est **nommé** (Prévue, Faite, Manquée), plus déduit du
     * précédent. L'ancien cycle prévue → faite → manquée changeait le statut à
     * chaque clic sans dire lequel, et un clic de trop passait inaperçu.
     *
     * Ne touche ni la note d'écart ni le ressenti (contrairement au formulaire
     * complet de la modale) : c'est un geste express, pas une saisie. Repli sans
     * JS : le menu est un popover natif et ses choix de vrais boutons de
     * formulaire, ils fonctionnent sans Stimulus.
     */
    #[Route('/{id}/quick-status', name: 'app_scheduled_workout_quick_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function quickStatus(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $payload = $request->getPayload();
        $status = ScheduledStatus::tryFrom($payload->getString('status'));

        if (null !== $status && $this->isCsrfTokenValid('quick-status'.$scheduled->getId(), $payload->getString('_token'))) {
            $scheduled->setStatus($status);
            $planned = $this->bringDoneToToday($scheduled);
            $this->entityManager->flush();

            // Ramenée à aujourd'hui : la pastille quitte son jour, le flux
            // demande un refresh par morphing (cf. updateStatus).
            if (null !== $planned) {
                $this->addFlash('success', $this->broughtToTodayMessage($planned));
            }

            // Geste express : on re-rend la pastille en place (pas de rechargement,
            // la vue mois/semaine est préservée). Repli sans JS = redirection.
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                return $this->streamCalEvent($request, $scheduled, $planFlattener, null !== $planned);
            }
        }

        return $this->redirectToCalendarOf($scheduled->getOwner(), $scheduled->getScheduledDate());
    }

    /**
     * Re-rend en Turbo Stream les trois zones de `/schedule/{id}` qui portent le
     * statut : la pastille du hero, le bilan et le formulaire du bas. Pendant de
     * streamCalEvent() pour l'autre page appelante.
     */
    private function streamScheduleStatus(Request $request, ScheduledWorkout $scheduled, bool $refresh = false): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('scheduled_workout/stream/status.stream.html.twig', [
            'summaryContext' => $this->summaryContext($scheduled),
            'statuses' => ScheduledStatus::cases(),
            'refresh' => $refresh,
        ]);
    }

    /**
     * Re-rend la pastille d'une séance datée en Turbo Stream (action="replace"
     * sur `#cal-event-{id}`), à l'identique de son rendu d'origine. `detailed`
     * (vue semaine) est reporté par le formulaire ; `overdue` est recalculé.
     */
    private function streamCalEvent(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener, bool $refresh = false): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        $today = new \DateTimeImmutable('today');
        $overdue = ScheduledStatus::PLANNED === $scheduled->getStatus()
            && $scheduled->getScheduledDate() < $today;

        $workout = $scheduled->getWorkout();

        return $this->render('calendar/stream/cal_event.stream.html.twig', [
            'scheduled' => $scheduled,
            // null pour une séance sans source : la pastille retombe sur son titre.
            'fw' => null === $workout ? null : $planFlattener->flattenWorkout($workout),
            'instruction' => $planFlattener->planInstruction($scheduled),
            'statuses' => ScheduledStatus::cases(),
            'detailed' => (bool) $request->getPayload()->getInt('detailed'),
            'rating' => $this->ratingOf($scheduled),
            'overdue' => $overdue,
            // Ancre de contexte de la vue appelante, reconduite telle quelle : la
            // pastille re-rendue doit garder des liens qui savent d'où l'on vient.
            // Non validée ici — c'est `BackTarget::parse()` qui tranche, à la
            // lecture, et un jeton illisible s'y lit comme une absence.
            'backFrom' => $request->getPayload()->getString('from') ?: null,
            // Séance ramenée à aujourd'hui : la page entière se morphe ensuite.
            'refresh' => $refresh,
        ]);
    }

    /**
     * Efface d'un coup un plan instancié : supprime TOUTES les séances datées
     * qui en proviennent (y compris DONE/MISSED — c'est une action explicite et
     * globale, distincte du retrait d'une case qui préserve le réalisé). Le
     * PlanTemplate n'est pas touché, seule son instanciation calendrier disparaît.
     * Permet notamment de vider un plan pour le ré-instancier sur une autre date.
     */
    #[Route('/plan/clear', name: 'app_scheduled_workout_clear_plan', methods: ['POST'])]
    public function clearPlan(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $repository,
    ): Response {
        $payload = $request->getPayload();
        $redirect = $this->monthFromPayload($payload);

        if (!$this->isCsrfTokenValid('clear_plan', $payload->getString('_token'))) {
            return $redirect;
        }

        $template = $planTemplateRepository->find($payload->getInt('planId'));
        if (null === $template) {
            $this->addFlash('error', 'Plan introuvable.');

            return $redirect;
        }

        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $scheduled = $repository->findBySourcePlanTemplateForOwner($template, $user);

        foreach ($scheduled as $one) {
            $this->entityManager->remove($one);
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Plan « %s » retiré du planning : %d séance(s) supprimée(s).',
            $template->getTitle(),
            \count($scheduled),
        ));

        return $redirect;
    }

    /**
     * Efface le RÉALISÉ d'une séance datée, et lui seul : la séance reste au
     * planning, avec sa date et son statut. C'est la seule écriture du réalisé
     * côté web — le mobile est la seule source de sa saisie, le web l'affiche et
     * le supprime, il ne l'édite pas.
     *
     * L'attribut est **LOG**, jamais EDIT. EDIT suffirait syntaxiquement et
     * donnerait la main au coach : il programme, il ne consigne pas, et il
     * n'efface donc pas ce que son athlète a déclaré avoir soulevé (KL-06).
     */
    #[Route('/{id}/log/delete', name: 'app_scheduled_workout_log_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteLog(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::LOG, $scheduled);

        if ($this->isCsrfTokenValid('log_delete'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            // orphanRemoval sur la collection : retirer l'élément suffit à
            // supprimer la ligne et ses LoggedSet en cascade.
            foreach ($scheduled->getLoggedExercises()->toArray() as $logged) {
                $scheduled->removeLoggedExercise($logged);
            }

            // Les bornes d'exécution décrivaient ce réalisé : sans lui, elles ne
            // mesurent plus rien. Le statut et la note d'écart, eux, relèvent de la
            // programmation (EDIT) et ne bougent pas — effacer le détail des séries
            // n'annule pas le fait que la séance a été faite.
            $scheduled->setStartedAt(null);
            $scheduled->setEndedAt(null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Détail saisi supprimé. La séance reste au planning.');
        }

        return $this->redirectToRoute('app_scheduled_workout_show', ['id' => $scheduled->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_scheduled_workout_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::DELETE, $scheduled);

        $date = $scheduled->getScheduledDate();
        $owner = $scheduled->getOwner();

        if ($this->isCsrfTokenValid('delete'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($scheduled);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance retirée de l\'agenda.');
        }

        return $this->redirectToCalendarOf($owner, $date);
    }

    /**
     * Le calendrier où retomber après un geste sur une séance datée : celui de
     * son **propriétaire**. Pour soi, c'est son calendrier dans la vue mémorisée ;
     * pour le coach (seul autre lecteur que `ScheduledWorkoutVoter` laisse
     * écrire), c'est l'agenda de l'athlète sur la semaine de la séance (#77).
     * Sans cette branche, déplacer ou retirer une séance de l'athlète envoyait
     * le coach sur SON calendrier : un lien ne change pas de sujet en silence.
     *
     * Comparaison par `id` : `owner` peut être un proxy Doctrine.
     */
    private function redirectToCalendarOf(?User $owner, \DateTimeImmutable $date): Response
    {
        $viewer = $this->getUser();

        if ($owner instanceof User && $viewer instanceof User && $owner->getId() !== $viewer->getId()) {
            return $this->redirectToRoute('app_coach_athlete_calendar', [
                'id' => $owner->getId(),
                'date' => $date->format('Y-m-d'),
            ]);
        }

        return $this->redirectToMonth($date);
    }

    /**
     * Redirige vers le calendrier positionné sur `$date`, dans la vue préférée de
     * l'utilisateur (cookie `kd_calview` posé par CalendarController). Ainsi une
     * mutation faite en vue semaine ré-atterrit en vue semaine (« résistance au
     * refresh »), sans se voir renvoyée en vue mois.
     */
    private function redirectToMonth(\DateTimeImmutable $date): Response
    {
        if ('week' === $this->preferredCalendarView()) {
            return $this->redirectToRoute('app_calendar_week', ['date' => $date->format('Y-m-d')]);
        }

        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
        ]);
    }

    /**
     * Comme `redirectToMonth`, mais pour montrer UN jour : la vue semaine ouvre sa
     * semaine, la vue mois ouvre son mois et le fait défiler jusqu'à lui
     * (`focus`, lu par le contrôleur Stimulus `caladd`). Pas d'ancre `#` : Turbo
     * suit la redirection en fetch et la perdrait.
     */
    private function redirectToDay(\DateTimeImmutable $date): Response
    {
        $focus = $date->format('Y-m-d');

        if ('week' === $this->preferredCalendarView()) {
            return $this->redirectToRoute('app_calendar_week', ['date' => $focus, 'focus' => $focus]);
        }

        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'focus' => $focus,
        ]);
    }

    private function redirectToCurrentMonth(): Response
    {
        // app_calendar_index respecte lui-même le cookie de vue.
        return $this->redirectToRoute('app_calendar_index');
    }

    /**
     * Redirige vers le mois de calendrier porté par le formulaire (champs cachés
     * year/month), avec repli sur le mois courant si absent ou invalide. Respecte
     * la vue préférée (semaine → semaine contenant le 1er du mois visé).
     *
     * @param \Symfony\Component\HttpFoundation\InputBag<bool|float|int|string> $payload
     */
    private function monthFromPayload(\Symfony\Component\HttpFoundation\InputBag $payload): Response
    {
        $year = $payload->getInt('year');
        $month = $payload->getInt('month');

        if ($year >= 1 && $month >= 1 && $month <= 12) {
            return $this->redirectToMonth(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)));
        }

        return $this->redirectToCurrentMonth();
    }

    /** Vue calendrier mémorisée côté cookie ('week' | 'month', défaut 'month'). */
    private function preferredCalendarView(): string
    {
        return 'week' === $this->requestStack->getCurrentRequest()?->cookies->get('kd_calview')
            ? 'week' : 'month';
    }
}
