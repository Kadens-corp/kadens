<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « CoachAudit a ajouté 3 séances à ton agenda ces 7 derniers jours » (#80) :
 * l'encart d'accueil de l'athlète, par coach.
 *
 * Se lit sur `addedBy` (cf. `ContentAuthorListener`) et `createdAt`, rien
 * d'autre : aucun état « vu » n'est mémorisé, l'encart s'éteint seul quand la
 * fenêtre glisse. Trois agrégats scalaires, un par type de contenu :
 *   - séances datées : ce que le coach a posé au calendrier, directement ou en y
 *     plaçant un plan. Une séance que l'athlète pose lui-même à partir d'un plan
 *     du coach n'entre pas ici, elle porte seulement le badge ;
 *   - séances de bibliothèque : les copies locales de plan (`planLocal`) n'en
 *     font pas partie, elles se comptent dans leur plan ;
 *   - plans : créés, copiés ou donnés (`PlanTransfer` remet `createdAt` au don).
 */
final class CoachContributions
{
    public const WINDOW_DAYS = 7;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Par coach, le plus actif d'abord.
     *
     * @return list<array{coach: User, scheduled: int, workouts: int, plans: int}>
     */
    public function recentFor(User $owner, ?\DateTimeImmutable $now = null): array
    {
        $since = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d days', self::WINDOW_DAYS));

        $counts = [];
        foreach ([
            'scheduled' => $this->countByAuthor(ScheduledWorkout::class, $owner, $since),
            'workouts' => $this->countByAuthor(Workout::class, $owner, $since, 'AND x.planLocal = false'),
            'plans' => $this->countByAuthor(PlanTemplate::class, $owner, $since),
        ] as $kind => $byAuthor) {
            foreach ($byAuthor as $authorId => $n) {
                $counts[$authorId] ??= ['scheduled' => 0, 'workouts' => 0, 'plans' => 0];
                $counts[$authorId][$kind] = $n;
            }
        }

        if ([] === $counts) {
            return [];
        }

        $rows = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['id' => array_keys($counts)]) as $coach) {
            $rows[] = ['coach' => $coach] + $counts[(int) $coach->getId()];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['scheduled'] + $b['workouts'] + $b['plans']) <=> ($a['scheduled'] + $a['workouts'] + $a['plans']));

        return $rows;
    }

    /**
     * @param class-string $entity
     *
     * @return array<int, int> id de l'auteur => nombre
     */
    private function countByAuthor(string $entity, User $owner, \DateTimeImmutable $since, string $extra = ''): array
    {
        /** @var list<array{author: int|string, n: int|string}> $rows */
        $rows = $this->entityManager->createQuery(sprintf(
            'SELECT IDENTITY(x.addedBy) AS author, COUNT(x.id) AS n FROM %s x
             WHERE x.owner = :owner AND x.addedBy IS NOT NULL AND x.addedBy <> :owner
               AND x.createdAt >= :since %s
             GROUP BY x.addedBy',
            $entity,
            $extra,
        ))
            ->setParameter('owner', $owner)
            ->setParameter('since', $since)
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['author']] = (int) $row['n'];
        }

        return $counts;
    }
}
