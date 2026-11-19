<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Repository\CoachingRepository;
use App\Repository\GoalRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Service\CoachContributions;
use App\Service\LastSevenDays;
use App\Service\StatsPeriod;
use App\Service\TrainingStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'accueil : « où j'en suis ». La prochaine séance, le prochain
 * objectif, l'observance et la répartition du mois, une demande de coaching en
 * attente ou la réponse à la sienne, ce que le coach a ajouté ces sept derniers
 * jours, et des raccourcis vers les pages de lecture. La fiche de l'athlète
 * (records, mesures, stats depuis le début) vit sur `/profile`.
 *
 * Un compte dont l'agenda est encore vide voit d'abord un démarrage en trois
 * étapes (#70) : créer une séance, l'assembler dans un plan, placer le plan
 * dans l'agenda. Chaque étape se coche sur l'état réel des données, rien n'est
 * mémorisé : le bloc disparaît dès que l'agenda porte une séance.
 *
 * Le mois se lit sur `TrainingStats`, le moteur unique : les chiffres de
 * l'accueil sont ceux de `/profile/stats?range=<mois>`, sur lequel la carte
 * renvoie. Les sept derniers jours (#131) se lisent sur `LastSevenDays`, qui
 * prend les mêmes sources sur une fenêtre glissante : séances par nature, et
 * muscles travaillés sur le réalisé.
 */
final class HomeController extends AbstractController
{
    /** Le prochain objectif en aplat, et celui d'après en pied de carte. */
    private const UPCOMING_GOALS = 2;

    /**
     * `/` n'est pas couvert par access_control : on garde manuellement.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        TrainingStats $training,
        GoalRepository $goalRepository,
        CoachingRepository $coachingRepository,
        ScheduledWorkoutRepository $scheduledRepository,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planRepository,
        CoachContributions $coachContributions,
        LastSevenDays $lastSevenDays,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $today = new \DateTimeImmutable('today');
        $month = $training->over($user, StatsPeriod::month((int) $today->format('Y'), (int) $today->format('n')));

        // Seulement pour le démarrage : un coach qui suit déjà des athlètes ne
        // le voit pas. L'accueil n'a plus de tuile « Mes athlètes », l'entrée
        // « Athlètes » de la barre de navigation y mène déjà.
        $athleteCount = $user->isCoach() ? \count($coachingRepository->findAcceptedAthletes($user)) : null;

        $onboarding = $this->onboarding($user, $athleteCount, $scheduledRepository, $workoutRepository, $planRepository);

        return $this->render('home/index.html.twig', [
            'today' => $today,
            'month' => $month,
            // Un compte qui démarre n'a rien fait : la carte serait vide sous les
            // trois étapes qui disent déjà par où commencer.
            'week' => null === $onboarding ? $lastSevenDays->for($user, $user, $today) : null,
            'goals' => $goalRepository->findUpcomingForOwner($user, self::UPCOMING_GOALS),
            // Découvrabilité : une demande reçue doit se voir dès l'arrivée, pas
            // seulement dans /coaching. Les relations actives, elles, n'appellent
            // aucune action : elles restent dans /coaching.
            'coachingReceived' => $coachingRepository->findPendingReceivedBy($user),
            // Et dans l'autre sens, la réponse à une demande qu'on a envoyée
            // (#79) : sans elle, il fallait revenir sur /coaching pour savoir.
            'coachingAnswered' => $coachingRepository->findUnseenResponsesFor($user),
            // Ce que le coach a ajouté chez soi ces derniers jours (#80) : sans
            // cet encart, la seule trace était un « 7 prévues » au lieu de 6.
            'coachAdded' => $coachContributions->recentFor($user),
            'nextSessions' => $scheduledRepository->findNextPlannedDay($user, $today),
            'onboarding' => $onboarding,
        ]);
    }

    /**
     * Les trois étapes du démarrage, ou `null` quand il n'a plus lieu d'être.
     *
     * Le bloc s'efface dès que l'agenda porte une séance, quelle qu'elle soit :
     * c'est le but des trois étapes, et une séance posée seule (sans plan) ou
     * par un coach dit aussi que le compte a démarré. Le coach qui suit déjà des
     * athlètes ne le voit pas non plus : son travail se fait chez eux, son propre
     * agenda peut rester vide sans que rien ne manque.
     *
     * @return array{workout: bool, plan: bool, filledPlan: ?PlanTemplate}|null
     */
    private function onboarding(
        User $user,
        ?int $athleteCount,
        ScheduledWorkoutRepository $scheduledRepository,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planRepository,
    ): ?array {
        if ($athleteCount > 0 || $scheduledRepository->count(['owner' => $user]) > 0) {
            return null;
        }

        $filledPlan = $planRepository->findLatestFilledForOwner($user);

        return [
            // Bibliothèque seule : les copies locales aux plans n'en font pas partie.
            'workout' => $workoutRepository->count(['owner' => $user, 'planLocal' => false]) > 0,
            'plan' => null !== $filledPlan,
            'filledPlan' => $filledPlan,
        ];
    }
}
