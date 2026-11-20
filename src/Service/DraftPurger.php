<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PlanTemplateRepository;
use App\Repository\WorkoutRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supprime les brouillons abandonnés (#115) : une « Nouvelle séance » ou un
 * « Nouveau plan » créés en un clic puis quittés sans rien y faire. La règle qui
 * les reconnaît vit dans les dépôts (`abandonedDraftCondition()`), ici on ne fait
 * que la borner dans le temps.
 *
 * **Deux temps, et c'est voulu.** Les listes les masquent tout de suite, la purge
 * ne les supprime qu'après un délai de grâce. Supprimer dès le retour à la liste
 * aurait tué le brouillon qu'on compose encore dans un autre onglet, ou celui que
 * Turbo précharge au survol d'un lien : la prochaine action y aurait pris une 404.
 * Masqué, il ne gêne personne ; vieux d'un jour, plus personne ne l'a ouvert.
 *
 * Passe par l'unité de travail plutôt qu'un `DELETE` en DQL : un brouillon
 * abandonné ne porte rien, mais si un écouteur de suppression s'ajoute un jour
 * (pierre tombale, journal), il le verra passer.
 */
final readonly class DraftPurger
{
    public const int DEFAULT_GRACE_HOURS = 24;

    public function __construct(
        private WorkoutRepository $workouts,
        private PlanTemplateRepository $planTemplates,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{workouts: int, plans: int}
     */
    public function purge(int $graceHours = self::DEFAULT_GRACE_HOURS): array
    {
        $before = new \DateTimeImmutable(\sprintf('-%d hours', max(0, $graceHours)));

        $workouts = $this->workouts->findAbandonedDrafts($before);
        $plans = $this->planTemplates->findAbandonedDrafts($before);

        foreach ([...$workouts, ...$plans] as $draft) {
            $this->entityManager->remove($draft);
        }
        $this->entityManager->flush();

        return ['workouts' => \count($workouts), 'plans' => \count($plans)];
    }
}
