<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\GoalBlock;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\Workout;
use App\Repository\PlanTemplateRepository;
use App\Repository\WorkoutRepository;
use App\Service\GoalPhotoStorage;
use App\Service\GoalStory;
use App\Service\PlanFlattener;
use App\Service\ShareLink;
use App\Service\WorkoutMetrics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques : une vue lecture seule, sans authentification et sans aucune
 * action d'édition. Tout reste sous le préfixe `/s` (hors access_control).
 *
 * **Privé par défaut, partout.** Une séance ou un plan n'a de page publique que
 * si son propriétaire a ouvert le partage par lien (#61, `ShareLink`) : l'adresse
 * porte alors un jeton aléatoire, qui vaut à lui seul autorisation de lecture. Un
 * jeton inconnu et un partage arrêté rendent la même réponse (le jeton effacé
 * n'existe simplement plus) : rien ne confirme qu'un contenu a existé. Les voters
 * n'interviennent pas, l'édition reste au propriétaire par ailleurs.
 *
 * Une case de plan (copie `planLocal`) n'a jamais de lien à elle : elle se lit
 * sous le jeton de son plan, et seulement lui.
 *
 * Le récit d'objectif suit la même règle opt-in depuis #42, avec son slug : un
 * objectif est privé, son slug n'existe qu'après une première publication, et il
 * ne vaut lecture que tant que `publishedAt` est posé.
 */
final class PublicShareController extends AbstractController
{
    public function __construct(
        private readonly WorkoutRepository $workoutRepository,
        private readonly PlanTemplateRepository $planTemplateRepository,
        private readonly PlanFlattener $planFlattener,
    ) {
    }

    #[Route('/s/{token}', name: 'app_public_share', methods: ['GET'], requirements: ['token' => ShareLink::TOKEN])]
    public function workout(string $token, WorkoutMetrics $metrics): Response
    {
        return $this->renderWorkout($this->sharedWorkout($token), $metrics);
    }

    #[Route('/s/plan/{token}', name: 'app_public_share_plan', methods: ['GET'], requirements: ['token' => ShareLink::TOKEN])]
    public function plan(string $token): Response
    {
        return $this->render('public_share/plan.html.twig', [
            'flat' => $this->planFlattener->flattenPlanTemplate($this->sharedPlan($token)),
            'weeks' => null,
        ]);
    }

    /**
     * Partage d'une plage de semaines : `{range}` = `2-4` ou `3`. Filtre la grille
     * sur ces semaines (bornées à la durée du plan). Stateless : la plage vit dans
     * l'URL, aucun stockage. C'est un filtre, pas une autorisation : le lien reste
     * celui du plan entier. 404 si la plage est vide/hors trame.
     */
    #[Route('/s/plan/{token}/semaines/{range}', name: 'app_public_share_plan_weeks', methods: ['GET'], requirements: ['token' => ShareLink::TOKEN, 'range' => '\d+(?:-\d+)?'])]
    public function planWeeks(string $token, string $range): Response
    {
        $template = $this->sharedPlan($token);

        $durationWeeks = (int) $template->getDurationWeeks();
        [$fromRaw, $toRaw] = str_contains($range, '-')
            ? array_map('intval', explode('-', $range, 2))
            : [(int) $range, (int) $range];

        $from = max(1, min($fromRaw, $toRaw));
        $to = min($durationWeeks, max($fromRaw, $toRaw));
        if ($from > $to) {
            throw $this->createNotFoundException('Plage de semaines hors de la trame.');
        }

        return $this->render('public_share/plan.html.twig', [
            'flat' => $this->planFlattener->flattenPlanTemplate($template),
            'weeks' => range($from, $to),
        ]);
    }

    /**
     * Une case d'un plan partagé, lue sous le jeton du plan. C'est la seule
     * porte vers une copie `planLocal` : le lien du plan l'ouvre, elle n'en a pas.
     * Une case d'un autre plan rend 404, comme un jeton inconnu.
     */
    #[Route('/s/plan/{token}/seance/{item}', name: 'app_public_share_plan_item', methods: ['GET'], requirements: ['token' => ShareLink::TOKEN, 'item' => '\d+'])]
    public function planItem(string $token, int $item, WorkoutMetrics $metrics): Response
    {
        $template = $this->sharedPlan($token);

        $planItem = $template->getPlanItems()->findFirst(static fn (int $key, PlanItem $candidate): bool => $candidate->getId() === $item);
        $workout = $planItem?->getWorkout();
        if (null === $planItem || null === $workout) {
            throw $this->createNotFoundException();
        }

        return $this->renderWorkout($workout, $metrics, $template);
    }

    /**
     * Le récit publié d'un objectif. N'affiche que ce qui se raconte : intitulé,
     * échéance, cible, présentation, récit, résultat. Jamais les plans rattachés
     * ni les séances à venir — c'est la préparation, pas l'histoire.
     */
    #[Route('/s/goal/{slug}', name: 'app_public_share_goal', methods: ['GET'])]
    public function goal(
        #[MapEntity(mapping: ['slug' => 'slug'])] Goal $goal,
        GoalStory $story,
    ): Response {
        if (!$goal->isPublished()) {
            throw $this->createNotFoundException();
        }

        return $this->render('public_share/goal.html.twig', [
            'goal' => $goal,
            'story' => $story->present($goal),
        ]);
    }

    /** Cache public, mais court : dépublier doit finir par se voir chez un intermédiaire. */
    #[Route('/s/goal/{slug}/photo/{blockId}', name: 'app_public_share_goal_photo', methods: ['GET'], requirements: ['blockId' => '\d+'])]
    public function goalPhoto(
        #[MapEntity(mapping: ['slug' => 'slug'])] Goal $goal,
        #[MapEntity(id: 'blockId')] GoalBlock $block,
        GoalPhotoStorage $photos,
    ): Response {
        if (!$goal->isPublished() || $block->getGoal()?->getId() !== $goal->getId()) {
            throw $this->createNotFoundException();
        }

        $response = $photos->response($block);
        if (null === $response) {
            throw $this->createNotFoundException();
        }
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * Mêmes repères qu'en interne : la synthèse est dérivée du contenu déjà
     * publié, elle n'expose rien de plus que la séance elle-même.
     */
    private function renderWorkout(Workout $workout, WorkoutMetrics $metrics, ?PlanTemplate $plan = null): Response
    {
        return $this->render('public_share/workout.html.twig', [
            'flat' => $this->planFlattener->flattenWorkout($workout),
            'summary' => $metrics->summary($workout),
            'blockStats' => $metrics->blockBreakdown($workout),
            'plan' => $plan,
        ]);
    }

    /**
     * Une seule réponse pour « jamais partagée », « partage arrêté » et « jeton
     * inventé » : c'est la même recherche qui échoue. `planLocal` en garde-fou,
     * une case ne devant jamais porter de jeton (`ShareLink::open` le refuse).
     */
    private function sharedWorkout(string $token): Workout
    {
        return $this->workoutRepository->findOneBy(['shareToken' => $token, 'planLocal' => false])
            ?? throw $this->createNotFoundException();
    }

    private function sharedPlan(string $token): PlanTemplate
    {
        return $this->planTemplateRepository->findOneBy(['shareToken' => $token])
            ?? throw $this->createNotFoundException();
    }
}
