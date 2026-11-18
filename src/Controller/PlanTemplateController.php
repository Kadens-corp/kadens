<?php

namespace App\Controller;

use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\ProgressionField;
use App\Enum\ScheduledStatus;
use App\Http\ProgressionInput;
use App\Repository\CoachingRepository;
use App\Repository\GoalRepository;
use App\Repository\ImportedActivityRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\UserRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Service\CoachedLibrary;
use App\Service\CoachingResolver;
use App\Service\ExerciseNaming;
use App\Service\LineageDivergence;
use App\Service\LineageSeed;
use App\Service\OffProgramRecap;
use App\Service\PlanProgression;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use App\Service\PlanTransfer;
use App\Service\PlanVolumeAggregator;
use App\Service\SessionRating;
use App\Service\ProgressionAggregator;
use App\Service\SharedPlanCopier;
use App\Service\SharedWorkoutCopier;
use App\Service\ShareLink;
use App\Service\SlugGenerator;
use App\Service\UnitFormatter;
use App\Service\WorkoutCloner;
use App\Service\WorkoutMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

#[Route('/plan-template')]
final class PlanTemplateController extends AbstractController
{
    /** Exécutions notées d'une lignée résumées sur une carte de palette. */
    private const int PALETTE_RATINGS = 5;

    /** Titre d'un brouillon créé en un clic, que l'éditeur ouvre aussitôt en édition. */
    public const DRAFT_PLAN_TITLE = 'Nouveau plan';

    /** Bloc de départ d'un nouveau plan : on ajoute ensuite semaine par semaine. */
    public const DRAFT_PLAN_WEEKS = 4;

    /** Plafond de la trame (borne haute de `durationWeeks`). */
    public const MAX_WEEKS = 52;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanFlattener $planFlattener,
        private readonly WorkoutRepository $workoutRepository,
        private readonly WorkoutMetrics $workoutMetrics,
        private readonly PlanVolumeAggregator $volumeAggregator,
        private readonly GoalRepository $goalRepository,
        private readonly ScheduledWorkoutRepository $scheduledWorkoutRepository,
        private readonly CoachingResolver $coachingResolver,
        private readonly CoachedLibrary $coachedLibrary,
        private readonly OffProgramRecap $offProgramRecap,
        private readonly LineageDivergence $lineageDivergence,
        private readonly ExerciseNaming $exerciseNaming,
        private readonly UnitFormatter $unitFormatter,
    ) {
    }

    #[Route('', name: 'app_plan_template_index', methods: ['GET'])]
    public function index(PlanTemplateRepository $planTemplateRepository, CoachedLibrary $coachedLibrary): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Même portée que l'index des séances : soi + ses athlètes suivis, pour
        // qu'un coach retrouve les plans qu'il a bâtis pour eux (ils appartiennent
        // à l'athlète). Facette « Moi » active par défaut.
        $athletes = $coachedLibrary->athletesOf($user);
        $templates = $planTemplateRepository->findForOwnersWithContent([$user, ...$athletes]);

        $items = [];
        $counts = [];
        $ownerCounts = [];
        foreach ($templates as $template) {
            // Activités distinctes de toutes les séances du plan (union), pour les facettes.
            $seen = [];
            foreach ($template->getPlanItems() as $planItem) {
                $workout = $planItem->getWorkout();
                if (null === $workout) {
                    continue;
                }
                foreach ($this->workoutMetrics->distinctActivities($workout) as $activity) {
                    $seen[$activity->value] = $activity;
                }
            }
            $activities = array_values($seen);
            $items[] = ['template' => $template, 'activities' => $activities];
            foreach ($activities as $activity) {
                $counts[$activity->value] = ($counts[$activity->value] ?? 0) + 1;
            }
            $ownerId = $template->getOwner()?->getId();
            if (null !== $ownerId) {
                $ownerCounts[$ownerId] = ($ownerCounts[$ownerId] ?? 0) + 1;
            }
        }

        $facets = [];
        foreach (ActivityType::cases() as $case) {
            if (isset($counts[$case->value])) {
                $facets[] = ['value' => $case->value, 'label' => $case->getLabel(), 'count' => $counts[$case->value]];
            }
        }

        return $this->render('plan_template/index.html.twig', [
            'items' => $items,
            'total' => \count($items),
            'activityFacets' => $facets,
            'ownerFacets' => $coachedLibrary->ownerFacets($user, $athletes, $ownerCounts),
        ]);
    }

    /**
     * Crée un brouillon titré par défaut, sur un bloc de 4 semaines, et bascule
     * directement sur l'éditeur de trame (pas d'écran de formulaire intermédiaire :
     * le titre s'édite en ligne, les semaines s'ajoutent au pied de la trame). Même
     * geste que `CoachController::newPlan`, qui procédait déjà ainsi.
     */
    #[Route('/new', name: 'app_plan_template_new', methods: ['POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        if (!$this->isCsrfTokenValid('plan_new', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $title = trim($request->getPayload()->getString('title')) ?: self::DRAFT_PLAN_TITLE;

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $template = (new PlanTemplate())
            ->setOwner($user)
            ->setTitle($title)
            ->setDurationWeeks(self::DRAFT_PLAN_WEEKS)
            ->setSlug($slugGenerator->generate($title, PlanTemplate::class));

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId(), 'rename' => 1]);
    }

    /**
     * La fiche d'un plan : sa trame mise à plat, puis le bloc de progression —
     * la rampe prévue, et par-dessus elle le réalisé d'une instanciation (KL-49).
     *
     * **Une trame n'a pas de dates**, il faut donc choisir *quelle fois* on
     * regarde : la plus récente par défaut, une autre par `?run=Y-m-d` quand le
     * plan a été repassé. Un plan jamais posé au calendrier n'a pas de réalisé du
     * tout, et le bloc reste celui du prévu seul.
     *
     * Portée : le propriétaire du plan, jamais l'utilisateur courant — un coach
     * ouvre cette page pour lire le plan de son athlète, et c'est le calendrier de
     * l'athlète qui porte le réalisé.
     */
    #[Route('/{id}', name: 'app_plan_template_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Request $request,
        PlanTemplate $template,
        SlugGenerator $slugGenerator,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        ImportedActivityRepository $importedActivityRepository,
        ProgressionAggregator $progression,
    ): Response {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);
        $this->ensureSlug($template, $slugGenerator);

        // Précharge tout le contenu en une requête : la mise à plat ET les agrégats
        // de progression parcourent chaque case (anti-N+1). Même instance managée.
        $loaded = $planTemplateRepository->findWithContent((int) $template->getId()) ?? $template;

        $owner = $template->getOwner();
        $anchors = null !== $owner ? $scheduledWorkoutRepository->findPlanAnchorsForOwner($template, $owner) : [];
        $anchor = $this->pickAnchor($anchors, $request->query->getString('run'));

        $realized = null;
        if (null !== $owner && [] !== $anchors) {
            // Le réalisé de l'endurance ne se logue pas, il s'importe : un agrégat
            // SQL de plus, borné aux séances de l'instanciation regardée.
            $run = $scheduledWorkoutRepository->findPlanRunWithLog($template, $owner, $anchor);
            $imported = $importedActivityRepository->enduranceTotalsForScheduledIds(
                array_map(static fn (ScheduledWorkout $s): int => (int) $s->getId(), $run),
            );
            $realized = $progression->realizedRun($loaded, $run, $imported);
        }

        return $this->render('plan_template/show.html.twig', [
            'flat' => $this->planFlattener->flattenPlanTemplate($loaded),
            'progression' => [
                'volume' => $progression->weeklyVolume($loaded, $realized['weeks'] ?? []),
                'trajectories' => $progression->exerciseTrajectories($loaded, $realized['exercises'] ?? []),
                'adherence' => $realized['adherence'] ?? null,
                'anchors' => $anchors,
                'anchor' => $anchor,
            ],
        ]);
    }

    /**
     * L'instanciation regardée : celle demandée si elle existe, la plus récente
     * sinon. `$anchors` est déjà trié du plus récent au plus ancien, l'ancre nulle
     * (instanciation antérieure au champ) en queue.
     *
     * Une valeur inconnue dans l'URL retombe silencieusement sur le défaut plutôt
     * que de lever : c'est un paramètre d'affichage, pas une ressource.
     *
     * @param list<\DateTimeImmutable|null> $anchors
     */
    private function pickAnchor(array $anchors, string $requested): ?\DateTimeImmutable
    {
        if ([] === $anchors) {
            return null;
        }

        foreach ($anchors as $anchor) {
            if (null !== $anchor && $anchor->format('Y-m-d') === $requested) {
                return $anchor;
            }
        }

        return $anchors[0];
    }

    /**
     * L'éditeur de trame. Titre et description s'éditent en ligne (endpoint
     * `meta`), le nombre de semaines par les boutons du pied de trame : plus aucun
     * formulaire de métadonnées ici, donc plus de POST sur cette route.
     */
    #[Route('/{id}/edit', name: 'app_plan_template_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(PlanTemplate $template, SlugGenerator $slugGenerator, CoachingRepository $coachingRepository): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $this->ensureSlug($template, $slugGenerator);

        /** @var User $user */
        $user = $this->getUser();

        return $this->render('plan_template/edit.html.twig',
            // Notes privées du coach sur l'athlète propriétaire (#65), null pour soi.
            ['coachRelation' => $coachingRepository->findAcceptedRelation($user, $template->getOwner())]
            + $this->gridContext($template) + $this->paletteContext($template) + $this->goalsContext($template)
            + $this->assignContext($template)
        );
    }

    /**
     * « Ce plan est pour mon athlète » (#58) : le propriétaire le donne à un de ses
     * athlètes, depuis son propre éditeur, sans passer par la fiche coach. Le plan
     * change de propriétaire (`PlanTransfer`), le coach en reste co-éditeur.
     *
     * Trois gardes :
     * - **le propriétaire seul**, pas l'attribut EDIT : un coach co-éditeur ne
     *   réattribue pas le plan de son athlète à quelqu'un d'autre, ni à lui-même ;
     * - **un athlète en relation acceptée** avec lui ;
     * - **un plan jamais posé au calendrier.** Ses séances datées appartiennent au
     *   calendrier du coach et pointent les copies locales qui partiraient avec le
     *   plan. On ne démêle pas ça en silence : on refuse, et on dit quoi faire.
     */
    #[Route('/{id}/assign', name: 'app_plan_template_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(Request $request, PlanTemplate $template, UserRepository $userRepository, PlanTransfer $transfer): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($template->getOwner()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Seul le propriétaire du plan peut le transférer.');
        }

        $redirect = $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        if (!$this->isCsrfTokenValid('plan_assign'.$template->getId(), $request->getPayload()->getString('_token'))) {
            return $redirect;
        }

        $athlete = $userRepository->find($request->getPayload()->getInt('athlete'));
        if (null === $athlete || !$this->coachingResolver->isAcceptedCoachOf($user, $athlete)) {
            $this->addFlash('error', 'Choisis un de tes athlètes.');

            return $redirect;
        }

        if (!$template->getScheduledWorkouts()->isEmpty()) {
            $this->addFlash('error', sprintf('Ce plan est déjà sur ton calendrier : il ne peut plus changer de propriétaire. Donne-en une copie à %s depuis sa fiche.', $athlete->getDisplayName()));

            return $redirect;
        }

        $transfer->transfer($template, $athlete);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('« %s » est maintenant le plan de %s. Tu restes co-éditeur tant que tu l\'entraînes.', $template->getTitle(), $athlete->getDisplayName()));

        return $redirect;
    }

    /**
     * Ouvre ou arrête le partage par lien du plan (#61). Geste de PROPRIÉTAIRE :
     * l'attribut EDIT ne suffit pas, un coach accepté le porte aussi, et il ne
     * publie pas le plan de son athlète (C-15). Le lien du plan ouvre aussi ses
     * cases, qui n'en ont pas à elles.
     */
    #[Route('/{id}/share', name: 'app_plan_template_share', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function share(Request $request, PlanTemplate $template, ShareLink $shareLink): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        /** @var User $user */
        $user = $this->getUser();
        if ($template->getOwner()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Seul le propriétaire partage son plan.');
        }

        if (!$this->isCsrfTokenValid('plan_share'.$template->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ('close' === $request->getPayload()->getString('action')) {
            $shareLink->close($template);
            $this->addFlash('success', 'Partage arrêté : le lien ne répond plus.');
        } else {
            $shareLink->open($template);
            $this->addFlash('success', 'Partage par lien activé. Copie le lien pour l\'envoyer.');
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_plan_template_show', ['id' => $template->getId()]);
    }

    /**
     * Copie dans SA bibliothèque un plan reçu par son lien public (#58). Même
     * principe que `WorkoutController::copyShared` : le jeton de partage vaut droit
     * de lecture, donc de copie, et un partage arrêté ne se copie plus. Les séances
     * du plan arrivent avec lui dans sa bibliothèque (`SharedPlanCopier`). Sur son
     * propre plan, rien à copier.
     */
    #[Route('/shared/{token}/copy', name: 'app_plan_template_copy_shared', methods: ['POST'], requirements: ['token' => ShareLink::TOKEN])]
    public function copyShared(
        Request $request,
        #[MapEntity(mapping: ['token' => 'shareToken'])] PlanTemplate $template,
        SharedPlanCopier $copier,
    ): Response {
        $token = (string) $template->getShareToken();
        if (!$this->isCsrfTokenValid('copy_shared_plan'.$token, $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_public_share_plan', ['token' => $token]);
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($template->getOwner()?->getId() === $user->getId()) {
            return $this->redirectToRoute('app_plan_template_show', ['id' => $template->getId()]);
        }

        $copy = $copier->copyFor($template, $user);
        $this->entityManager->flush();

        $this->addFlash('success', 'Plan copié dans ta bibliothèque, avec ses séances.');

        return $this->redirectToRoute('app_plan_template_show', ['id' => $copy->getId()]);
    }

    /**
     * L'entrée du visiteur anonyme, comme `WorkoutController::copySharedEntry` :
     * le pare-feu le fait passer par le login, puis on le repose sur la page
     * publique du plan, où la copie est désormais possible.
     */
    #[Route('/shared/{token}/copy', name: 'app_plan_template_copy_shared_entry', methods: ['GET'], requirements: ['token' => ShareLink::TOKEN])]
    public function copySharedEntry(#[MapEntity(mapping: ['token' => 'shareToken'])] PlanTemplate $template): Response
    {
        return $this->redirectToRoute('app_public_share_plan', ['token' => $template->getShareToken()]);
    }

    /**
     * Édition en ligne d'un champ du plan (titre/description/notes) depuis l'en-tête
     * cliquable de l'éditeur (contrôleur `inline-edit`). Renvoie la valeur
     * persistée (texte brut) que le JS réaffiche. C'est le SEUL chemin d'édition
     * des métadonnées : il n'y a plus de formulaire de repli.
     *
     * `notes` est le seul champ à porter une garde plus stricte que l'attribut EDIT
     * de la route : c'est un bloc-notes privé, réservé au propriétaire.
     */
    #[Route('/{id}/meta', name: 'app_plan_template_meta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateMeta(Request $request, PlanTemplate $template, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('plan_meta'.$template->getId(), $payload->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $value = trim($payload->getString('value'));
        switch ($payload->getString('field')) {
            case 'title':
                if ('' === $value) {
                    return new Response('Le titre ne peut pas être vide.', Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $template->setTitle($value);
                // Le slug suit le titre : il ne nomme plus que le fichier d'export,
                // le lien public est un jeton depuis #61. Un slug qui dérive déjà
                // du titre reste, sans quoi il glisserait vers `-2` à chaque envoi.
                if (!$slugGenerator->derivesFrom($template->getSlug(), $value)) {
                    $template->setSlug($slugGenerator->generate($value, PlanTemplate::class));
                }
                break;
            case 'description':
                $template->setDescription('' === $value ? null : $value);
                break;
            case 'notes':
                // Bloc-notes privé : EDIT ne suffit pas, un coach accepté le porte
                // aussi. Seul le propriétaire écrit ici, comme lui seul le lit.
                if ($template->getOwner() !== $this->getUser()) {
                    return new Response('', Response::HTTP_FORBIDDEN);
                }
                $template->setNotes('' === $value ? null : $value);
                break;
            default:
                return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new Response($value);
    }

    /**
     * Rattache/détache un objectif à ce plan (relation N:N, réversible).
     *
     * Scoping : les objectifs proposés et acceptés sont ceux du PROPRIÉTAIRE du
     * plan, pas de l'utilisateur courant — un coach qui travaille sur le contenu de
     * son athlète doit rattacher les objectifs de l'athlète. Même raisonnement que
     * le repli `PlanScheduler::resync()`.
     */
    #[Route('/{id}/goals', name: 'app_plan_template_goals', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateGoals(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();
        $notice = null;

        if ($this->isCsrfTokenValid('plan_goals'.$template->getId(), $payload->getString('_token'))) {
            $goal = $this->goalRepository->find($payload->getInt('goalId'));

            if (null !== $goal && $goal->getOwner() === $template->getOwner()) {
                if ('detach' === $payload->getString('action')) {
                    $template->removeGoal($goal);
                    $notice = sprintf('Objectif « %s » détaché.', $goal->getTitle());
                } else {
                    $template->addGoal($goal);
                    $notice = sprintf('Plan associé à « %s ».', $goal->getTitle());
                }
                $this->entityManager->flush();
            }
        }

        return $this->goalsResponse($request, $template, $notice);
    }

    /**
     * Réponse d'une mutation de rattachement : stream ciblé sur #plan-goals (repli
     * sans JS : redirection vers l'éditeur, le message en flash), calqué sur
     * `gridResponse()`.
     *
     * La confirmation s'affiche DANS le bandeau, là où l'on vient de cliquer, et
     * le stream retire les flashes de la page : celui de l'arrivée (« Plan créé
     * pour… ») restait sinon en haut de l'éditeur, et passait pour la réponse au
     * rattachement (B-05 coach).
     */
    private function goalsResponse(Request $request, PlanTemplate $template, ?string $notice): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('plan_template/stream/goals.stream.html.twig', [
                'goalsNotice' => $notice,
            ] + $this->goalsContext($template));
        }

        if (null !== $notice) {
            $this->addFlash('success', $notice);
        }

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
    }

    /**
     * Contexte du bandeau d'objectifs : les objectifs liés (via la relation, donc
     * à jour dans la requête courante) et ceux, à venir, qu'on peut encore lier.
     *
     * @return array<string, mixed>
     */
    private function goalsContext(PlanTemplate $template): array
    {
        $owner = $template->getOwner();
        $linked = $template->getGoals();

        $available = null === $owner ? [] : array_values(array_filter(
            $this->goalRepository->findUpcomingForOwner($owner),
            static fn ($goal) => !$linked->contains($goal),
        ));

        return [
            'template' => $template,
            'linkedGoals' => $linked,
            'availableGoals' => $available,
        ];
    }

    #[Route('/{id}/delete', name: 'app_plan_template_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::DELETE, $template);

        if ($this->isCsrfTokenValid('delete'.$template->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($template);
            $this->entityManager->flush();

            $this->addFlash('success', 'Plan supprimé.');
        }

        return $this->redirectToRoute('app_plan_template_index');
    }

    /**
     * Duplique un plan visible en une copie appartenant au **propriétaire du plan
     * source** (utile pour itérer sans repartir de zéro). Le template source reste
     * intact. Un coach qui duplique le plan de son athlète obtient donc une variante
     * chez cet athlète, pas un plan orphelin dans sa propre bibliothèque : sinon les
     * copies locales lui appartiendraient et l'athlète ne verrait jamais le résultat.
     */
    #[Route('/{id}/duplicate', name: 'app_plan_template_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(Request $request, PlanTemplate $template, SlugGenerator $slugGenerator, WorkoutCloner $cloner): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        if (!$this->isCsrfTokenValid('duplicate'.$template->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        }

        $owner = $this->ownerOf($template);

        $copy = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($template->getTitle().' (copie)')
            ->setDescription($template->getDescription())
            ->setDurationWeeks((int) $template->getDurationWeeks())
            ->setSlug($slugGenerator->generate($template->getTitle().' copie', PlanTemplate::class));

        // Chaque case porte sa PROPRE copie de séance (progression indépendante) :
        // on clone donc la copie locale, pas seulement le placement. Sans ça, les
        // deux plans partageraient les mêmes séances et s'éditeraient mutuellement.
        foreach ($template->getPlanItems() as $item) {
            $source = $item->getWorkout();
            if (null === $source) {
                continue;
            }
            $workoutCopy = $cloner->cloneWorkout($source, $owner, $source->getTitle(), true);
            $copy->addPlanItem(
                (new PlanItem())
                    ->setWorkout($workoutCopy)
                    ->setWeekNumber((int) $item->getWeekNumber())
                    ->setDayOfWeek((int) $item->getDayOfWeek())
                    ->setNotes($item->getNotes())
            );
        }

        // cascade persist depuis PlanTemplate propage aux PlanItem.
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        $this->addFlash('success', 'Plan dupliqué.');

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $copy->getId()]);
    }

    // ---- Édition de la trame (placement des séances) -----------------------

    /**
     * Re-rend la trame seule, sans muter quoi que ce soit. Sert au rafraîchissement
     * de la grille après une édition rapide (mini-modale) : la durée estimée et les
     * volumes de semaine dérivent du contenu des séances, mais leur enregistrement
     * passe par WorkoutController, qui ne connaît pas le plan. Plutôt que recharger
     * la page (et perdre la position de défilement), le contrôleur `plangrid`
     * redemande ce stream à la fermeture de la modale.
     *
     * Sans JS, cette route n'est jamais appelée : le repli reste la redirection
     * vers l'éditeur (gridResponse).
     */
    #[Route('/{id}/grid', name: 'app_plan_template_grid', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function grid(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        return $this->gridResponse($request, $template);
    }

    /**
     * Pose une séance dans une case (palette : mode tampon / glisser-déposer).
     * Corps : workoutId + week + day. Forke la séance choisie en partant de sa
     * dernière réalisation s'il y en a une (LineageSeed), la pose dans la case,
     * puis resync si le plan est déjà au calendrier.
     */
    #[Route('/{id}/place', name: 'app_plan_template_item_place', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function placeItem(Request $request, PlanTemplate $template, LineageSeed $seed, PlanScheduler $scheduler, SharedWorkoutCopier $copier): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('plan_place'.$template->getId(), $payload->getString('_token'))) {
            $week = $payload->getInt('week');
            $day = $payload->getInt('day');
            $source = $this->placeableSource($template, $payload->getInt('workoutId'), $copier);

            if ($week >= 1 && $week <= (int) $template->getDurationWeeks() && $day >= 1 && $day <= 7 && null !== $source) {
                $this->placeWorkoutInCell($template, $source, $week, $day, null, $seed);
                $this->entityManager->flush();
                $scheduler->resync($template);
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * La séance de bibliothèque à partir de laquelle forker la case, ou null si la
     * palette n'avait pas à la proposer. Deux provenances admises, jamais une copie
     * locale d'un autre plan :
     *
     * - la bibliothèque du **propriétaire du plan**. Scoper sur l'utilisateur courant
     *   interdirait à un coach de poser une séance de son athlète ;
     * - celle du **coach courant**, quand il compose le plan de son athlète (#58).
     *   Elle passe d'abord dans la bibliothèque de l'athlète (une seule copie, reprise
     *   aux poses suivantes) : la case part de cette copie, l'athlète ne voit jamais
     *   une séance qui n'est pas à lui, et sa lignée vit chez lui.
     */
    private function placeableSource(PlanTemplate $template, int $workoutId, SharedWorkoutCopier $copier): ?Workout
    {
        $source = $this->workoutRepository->find($workoutId);
        if (null === $source || $source->isPlanLocal()) {
            return null;
        }

        $owner = $this->ownerOf($template);
        $sourceOwnerId = $source->getOwner()?->getId();
        if ($sourceOwnerId === $owner->getId()) {
            return $source;
        }

        $coach = $this->coachComposing($template);
        if (null === $coach || $sourceOwnerId !== $coach->getId()) {
            return null;
        }

        $copy = $copier->libraryCopyFor($source, $owner);
        // La pose part du dernier réalisé de la lignée (LineageSeed), qui se cherche
        // en base : une copie fraîche doit y être avant.
        $this->entityManager->flush();

        return $copy;
    }

    #[Route('/{id}/items/{itemId}/delete', name: 'app_plan_template_item_delete', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function deleteItem(Request $request, PlanTemplate $template, int $itemId, ScheduledWorkoutRepository $scheduledRepository): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);

        if ($this->isCsrfTokenValid('item_delete'.$itemId, $request->getPayload()->getString('_token'))) {
            $orphan = $this->detachItem($template, $item, $scheduledRepository);
            $this->entityManager->flush();

            // Copie orpheline nettoyée APRÈS le flush (pour que le retrait des séances
            // datées prenne effet avant la cascade workout -> scheduled).
            if (null !== $orphan) {
                $this->entityManager->remove($orphan);
                $this->entityManager->flush();
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Édition en ligne de la note d'une case (contrôleur `inline-edit`). Renvoie
     * la note persistée (texte brut) ; pas de re-render de grille nécessaire (la
     * note s'affiche là où on la modifie).
     */
    #[Route('/{id}/items/{itemId}/note', name: 'app_plan_template_item_note', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function updateItemNote(Request $request, PlanTemplate $template, int $itemId): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('item_note'.$itemId, $payload->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $value = trim($payload->getString('value'));
        $item->setNotes('' === $value ? null : $value);
        $this->entityManager->flush();

        return new Response($value);
    }

    /**
     * Ajoute une semaine vide en fin de trame (durationWeeks++). Pas de case créée,
     * donc rien à resynchroniser au calendrier.
     */
    #[Route('/{id}/weeks/add', name: 'app_plan_template_week_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addWeek(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        if ($this->isCsrfTokenValid('week_add'.$template->getId(), $request->getPayload()->getString('_token'))) {
            // `count` absent (bouton « + 1 semaine ») = une seule semaine. On borne
            // à ce qui reste avant le plafond plutôt que de refuser tout le paquet.
            $current = (int) $template->getDurationWeeks();
            $requested = max(1, $request->getPayload()->getInt('count', 1));
            $added = min($requested, self::MAX_WEEKS - $current);

            if ($added > 0) {
                $template->setDurationWeeks($current + $added);
                $this->entityManager->flush();
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Retire une semaine : détache ses cases (préserve le réalisé, cf. detachItem),
     * décale les semaines suivantes d'un cran et réaligne au calendrier les séances
     * encore prévues des cases décalées. Refuse de descendre sous 1 semaine.
     */
    #[Route('/{id}/weeks/{week}/remove', name: 'app_plan_template_week_remove', methods: ['POST'], requirements: ['id' => '\d+', 'week' => '\d+'])]
    public function removeWeek(Request $request, PlanTemplate $template, int $week, ScheduledWorkoutRepository $scheduledRepository, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        if ($this->isCsrfTokenValid('week_remove'.$week, $request->getPayload()->getString('_token'))
            && $week >= 1 && $week <= (int) $template->getDurationWeeks() && (int) $template->getDurationWeeks() > 1) {
            // 1) Détacher les cases de la semaine retirée (snapshot d'abord : on
            //    modifie la collection).
            $toDetach = [];
            foreach ($template->getPlanItems() as $item) {
                if ($item->getWeekNumber() === $week) {
                    $toDetach[] = $item;
                }
            }
            $orphans = [];
            foreach ($toDetach as $item) {
                $orphan = $this->detachItem($template, $item, $scheduledRepository);
                if (null !== $orphan) {
                    $orphans[] = $orphan;
                }
            }

            // 2) Décaler les semaines suivantes d'un cran.
            $shifted = [];
            foreach ($template->getPlanItems() as $item) {
                if ($item->getWeekNumber() > $week) {
                    $item->setWeekNumber($item->getWeekNumber() - 1);
                    $shifted[] = $item;
                }
            }

            $template->setDurationWeeks((int) $template->getDurationWeeks() - 1);
            $this->entityManager->flush();

            if ([] !== $orphans) {
                foreach ($orphans as $copy) {
                    $this->entityManager->remove($copy);
                }
                $this->entityManager->flush();
            }

            // 3) Le calendrier suit : les séances prévues des cases décalées migrent.
            foreach ($shifted as $item) {
                $scheduler->rescheduleItem($item, $this->ownerOf($template));
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Copie le contenu d'une semaine vers une ou plusieurs autres (#111). Chaque
     * séance est clonée en copie locale indépendante, une par semaine cible ; le
     * contenu de chaque cible est d'abord REMPLACÉ (ses cases détachées, réalisé
     * préservé). Support de la construction incrémentale (« ma S1 est bonne, je
     * la reporte sur S2 à S4 » en un geste, et non trois).
     *
     * La confirmation dit ce qui a été fait (« Semaine 1 copiée sur S2, S3 et
     * S4. »), sous l'en-tête de la semaine source, là où l'on vient de cliquer.
     */
    #[Route('/{id}/weeks/{week}/copy', name: 'app_plan_template_week_copy', methods: ['POST'], requirements: ['id' => '\d+', 'week' => '\d+'])]
    public function copyWeek(Request $request, PlanTemplate $template, int $week, WorkoutCloner $cloner, ScheduledWorkoutRepository $scheduledRepository, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();
        $duration = (int) $template->getDurationWeeks();

        if (!$this->isCsrfTokenValid('week_copy'.$week, $payload->getString('_token')) || $week < 1 || $week > $duration) {
            return $this->gridResponse($request, $template);
        }

        // « Toutes les suivantes » l'emporte sur les cases cochées : c'est le
        // bouton cliqué qui dit l'intention.
        $allAfter = $payload->getBoolean('all_after') && $week < $duration;
        $targets = $allAfter
            ? range($week + 1, $duration)
            : self::copyTargets($payload->all('targets'), $week, $duration);
        if ([] === $targets) {
            return $this->gridResponse($request, $template, [
                'week' => $week,
                'tone' => 'error',
                'text' => 'Coche au moins une semaine de destination.',
            ]);
        }

        // Snapshots AVANT toute modification de la collection.
        $sources = [];
        $targetItems = [];
        foreach ($template->getPlanItems() as $item) {
            if ($item->getWeekNumber() === $week) {
                $sources[] = $item;
            } elseif (\in_array($item->getWeekNumber(), $targets, true)) {
                $targetItems[] = $item;
            }
        }

        // 1) Vider les semaines cibles (remplacement, réalisé préservé).
        $orphans = [];
        foreach ($targetItems as $item) {
            $orphan = $this->detachItem($template, $item, $scheduledRepository);
            if (null !== $orphan) {
                $orphans[] = $orphan;
            }
        }

        // 2) Cloner les cases de la source vers chaque cible : une copie locale
        // par case ET par semaine, pour que chacune progresse seule.
        foreach ($targets as $target) {
            foreach ($sources as $item) {
                $source = $item->getWorkout();
                if (null === $source) {
                    continue;
                }
                $copy = $cloner->cloneWorkout($source, $this->ownerOf($template), $source->getTitle(), true);
                $newItem = (new PlanItem())
                    ->setWeekNumber($target)
                    ->setDayOfWeek((int) $item->getDayOfWeek())
                    ->setNotes($item->getNotes())
                    ->setWorkout($copy);
                $template->addPlanItem($newItem);
                $this->entityManager->persist($newItem);
            }
        }

        $this->entityManager->flush();

        if ([] !== $orphans) {
            foreach ($orphans as $copy) {
                $this->entityManager->remove($copy);
            }
            $this->entityManager->flush();
        }

        $scheduler->resync($template);

        return $this->gridResponse($request, $template, [
            'week' => $week,
            'tone' => 'success',
            'text' => $allAfter && \count($targets) > 1
                ? sprintf('Semaine %d copiée sur toutes les suivantes (S%d à S%d).', $week, $week + 1, $duration)
                : self::weekCopyMessage($week, $targets),
        ]);
    }

    /**
     * Les semaines cibles cochées, nettoyées : entiers du plan, sans la source,
     * sans doublon, dans l'ordre. Une valeur hors plan est ignorée, pas refusée
     * en bloc : le plan a pu raccourcir dans un autre onglet.
     *
     * @param array<mixed> $raw
     *
     * @return list<int>
     */
    private static function copyTargets(array $raw, int $week, int $duration): array
    {
        $targets = [];
        foreach ($raw as $value) {
            if (!\is_scalar($value) || !ctype_digit((string) $value)) {
                continue;
            }
            $target = (int) $value;
            if ($target >= 1 && $target <= $duration && $target !== $week) {
                $targets[$target] = $target;
            }
        }
        ksort($targets);

        return array_values($targets);
    }

    /**
     * « Semaine 1 copiée sur S2. », « … sur S2 et S3. », « … sur S2, S3 et S4. »
     *
     * @param non-empty-list<int> $targets
     */
    private static function weekCopyMessage(int $week, array $targets): string
    {
        $labels = array_map(static fn (int $w): string => 'S'.$w, $targets);
        $last = array_pop($labels);
        $list = [] === $labels ? $last : implode(', ', $labels).' et '.$last;

        return sprintf('Semaine %d copiée sur %s.', $week, $list);
    }

    /**
     * Copie UNE case vers une autre semaine et un autre jour, sans toucher au reste
     * de la trame. Le pendant unitaire de `copyWeek` : reprendre la séance de la
     * semaine 3 dans la semaine 6, sans recopier toute la semaine 3.
     *
     * La case cible ne remplace rien : un jour porte plusieurs séances, la copie
     * s'y ajoute. Elle reçoit sa PROPRE copie locale (progression indépendante),
     * clonée depuis la case source, valeurs ajustées comprises, et garde sa lignée
     * (`origin`) : c'est toujours la même séance, à une autre date. Le calendrier
     * suit si le plan y est posé (`resync`, add-only).
     */
    #[Route('/{id}/items/{itemId}/copy', name: 'app_plan_template_item_copy', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function copyItem(Request $request, PlanTemplate $template, int $itemId, WorkoutCloner $cloner, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);
        $payload = $request->getPayload();

        $source = $item->getWorkout();
        $week = $payload->getInt('week');
        $day = $payload->getInt('day');

        if ($this->isCsrfTokenValid('item_copy'.$itemId, $payload->getString('_token'))
            && null !== $source
            && $week >= 1 && $week <= (int) $template->getDurationWeeks() && $day >= 1 && $day <= 7) {
            $copy = (new PlanItem())
                ->setWeekNumber($week)
                ->setDayOfWeek($day)
                ->setNotes($item->getNotes())
                ->setWorkout($cloner->cloneWorkout($source, $this->ownerOf($template), $source->getTitle(), true));
            $template->addPlanItem($copy);
            $this->entityManager->persist($copy);
            $this->entityManager->flush();

            $scheduler->resync($template);
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Déplace une case dans une autre semaine/jour (glisser-déposer SortableJS).
     * Réaligne les séances datées ENCORE PRÉVUES sur la nouvelle position (le
     * calendrier suit le plan), en préservant les DONE/MISSED (leur date =
     * réalisé). Voir PlanScheduler::rescheduleItem.
     */
    #[Route('/{id}/items/{itemId}/move', name: 'app_plan_template_item_move', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function moveItem(Request $request, PlanTemplate $template, int $itemId, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('item_move'.$itemId, $payload->getString('_token'))) {
            $week = $payload->getInt('week');
            $day = $payload->getInt('day');
            if ($week >= 1 && $week <= (int) $template->getDurationWeeks() && $day >= 1 && $day <= 7) {
                $item->setWeekNumber($week)->setDayOfWeek($day);
                $this->entityManager->flush();

                // Le calendrier suit : les séances prévues issues de cette case
                // migrent à la nouvelle date (DONE/MISSED conservées).
                $scheduler->rescheduleItem($item, $this->ownerOf($template));
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Outil de progression (#110) : faire progresser la charge ou les répétitions
     * d'un exercice sur une plage de semaines, dans toutes les cases de sa séance.
     *
     * Une page à part plutôt qu'une modale : l'aperçu est un tableau de toutes les
     * cases concernées, rendu par le serveur, qui marche sans JS. GET = choisir et
     * voir l'aperçu (le formulaire se recharge sur lui-même), POST = appliquer ce
     * même aperçu, puis retour à l'éditeur. Les deux passent par
     * `ProgressionInput::parse()` et `PlanProgression::preview()` : on applique
     * exactement ce qu'on a vu.
     */
    #[Route('/{id}/progression', name: 'app_plan_template_progression', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function progression(Request $request, PlanTemplate $template, PlanProgression $progression): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        $targets = $progression->targets($template);
        $weeks = (int) $template->getDurationWeeks();
        $isPost = $request->isMethod('POST');
        $bag = $isPost ? $request->request : $request->query;

        // Première visite : l'exercice le plus haut de la liste, sur les semaines où
        // il apparaît, à +2,5 kg (ou +1 rép.) par semaine. L'aperçu se montre
        // d'emblée : on voit ce que l'outil fait avant d'avoir rien réglé.
        if (!$isPost && !$bag->has('target') && [] !== $targets) {
            $first = $targets[0]['exercises'][0];
            $bag = new InputBag([
                'target' => $first['key'],
                'field' => $first['defaultField']->value,
                'from' => (string) $first['firstWeek'],
                'to' => (string) max($first['lastWeek'], $first['firstWeek']),
                'step' => ProgressionField::REPS === $first['defaultField'] ? '1' : '2,5',
                'start' => '',
            ]);
        }

        $preview = null;
        $error = null;
        if ([] !== $targets) {
            try {
                $input = ProgressionInput::parse($bag, $weeks);

                if ($isPost) {
                    if (!$this->isCsrfTokenValid('plan_progression'.$template->getId(), $request->getPayload()->getString('_token'))) {
                        throw new \InvalidArgumentException('La page a expiré : vérifie l\'aperçu et applique à nouveau.');
                    }
                    $result = $progression->apply($template, $input);
                    $this->entityManager->flush();

                    $this->addFlash('success', $this->progressionMessage($result, $input));

                    return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
                }

                $preview = $progression->preview($template, $input);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('plan_template/progression.html.twig', [
            'template' => $template,
            'targets' => $targets,
            'values' => $bag->all(),
            'preview' => $preview,
            'error' => $error,
            'fields' => ProgressionField::cases(),
        ], new Response(null, null !== $error && $isPost ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * « Développé militaire (Upper A) : 3 cases mises à jour, de 60 kg en S1 à
     * 67,5 kg en S4. » Rien à changer se dit aussi : sinon on croit à un échec.
     *
     * @param array{root: Workout, exercise: Exercise, start: float, changes: int} $result
     */
    private function progressionMessage(array $result, ProgressionInput $input): string
    {
        $what = \sprintf('%s (%s)', $this->exerciseNaming->label($result['exercise']), $result['root']->getTitle());
        if (0 === $result['changes']) {
            return $what.' : aucune case à changer, le plan prescrivait déjà ces valeurs.';
        }

        $end = $result['start'] + ($input->toWeek - $input->fromWeek) * $input->step;
        $format = fn (float $v): string => ProgressionField::WEIGHT === $input->field
            ? $this->unitFormatter->weight(max(0.0, $v))
            : \sprintf('%d %s', max(1, (int) round($v)), $input->field->unit());

        return \sprintf(
            '%s : %d case%s mise%s à jour, de %s en S%d à %s en S%d.',
            $what,
            $result['changes'],
            $result['changes'] > 1 ? 's' : '',
            $result['changes'] > 1 ? 's' : '',
            $format($result['start']),
            $input->fromWeek,
            $format($end),
            $input->toWeek,
        );
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * Propriétaire du plan — la seule référence valable pour tout ce que cet
     * éditeur crée (copies locales, copies de bibliothèque, calendrier ciblé).
     * L'utilisateur courant n'est pas forcément le propriétaire : un coach accepté
     * édite la trame de son athlète, et le contenu doit rester à l'athlète
     * (cf. CLAUDE.md §3). Seule lecture élargie : la palette, qui propose aussi
     * au coach ses propres séances (cf. `coachComposing()`). Repli sur l'utilisateur courant pour un plan sans owner
     * (données anciennes) — le cas normal ne s'y appuie pas.
     */
    private function ownerOf(PlanTemplate $template): User
    {
        /** @var User $current */
        $current = $this->getUser();

        return $template->getOwner() ?? $current;
    }

    /**
     * Le coach qui compose le plan de son athlète, ou null si l'utilisateur courant
     * est le propriétaire (ou un plan sans propriétaire). Seul cas où l'éditeur lit
     * une seconde bibliothèque : la sienne, pour la palette et la pose (#58).
     */
    private function coachComposing(PlanTemplate $template): ?User
    {
        $viewer = $this->getUser();
        $owner = $template->getOwner();

        if (!$viewer instanceof User || null === $owner || $viewer->getId() === $owner->getId()) {
            return null;
        }

        return $this->coachingResolver->isAcceptedCoachOf($viewer, $owner) ? $viewer : null;
    }

    /**
     * Contexte du bandeau « Pour qui » de l'éditeur (#58). Le propriétaire voit ses
     * athlètes à qui donner le plan ; un coach sur le plan d'un athlète voit à qui
     * il appartient.
     *
     * @return array{assignableAthletes: list<User>, onCalendar: bool, composingFor: User|null}
     */
    private function assignContext(PlanTemplate $template): array
    {
        $viewer = $this->getUser();
        $isOwner = $viewer instanceof User && $template->getOwner()?->getId() === $viewer->getId();

        return [
            'assignableAthletes' => $isOwner ? $this->coachedLibrary->athletesOf($viewer) : [],
            'onCalendar' => !$template->getScheduledWorkouts()->isEmpty(),
            'composingFor' => null !== $this->coachComposing($template) ? $template->getOwner() : null,
        ];
    }

    /**
     * Garantit un slug (nom du fichier d'export). Les plans créés/dupliqués en ont déjà un ;
     * ce repli couvre d'éventuelles données anciennes au slug null.
     */
    private function ensureSlug(PlanTemplate $template, SlugGenerator $slugGenerator): void
    {
        if (null === $template->getSlug() || '' === $template->getSlug()) {
            $template->setSlug($slugGenerator->generate((string) $template->getTitle(), PlanTemplate::class));
            $this->entityManager->flush();
        }
    }

    /**
     * Fork à la pose : la case reçoit sa PROPRE copie (planLocal) de la séance
     * source, éditable et progressable sans toucher la biblio ni les autres cases.
     * La copie part de la dernière réalisation de la séance quand il y en a une
     * (cf. LineageSeed), de la bibliothèque sinon.
     * La copie appartient au propriétaire du plan (le contenu appartient toujours
     * à l'athlète) : c'est elle que référencera la séance datée au calendrier.
     * Persiste l'item ; le flush et le resync calendrier restent à l'appelant
     * (pour grouper les poses multiples si besoin).
     */
    private function placeWorkoutInCell(PlanTemplate $template, Workout $source, int $week, int $day, ?string $notes, LineageSeed $seed): PlanItem
    {
        $copy = $seed->seed($source, $this->ownerOf($template));
        $item = (new PlanItem())
            ->setWeekNumber($week)
            ->setDayOfWeek($day)
            ->setNotes($notes)
            ->setWorkout($copy);
        $template->addPlanItem($item);
        $this->entityManager->persist($item);

        return $item;
    }

    /**
     * Détache une case de la trame en PRÉSERVANT le réalisé : retire ses séances
     * datées `PLANNED`, conserve `DONE`/`MISSED` (leur date matérialise le réalisé,
     * leur lien vers la case passera à NULL — SET NULL), retire la case du template.
     * Renvoie la copie locale à nettoyer si elle devient orpheline (aucune séance
     * conservée ne la référence), sinon null. Le flush et la suppression de la copie
     * restent à l'appelant (pour batcher : la suppression de la copie doit suivre le
     * flush du retrait des séances datées, cf. cascade workout -> scheduled).
     */
    private function detachItem(PlanTemplate $template, PlanItem $item, ScheduledWorkoutRepository $scheduledRepository): ?Workout
    {
        $copy = $item->getWorkout();

        $kept = 0;
        foreach ($scheduledRepository->findBySourcePlanItem($item) as $scheduled) {
            if (ScheduledStatus::PLANNED === $scheduled->getStatus()) {
                $this->entityManager->remove($scheduled);
            } else {
                ++$kept;
            }
        }

        $template->removePlanItem($item);

        return (null !== $copy && $copy->isPlanLocal() && 0 === $kept) ? $copy : null;
    }

    private function findItem(PlanTemplate $template, int $itemId): PlanItem
    {
        foreach ($template->getPlanItems() as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        throw $this->createNotFoundException('Case introuvable dans ce plan.');
    }

    /**
     * Contexte de rendu de l'éditeur de trame : le plan aplati (grille dense
     * semaines × jours) et le volume par semaine.
     *
     * @return array<string, mixed>
     */
    private function gridContext(PlanTemplate $template): array
    {
        $feelings = [];
        foreach ($this->scheduledWorkoutRepository->recentRatingsByLineage($this->ownerOf($template), self::PALETTE_RATINGS) as $root => $entries) {
            $feelings[$root] = self::feelingSummary($entries);
        }

        return [
            'template' => $template,
            'flat' => $this->planFlattener->flattenPlanTemplate($template),
            'weekVolumes' => $this->volumeAggregator->byWeek($template),
            // Le ressenti par racine de lignée, pour l'aperçu d'une case posée :
            // la copie locale y renvoie par `origin`.
            'lineageFeelings' => $feelings,
            // Cases qui répètent à l'identique la fois d'avant de leur lignée (#93).
            'repeats' => $this->lineageDivergence->repeatsInPlan($template),
        ];
    }

    /**
     * Le ressenti d'une lignée sur la carte de palette : la dernière exécution
     * notée, la moyenne de chaque axe sur les `PALETTE_RATINGS` dernières (un axe
     * absent ne compte pas dans sa moyenne), et la liste datée pour le survol.
     * Null quand rien n'a été noté : la carte n'annonce pas une absence.
     *
     * @param list<array{id: int, date: \DateTimeImmutable, rating: SessionRating}> $entries du plus récent au plus ancien
     *
     * @return array{last: SessionRating, feeling: float|null, sessionRpe: float|null, count: int, entries: list<array{id: int, date: \DateTimeImmutable, rating: SessionRating}>}|null
     */
    private static function feelingSummary(array $entries): ?array
    {
        if ([] === $entries) {
            return null;
        }

        $average = static function (array $values): ?float {
            $values = array_values(array_filter($values, static fn (?int $v): bool => null !== $v));

            return [] === $values ? null : round(array_sum($values) / \count($values), 1);
        };

        return [
            'last' => $entries[0]['rating'],
            'feeling' => $average(array_map(static fn (array $e): ?int => $e['rating']->feeling, $entries)),
            'sessionRpe' => $average(array_map(static fn (array $e): ?int => $e['rating']->sessionRpe, $entries)),
            'count' => \count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * Contexte de la palette de séances (volet gauche de l'éditeur) : les séances
     * de bibliothèque avec leurs repères de carte (activités distinctes, nombre
     * d'exos, texte de recherche) et les filtres d'activité présents. Chargée une
     * fois au rendu de la page (hors des flux de grille), avec le contenu
     * fetch-joint pour éviter tout N+1.
     *
     * Portée : la bibliothèque du **propriétaire du plan**, pas celle de
     * l'utilisateur courant. Un coach qui compose la trame d'un athlète doit y
     * retrouver les séances de cet athlète — dont celles qu'il a lui-même créées
     * pour lui, qui appartiennent à l'athlète (cf. CLAUDE.md §3). S'y ajoutent,
     * pour ce coach seulement, ses propres séances pas encore copiées chez
     * l'athlète (#58) : la pose les y copie (`placeableSource()`). Les déjà
     * copiées ne restent nommées que par `paletteAlreadyCopied` (#112).
     *
     * @return array<string, mixed>
     */
    private function paletteContext(PlanTemplate $template): array
    {
        $owner = $this->ownerOf($template);
        $workouts = $this->workoutRepository->findLibraryForOwnerWithContent($owner);

        // Le coach qui compose le plan de son athlète voit aussi SES séances (#58).
        // Celles déjà copiées chez l'athlète s'effacent : leur copie est dans la
        // liste ci-dessus, et c'est elle que la pose reprendrait de toute façon.
        // Mais l'effacement se dit (#112) : la copie porte « Copie de la tienne »
        // et une ligne sous les pastilles les compte, sans quoi « Moi · 0 » se lit
        // comme une séance perdue.
        $coach = $this->coachComposing($template);
        $coachWorkouts = [];
        $coachIds = [];
        $alreadyCopied = [];
        if (null !== $coach) {
            $copied = array_flip($this->workoutRepository->findCopiedSourceIds($owner));
            foreach ($this->workoutRepository->findLibraryForOwnerWithContent($coach) as $w) {
                $coachIds[(int) $w->getId()] = true;
                if (isset($copied[(int) $w->getId()])) {
                    $alreadyCopied[] = $w;
                } else {
                    $coachWorkouts[] = $w;
                }
            }
        }
        // La pose part de la dernière réalisation : la carte le dit, pour que
        // les valeurs de la case ne changent pas de source en silence.
        $lastDone = $this->scheduledWorkoutRepository->lastDoneDateByLineage($owner);
        // Et comment ça s'est passé : on compose la semaine suivante en sachant
        // que « Upper A » a été vécue « faible » deux fois de suite.
        $feelings = $this->scheduledWorkoutRepository->recentRatingsByLineage($owner, self::PALETTE_RATINGS);
        // Et ce qui a été fait en plus : la pose ne le reprend pas, la carte le
        // signale, l'édition rapide de la case le propose.
        $offProgram = $this->offProgramRecap->countsByLineage($owner);

        $cards = [];
        $countsByActivity = [];
        foreach ([...$workouts, ...$coachWorkouts] as $index => $workout) {
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
                'lastDone' => $lastDone[(int) $workout->getId()] ?? null,
                'feelings' => self::feelingSummary($feelings[(int) $workout->getId()] ?? []),
                'offProgram' => $offProgram[(int) $workout->getId()] ?? 0,
                // À la séance du coach : la poser la copiera chez l'athlète.
                'mine' => $index >= \count($workouts),
                // Chez l'athlète, la copie d'une séance du coach (#112) : c'est
                // elle qui remplace l'original effacé de « Moi ». Lire l'id d'un
                // proxy ne le charge pas.
                'copyOfMine' => isset($coachIds[(int) $workout->getCopiedFrom()?->getId()]),
            ];
        }

        // Filtres d'activité présents, dans l'ordre canonique de l'enum. Un même
        // workout compte pour chaque activité qu'il contient.
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

        return [
            'paletteCards' => $cards,
            'paletteCount' => \count($cards),
            'paletteActivities' => $activityFilters,
            // Pastilles « à qui » : seulement quand deux bibliothèques se croisent.
            'paletteOwners' => null === $coach ? null : [
                'owner' => ['label' => $owner->getDisplayName(), 'count' => \count($workouts)],
                'mine' => ['label' => 'Moi', 'count' => \count($coachWorkouts)],
            ],
            'paletteAlreadyCopied' => $alreadyCopied,
        ];
    }

    /**
     * Répond à une mutation de la trame : Turbo Stream si accepté, sinon repli
     * par redirection vers l'édition (dégradation sans JS).
     *
     * `$notice` (facultatif) confirme le geste sous l'en-tête de la semaine
     * concernée ; le stream retire d'abord les flashes de la page, qui se liraient
     * sinon comme la réponse (même logique que `goalsResponse()`). Sans JS, il
     * devient un flash sur l'éditeur.
     *
     * @param array{week: int, tone: 'success'|'error', text: string}|null $notice
     */
    private function gridResponse(Request $request, PlanTemplate $template, ?array $notice = null): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('plan_template/stream/grid.stream.html.twig', [
                'gridNotice' => $notice,
            ] + $this->gridContext($template));
        }

        if (null !== $notice) {
            $this->addFlash($notice['tone'], $notice['text']);
        }

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
    }
}
