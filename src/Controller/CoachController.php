<?php

namespace App\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use App\Enum\MuscleGroup;
use App\Enum\ScheduledStatus;
use App\Enum\StatsRange;
use App\Enum\StatsTab;
use App\Http\BackTarget;
use App\Http\NoLongerCoachException;
use App\Repository\CoachingRepository;
use App\Repository\GoalRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\ExerciseVoter;
use App\Service\CalendarWeek;
use App\Service\CoachingResolver;
use App\Service\ExerciseBodyMap;
use App\Service\ExerciseProgress;
use App\Service\ExerciseTrajectory;
use App\Service\HeartRateZones;
use App\Service\PlanScheduler;
use App\Service\ProfileStats;
use App\Service\RecentSessions;
use App\Service\SharedPlanCopier;
use App\Service\SharedWorkoutCopier;
use App\Service\SlugGenerator;
use App\Service\StatsPeriod;
use App\Service\TrainingHistory;
use App\Service\TrainingLog;
use App\Service\TrainingStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace de travail du coach (ROLE_COACH, cf. access_control).
 *
 * Il n'y a **pas de tableau de bord ici** : la liste des athlètes et la gestion
 * des relations vivent sur `/coaching`, page unique pour les deux sens de la
 * relation. `/coach` ne porte que la fiche de travail d'un athlète donné.
 *
 * Principe directeur : **le contenu créé ici est possédé par l'athlète**
 * (`setOwner($athlete)`). Ces actions sont volontairement minces — elles créent
 * la coquille puis renvoient vers les éditeurs habituels (compositeur de séance,
 * éditeur de plan), qui fonctionnent tels quels pour le coach une fois les voters
 * étendus à la relation acceptée. On ne duplique aucun éditeur.
 *
 * Conséquence : tout apparaît d'office chez l'athlète (bibliothèque, calendrier),
 * et `PlanScheduler::resync()` reste cohérent puisque son repli sur
 * `$template->getOwner()` désigne bien l'athlète.
 */
#[Route('/coach')]
final class CoachController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachingResolver $coachingResolver,
        private readonly CoachingRepository $coachingRepository,
    ) {
    }

    /**
     * Vue d'un athlète suivi : sa bibliothèque, ses plans, ses prochaines séances
     * datées, et **ce qu'il a réellement fait** (KL-45). C'est le point de départ
     * de toutes les actions « pour lui ».
     *
     * Tout se scope sur `$athlete`, jamais sur `$this->getUser()` — c'est la règle
     * de toute vue ouverte au coach, et c'est ici qu'elle est la plus visible : la
     * page ne parle que de quelqu'un d'autre.
     *
     * Le réalisé est en **lecture seule**, sans rien à ajouter pour ça : la page
     * de séance datée où mène chaque ligne n'ouvre son bloc de suppression qu'à
     * l'attribut `LOG`, réservé au propriétaire (KL-06). Le coach programme, il ne
     * consigne pas.
     */
    #[Route('/athlete/{id}', name: 'app_coach_athlete', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function athlete(
        #[MapEntity(id: 'id')] User $athlete,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        GoalRepository $goalRepository,
        ProfileStats $profileStats,
        HeartRateZones $heartRateZones,
        TrainingLog $trainingLog,
        RecentSessions $recentSessions,
        PlanScheduler $planScheduler,
        Request $request,
    ): Response {
        $this->denyUnlessCoachOf($athlete);

        /** @var User $coach */
        $coach = $this->getUser();
        $today = new \DateTimeImmutable('today');

        // L'agenda garde ce qui est encore à venir, « Ses dernières séances » ce
        // qui est échu : une séance d'aujourd'hui déjà cochée passe de l'un à
        // l'autre, jamais dans les deux (#76).
        $upcoming = array_values(array_filter(
            $scheduledWorkoutRepository->findByOwnerBetween($athlete, $today, $today->modify('+8 weeks')),
            static fn (ScheduledWorkout $scheduled): bool => !RecentSessions::isPast($scheduled, $today),
        ));
        $plans = $planTemplateRepository->findBy(['owner' => $athlete], ['title' => 'ASC']);
        $workouts = $workoutRepository->findLibraryForOwner($athlete);
        $coachWorkouts = $workoutRepository->findLibraryForOwner($coach);

        return $this->render('coach/athlete.html.twig', [
            'athlete' => $athlete,
            // La relation porte les notes privées du coach (#65).
            'coaching' => $this->acceptedRelation($coach, $athlete),
            'workouts' => $workouts,
            // Le seul élément de la page qui parle du coach : ce qu'il peut donner.
            'coachWorkouts' => $coachWorkouts,
            // Prévenir le doublon avant de redonner une séance (#82).
            'existingCopies' => $this->existingCopies($workouts, $coachWorkouts, $athlete),
            'plans' => $plans,
            // Aperçu des dates avant de placer un plan (#68), et présélection
            // depuis « Placer dans son agenda » sur la page du plan.
            'planPlacement' => $planScheduler->placementPreviews($plans, $athlete),
            'preselectPlanId' => $request->query->getInt('plan'),
            'coachPlans' => $planTemplateRepository->findBy(['owner' => $coach], ['title' => 'ASC']),
            'upcoming' => $upcoming,
            // Faites, manquées ou pas cochées, consignées ou non (C-10, C-11) :
            // le journal ne listait que le réalisé consigné.
            'recent' => $recentSessions->for($athlete, $today),
            'recentWeeks' => RecentSessions::WEEKS,
            'loggedTotal' => $trainingLog->total($athlete),
            'unloggedStrength' => $trainingLog->unloggedStrength($athlete),
            'goals' => $goalRepository->findUpcomingForOwner($athlete, 3),
            // Mêmes services que la page profil : ils prennent n'importe quel User.
            // Le coach a besoin des 1RM, records et zones cardio pour programmer.
            'stats' => $profileStats->for($athlete),
            'hrZones' => $heartRateZones->forUser($athlete),
        ]);
    }

    /**
     * La trajectoire d'un exercice **chez l'athlète** : même page que
     * `/exercise/{id}`, lue au nom de quelqu'un d'autre.
     *
     * Elle existe parce que `ExerciseController::show()` scope volontairement sur
     * `$this->getUser()` — « est-ce que MOI je progresse » sur un exercice de la
     * bibliothèque globale, que tout le monde pratique. Cette question-là garde sa
     * page ; celle du coach en a désormais une autre, au lieu de basculer en
     * silence d'un athlète à soi au clic depuis le compositeur.
     *
     * Deux gardes, pas une : coach accepté de l'athlète (l'historique lu), **et**
     * `ExerciseVoter::VIEW` sur l'exercice (un exercice perso d'un tiers reste
     * fermé — la relation de coaching ne l'ouvre que dans son propre sens).
     */
    #[Route('/athlete/{id}/exercise/{exerciseId}', name: 'app_coach_athlete_exercise', methods: ['GET'], requirements: ['id' => '\d+', 'exerciseId' => '\d+'])]
    public function athleteExercise(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        #[MapEntity(id: 'exerciseId')] Exercise $exercise,
        ExerciseTrajectory $trajectory,
        ExerciseProgress $progress,
        ExerciseBodyMap $bodyMap,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyAccessUnlessGranted(ExerciseVoter::VIEW, $exercise);

        $path = $trajectory->for($athlete, $exercise);
        $viewer = $this->getUser();

        return $this->render('exercise/show.html.twig', [
            'exercise' => $exercise,
            // La silhouette reste celle du coach (réglage d'affichage), les
            // courbes et le plan en cours sont ceux de l'athlète.
            'bodyMap' => $bodyMap->for($exercise, $viewer instanceof User ? $viewer : null),
            'trajectory' => $path,
            'progress' => null === $path ? null : $progress->for($athlete, $exercise, $request->query->getString('range')),
            'subject' => $athlete,
        ]);
    }

    /**
     * Les statistiques de l'athlète sur une fenêtre de temps — `/profile/stats`
     * lu au nom de quelqu'un d'autre, même template et même moteur
     * (`TrainingStats` prend n'importe quel `User`).
     *
     * La fenêtre reste un paramètre d'URL : « les stats de juillet de X » se
     * partage et se met en favori, comme les siennes.
     */
    #[Route('/athlete/{id}/stats', name: 'app_coach_athlete_stats', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function athleteStats(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        TrainingStats $training,
    ): Response {
        $this->denyUnlessCoachOf($athlete);

        $period = StatsPeriod::resolve($request->query->getString('range'));

        return $this->render('profile/stats.html.twig', [
            'stats' => $training->over($athlete, $period),
            'period' => $period,
            'ranges' => StatsRange::pickable(),
            'months' => $training->availableMonths($athlete),
            'tab' => StatsTab::resolve($request->query->getString('tab')),
            'tabs' => StatsTab::cases(),
            'subject' => $athlete,
        ]);
    }

    /**
     * L'historique en calendrier de l'athlète — `/profile/history` lu au nom de
     * quelqu'un d'autre. Sans paramètre de fenêtre, comme l'original : la réponse
     * EST l'étendue complète.
     */
    #[Route('/athlete/{id}/history', name: 'app_coach_athlete_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function athleteHistory(
        #[MapEntity(id: 'id')] User $athlete,
        TrainingHistory $history,
    ): Response {
        $this->denyUnlessCoachOf($athlete);

        return $this->render('profile/history.html.twig', [
            'history' => $history->calendar($athlete),
            'groups' => MuscleGroup::cases(),
            'subject' => $athlete,
        ]);
    }

    /**
     * L'agenda de l'athlète, semaine par semaine — `/calendar/week` lu au nom de
     * quelqu'un d'autre, même template et même service (`CalendarWeek` prend
     * n'importe quel `User`) (#77, C-14).
     *
     * La fiche ne listait ses huit prochaines semaines qu'en texte : le coach ne
     * pouvait ni ouvrir, ni déplacer, ni retirer une séance prévue. Ici chaque
     * pastille porte les gestes du calendrier (statut, déplacer, retirer), tous
     * gardés par `ScheduledWorkoutVoter::EDIT`, qui a déjà sa branche coach. Le
     * réalisé et le ressenti restent à l'athlète (`LOG`) : la modale ne les
     * propose pas.
     *
     * Ce qui ne s'y trouve PAS, volontairement : la barre d'ajout, l'export et
     * l'abonnement ICS. La première pose depuis la bibliothèque du lecteur, les
     * deux autres livrent le calendrier du lecteur. Poser pour l'athlète reste
     * sur sa fiche, qui sait déjà le faire avec SA bibliothèque.
     */
    #[Route('/athlete/{id}/calendar/{date}', name: 'app_coach_athlete_calendar', methods: ['GET'], requirements: ['id' => '\d+', 'date' => '\d{4}-\d{2}-\d{2}'], defaults: ['date' => null])]
    public function athleteCalendar(
        #[MapEntity(id: 'id')] User $athlete,
        CalendarWeek $calendarWeek,
        ?string $date = null,
    ): Response {
        $this->denyUnlessCoachOf($athlete);

        $ref = null === $date ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $ref) {
            $ref = new \DateTimeImmutable('today');
        }

        return $this->render('calendar/week.html.twig', [
            ...$calendarWeek->for($athlete, $ref),
            'statuses' => ScheduledStatus::cases(),
            'subject' => $athlete,
        ]);
    }

    /**
     * Le journal du réalisé de l'athlète — `/profile/log` lu au nom de quelqu'un
     * d'autre, même template et même service (`TrainingLog` prend n'importe quel
     * `User`).
     *
     * C'est la sortie de la fiche, qui ne montre que ses dernières semaines :
     * au-delà, ce n'était plus une fiche, mais il fallait bien un endroit où lire
     * la suite. La fenêtre est un paramètre d'URL, comme pour les statistiques.
     */
    #[Route('/athlete/{id}/log', name: 'app_coach_athlete_log', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function athleteLog(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        TrainingLog $log,
        TrainingStats $training,
    ): Response {
        $this->denyUnlessCoachOf($athlete);

        $period = StatsPeriod::resolve($request->query->getString('range'));

        $groups = $log->monthly($athlete, $period);

        return $this->render('profile/log.html.twig', [
            'groups' => $groups,
            'sessions' => array_sum(array_column($groups, 'sessions')),
            'total' => $log->total($athlete),
            'unloggedStrength' => $log->unloggedStrength($athlete, $period),
            'period' => $period,
            'ranges' => StatsRange::pickable(),
            'months' => $training->availableMonths($athlete),
            'subject' => $athlete,
        ]);
    }

    /**
     * Crée une séance vide **pour** l'athlète et bascule sur le compositeur normal.
     * Le coach y a accès parce que WorkoutVoter accorde EDIT au coach accepté du
     * propriétaire.
     */
    #[Route('/athlete/{id}/workout/new', name: 'app_coach_workout_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newWorkout(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        SlugGenerator $slugGenerator,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_workout_new'.$athlete->getId());

        $title = trim($request->getPayload()->getString('title')) ?: 'Nouvelle séance';

        $workout = (new Workout())
            ->setOwner($athlete)
            ->setTitle($title)
            ->setSlug($slugGenerator->generate($title, Workout::class));

        $this->entityManager->persist($workout);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Séance créée pour %s. Compose-la maintenant.', $athlete->getDisplayName()));

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }

    /**
     * Ajoute à la bibliothèque de l'athlète une copie d'une séance **du coach**
     * (#58). La copie appartient à l'athlète, comme tout ce que le coach crée ici,
     * et l'original reste intact chez le coach : l'adapter à l'athlète ne touche
     * pas sa séance modèle.
     *
     * Seule une séance de la bibliothèque du coach est acceptée (pas une copie
     * locale de plan, pas le contenu d'un tiers). Les exercices perso du coach
     * restent référencés, la relation acceptée les ouvre à l'athlète
     * (cf. `SharedWorkoutCopier`).
     *
     * Bascule ensuite sur le compositeur, avec la fiche athlète comme retour.
     */
    #[Route('/athlete/{id}/workout/copy', name: 'app_coach_workout_copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copyWorkout(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        WorkoutRepository $workoutRepository,
        SharedWorkoutCopier $copier,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_workout_copy'.$athlete->getId());

        /** @var User $coach */
        $coach = $this->getUser();
        $source = $workoutRepository->find($request->getPayload()->getInt('workout'));

        if (!$source instanceof Workout || $source->getOwner()?->getId() !== $coach->getId() || $source->isPlanLocal()) {
            $this->addFlash('error', 'Choisis une séance de ta bibliothèque.');

            return $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);
        }

        $copy = $copier->copyFor($source, $athlete)->workout;
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('« %s » ajoutée à la bibliothèque de %s. Ajuste-la à son niveau si besoin.', $copy->getTitle(), $athlete->getDisplayName()));

        return $this->redirectToRoute('app_workout_edit', [
            'id' => $copy->getId(),
            'from' => BackTarget::token(BackTarget::ATHLETE, (int) $athlete->getId()),
        ]);
    }

    /**
     * Les copies que l'athlète a déjà des séances du coach (#82), par id de séance
     * source : combien, et la plus récemment modifiée, celle qu'on lui propose
     * d'ouvrir plutôt que d'en créer une de plus. Toute copie de bibliothèque
     * compte, qu'elle vienne de « Ajouter à sa bibliothèque » ou d'une séance
     * posée dans son plan (`SharedWorkoutCopier::libraryCopyFor`).
     *
     * Filtré sur les ids des séances du coach, sans remonter `copiedFrom` : lire
     * l'id d'un proxy ne le charge pas, lire son propriétaire si.
     *
     * @param list<Workout> $athleteWorkouts
     * @param list<Workout> $coachWorkouts
     *
     * @return array<int, array{count: int, title: string, modifiedAt: string, url: string}>
     */
    private function existingCopies(array $athleteWorkouts, array $coachWorkouts, User $athlete): array
    {
        $sourceIds = array_flip(array_map(static fn (Workout $workout): int => (int) $workout->getId(), $coachWorkouts));

        /** @var array<int, list<Workout>> $bySource */
        $bySource = [];
        foreach ($athleteWorkouts as $workout) {
            $sourceId = $workout->getCopiedFrom()?->getId();
            if (null !== $sourceId && isset($sourceIds[$sourceId])) {
                $bySource[$sourceId][] = $workout;
            }
        }

        $copies = [];
        foreach ($bySource as $sourceId => $workouts) {
            usort($workouts, static fn (Workout $a, Workout $b): int => self::modifiedAt($b) <=> self::modifiedAt($a));
            $latest = $workouts[0];

            $copies[$sourceId] = [
                'count' => count($workouts),
                'title' => (string) $latest->getTitle(),
                'modifiedAt' => self::modifiedAt($latest)->format('d/m/Y'),
                'url' => $this->generateUrl('app_workout_edit', [
                    'id' => $latest->getId(),
                    'from' => BackTarget::token(BackTarget::ATHLETE, (int) $athlete->getId()),
                ]),
            ];
        }

        return $copies;
    }

    private static function modifiedAt(Workout $workout): \DateTimeImmutable
    {
        return $workout->getUpdatedAt() ?? $workout->getCreatedAt() ?? new \DateTimeImmutable();
    }

    /**
     * Donne à l'athlète une copie d'un plan **du coach** (#58), comme `copyWorkout`
     * pour une séance. Le plan modèle reste chez le coach ; la copie, ses cases et
     * leurs séances racines appartiennent à l'athlète (`SharedPlanCopier`).
     *
     * Pour donner le plan lui-même plutôt qu'une copie, c'est l'éditeur de plan
     * (« Donner ce plan à », `PlanTemplateController::assign`).
     */
    #[Route('/athlete/{id}/plan/copy', name: 'app_coach_plan_copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copyPlan(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        PlanTemplateRepository $planTemplateRepository,
        SharedPlanCopier $copier,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_plan_copy'.$athlete->getId());

        /** @var User $coach */
        $coach = $this->getUser();
        $source = $planTemplateRepository->find($request->getPayload()->getInt('plan'));

        if (!$source instanceof PlanTemplate || $source->getOwner()?->getId() !== $coach->getId()) {
            $this->addFlash('error', 'Choisis un plan de ta bibliothèque.');

            return $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);
        }

        $copy = $copier->copyFor($source, $athlete);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('« %s » ajouté aux plans de %s, avec ses séances.', $copy->getTitle(), $athlete->getDisplayName()));

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $copy->getId()]);
    }

    /**
     * Crée un plan vide pour l'athlète et bascule sur l'éditeur de plan normal.
     */
    #[Route('/athlete/{id}/plan/new', name: 'app_coach_plan_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newPlan(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        SlugGenerator $slugGenerator,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_plan_new'.$athlete->getId());

        $payload = $request->getPayload();
        $title = trim($payload->getString('title')) ?: 'Nouveau plan';
        $weeks = max(1, min(52, $payload->getInt('durationWeeks', 4)));

        $template = (new PlanTemplate())
            ->setOwner($athlete)
            ->setTitle($title)
            ->setDurationWeeks($weeks)
            ->setSlug($slugGenerator->generate($title, PlanTemplate::class));

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Plan créé pour %s. Ajoute maintenant ses séances dans la grille.', $athlete->getDisplayName()));

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
    }

    /**
     * Pose une séance de la bibliothèque de l'athlète sur une date de SON
     * calendrier. Calqué sur `ScheduledWorkoutController::place`, à ceci près que
     * l'owner posé est l'athlète, pas l'utilisateur courant.
     */
    #[Route('/athlete/{id}/schedule', name: 'app_coach_schedule', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function scheduleWorkout(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        WorkoutRepository $workoutRepository,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_schedule'.$athlete->getId());

        $payload = $request->getPayload();
        $redirect = $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('date')) ?: null;
        $workout = $workoutRepository->find($payload->getInt('workoutId'));

        // Même garde-fou que la pose côté athlète : une copie privée de plan
        // (planLocal) n'est jamais posable seule. On exige en plus que la séance
        // appartienne bien à cet athlète : sans ça, un coach poserait sa propre
        // séance (ou celle d'un autre athlète) sur ce calendrier.
        if (null === $date || null === $workout || $workout->isPlanLocal() || $workout->getOwner() !== $athlete) {
            $this->addFlash('error', 'Impossible de planifier cette séance.');

            return $redirect;
        }

        $scheduled = (new ScheduledWorkout())
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setOwner($athlete)
            ->setStatus(ScheduledStatus::PLANNED);

        $this->entityManager->persist($scheduled);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Séance posée au %s sur le calendrier de %s.', $date->format('d/m/Y'), $athlete->getDisplayName()));

        return $redirect;
    }

    /**
     * Place un plan de l'athlète dans son agenda. `PlanScheduler` prend un owner
     * explicite : on lui passe l'athlète, donc la pose et les resync ultérieurs
     * restent les siens.
     */
    #[Route('/athlete/{id}/instantiate', name: 'app_coach_instantiate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function instantiatePlan(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_instantiate'.$athlete->getId());

        $payload = $request->getPayload();
        $redirect = $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);

        $template = $planTemplateRepository->find($payload->getInt('planId'));
        $startDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('startDate')) ?: null;

        if (null === $template || null === $startDate || $template->getOwner() !== $athlete) {
            $this->addFlash('error', 'Impossible de placer ce plan dans l\'agenda.');

            return $redirect;
        }

        $alreadyOnCalendar = $planScheduler->isInstantiated($template, $athlete);
        $created = $planScheduler->instantiate($template, $athlete, $startDate);

        $count = \count($created);
        $plural = $count > 1 ? 's' : '';
        $this->addFlash('success', $alreadyOnCalendar
            ? sprintf(
                'Le plan « %s » était déjà dans l\'agenda de %s : %d nouvelle%s séance%s ajoutée%s, les autres n\'ont pas bougé.',
                $template->getTitle(), $athlete->getDisplayName(), $count, $plural, $plural, $plural,
            )
            : sprintf(
                'Plan « %s » placé dans l\'agenda de %s : %d séance%s.',
                $template->getTitle(), $athlete->getDisplayName(), $count, $plural,
            ));

        return $redirect;
    }

    /**
     * Notes privées du coach sur son athlète (#65), en édition en ligne. Même
     * contrat que les métadonnées (`inline-edit`) : champ `notes`, réponse = la
     * valeur persistée en texte brut.
     *
     * La garde est celle de la fiche, et elle suffit : la relation lue est celle
     * où l'utilisateur courant est le COACH. L'athlète n'a aucun chemin vers ce
     * champ, ni en lecture ni en écriture.
     */
    #[Route('/athlete/{id}/notes', name: 'app_coach_athlete_notes', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function notes(Request $request, #[MapEntity(id: 'id')] User $athlete): Response
    {
        $this->denyUnlessCoachOf($athlete);

        /** @var User $coach */
        $coach = $this->getUser();
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('coach_notes'.$athlete->getId(), $payload->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        if ('notes' !== $payload->getString('field')) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $value = trim($payload->getString('value'));
        $this->acceptedRelation($coach, $athlete)->setCoachNotes('' === $value ? null : $value);
        $this->entityManager->flush();

        return new Response($value);
    }

    /**
     * La relation acceptée coach → athlète. À n'appeler qu'après
     * `denyUnlessCoachOf()`, qui garantit qu'elle existe.
     */
    private function acceptedRelation(User $coach, User $athlete): Coaching
    {
        $coaching = $this->coachingRepository->findAcceptedRelation($coach, $athlete);
        if (null === $coaching) {
            throw $this->createAccessDeniedException('Tu n\'es pas le coach de cet athlète.');
        }

        return $coaching;
    }

    /**
     * Garde-fou commun : l'utilisateur courant doit être coach **accepté** de cet
     * athlète. ROLE_COACH seul ne donne accès à personne — il ouvre la porte de
     * /coach, pas celle d'un athlète donné.
     */
    private function denyUnlessCoachOf(User $athlete): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->coachingResolver->isAcceptedCoachOf($user, $athlete)) {
            return;
        }

        // Ancien lien vers un athlète qu'il ne suit plus (#81) : la page d'erreur
        // le lui dit, au lieu de « ce contenu ne t'appartient pas ».
        if (CoachingStatus::ENDED === $this->coachingRepository->findRelation($user, $athlete)?->getStatus()) {
            throw new NoLongerCoachException();
        }

        throw $this->createAccessDeniedException('Tu n\'es pas le coach de cet athlète.');
    }

    private function denyUnlessCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
