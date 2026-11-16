<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Repository\ImportedActivityRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Les dernières séances DATÉES d'un utilisateur, toutes celles qui sont échues :
 * faites, manquées, ou restées « prévues » après leur date (#76). C'est la
 * section « Ses dernières séances » de la fiche athlète du coach.
 *
 * Elle existe parce que le journal (`TrainingLog`) ne liste que ce qui porte du
 * réalisé consigné. Une séance manquée n'y paraissait jamais, une séance cochée
 * « faite » sans détail non plus : le coach lisait « 1 manquée » et « 1 faite »
 * dans les compteurs sans pouvoir ouvrir ni l'une ni l'autre (C-10, C-11).
 *
 * Trois règles :
 *
 * - **La fenêtre est courte et fixe** (`WEEKS`) : c'est un aperçu de fiche, la
 *   suite se lit sur le journal et l'historique. Bornée, elle peut hydrater les
 *   séances datées (une requête, séance source jointe) sans trahir la
 *   contrainte de coût des statistiques.
 * - **Une séance consignée garde sa ligne de journal** : séries, tonnage, durée
 *   et RPE viennent de `TrainingLog`, sur la même fenêtre. Aucun chiffre n'est
 *   recalculé ici, la fiche et le journal disent la même chose d'une séance.
 * - **« Échue » partage la journée en deux** (`isPast()`) : une séance d'avant
 *   aujourd'hui l'est toujours ; une séance d'aujourd'hui ne l'est qu'une fois
 *   cochée. Ce qui reste prévu aujourd'hui est encore à venir, et l'agenda des
 *   prochaines semaines s'en charge. Chaque séance tombe donc dans une seule
 *   des deux listes de la fiche.
 *
 * Une séance faite sans rien derrière (ni série consignée, ni activité importée
 * de la montre) porte la mention « sans détail » : elle a eu lieu, il n'y a
 * rien à y lire. Le combler avec le prescrit ferait passer une intention pour un
 * fait (CLAUDE.md §3).
 *
 * @phpstan-type RecentSession array{id: int, date: \DateTimeImmutable, title: string, status: ScheduledStatus, workingSets: int, tonnageKg: float, durationSeconds: int|null, averageRpe: float|null, exercises: int, skipped: int, rating: SessionRating|null, note: string|null}
 */
final readonly class RecentSessions
{
    /** Profondeur de l'aperçu, en semaines, aujourd'hui compris. */
    public const WEEKS = 4;

    public const NOTE_BARE = 'sans détail';
    public const NOTE_UNCHECKED = 'pas cochée';

    public function __construct(
        private ScheduledWorkoutRepository $scheduledWorkouts,
        private ImportedActivityRepository $importedActivities,
        private TrainingLog $trainingLog,
    ) {
    }

    /**
     * Les séances échues des `WEEKS` dernières semaines, la plus récente d'abord.
     *
     * @return list<RecentSession>
     */
    public function for(User $owner, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $from = self::since($today);

        $sessions = array_values(array_filter(
            $this->scheduledWorkouts->findByOwnerBetween($owner, $from, $today),
            static fn (ScheduledWorkout $scheduled): bool => self::isPast($scheduled, $today),
        ));

        if ([] === $sessions) {
            return [];
        }

        // Le dépôt trie par date puis id croissants : à l'envers, c'est l'ordre
        // du journal (date puis id décroissants).
        $sessions = array_reverse($sessions);
        $ids = array_map(static fn (ScheduledWorkout $scheduled): int => (int) $scheduled->getId(), $sessions);

        $logged = [];
        foreach ($this->trainingLog->between($owner, $from, $today) as $entry) {
            $logged[$entry['id']] = $entry;
        }
        $ratings = $this->scheduledWorkouts->ratingsFor($ids);
        $withActivity = array_flip($this->importedActivities->scheduledIdsWithActivity($ids));

        $rows = [];
        foreach ($sessions as $scheduled) {
            $id = (int) $scheduled->getId();
            $status = $scheduled->getStatus();

            if (isset($logged[$id])) {
                $rows[] = $logged[$id] + ['note' => null];

                continue;
            }

            $rows[] = [
                'id' => $id,
                'date' => $scheduled->getScheduledDate(),
                'title' => $scheduled->getDisplayTitle(),
                'status' => $status,
                'workingSets' => 0,
                'tonnageKg' => 0.0,
                'durationSeconds' => null,
                'averageRpe' => null,
                'exercises' => 0,
                'skipped' => 0,
                'rating' => $ratings[$id] ?? null,
                'note' => match (true) {
                    ScheduledStatus::DONE === $status && !isset($withActivity[$id]) => self::NOTE_BARE,
                    ScheduledStatus::PLANNED === $status => self::NOTE_UNCHECKED,
                    default => null,
                },
            ];
        }

        return $rows;
    }

    /** Premier jour de la fenêtre : `WEEKS` semaines pleines, aujourd'hui compris. */
    public static function since(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify(sprintf('-%d days', self::WEEKS * 7 - 1));
    }

    /**
     * Une séance est échue si sa date est passée, ou si elle est d'aujourd'hui et
     * déjà cochée (faite ou manquée). Le pendant exact sert à l'agenda des
     * prochaines semaines, qui garde le reste.
     */
    public static function isPast(ScheduledWorkout $scheduled, \DateTimeImmutable $today): bool
    {
        $day = $scheduled->getScheduledDate()->format('Y-m-d');
        $todayKey = $today->format('Y-m-d');

        return $day < $todayKey
            || ($day === $todayKey && ScheduledStatus::PLANNED !== $scheduled->getStatus());
    }
}
