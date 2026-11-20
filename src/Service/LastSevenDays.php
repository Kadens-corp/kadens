<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\BodySide;
use App\Enum\ScheduledStatus;
use App\Repository\ExerciseRepository;
use App\Repository\LoggedSetRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Le coup d'œil de l'accueil sur les sept derniers jours (#131) : combien de
 * séances faites, par activité, et les muscles travaillés sur une silhouette.
 *
 * **Aucune règle propre, seulement une fenêtre.** Chaque chiffre vient de la
 * source qui fait autorité sur lui, comme dans `TrainingStats` :
 *
 * - les séances se comptent sur le **statut** et se rangent sous leur **nature**
 *   (`SessionNature`), une séance = une activité. C'est la carte « par activité »
 *   du mois, sur une autre fenêtre : la somme des lignes vaut le nombre de
 *   séances faites ;
 * - les muscles se lisent sur le **réalisé** (`LoggedSet`, périmètre de
 *   `LoggedSetRepository::workingSetWindow()` : échauffement, exercice sauté et
 *   série sans valeur dehors) et se peignent par `BodyLoad`, le dessin et la
 *   légende du bandeau de volume d'une séance. Une séance cochée faite sans
 *   réalisé compte donc en séances et ne colore rien, et c'est exact (CLAUDE.md
 *   §3 : on ne comble pas avec le prescrit).
 *
 * La fenêtre est **glissante et en jours pleins** : aujourd'hui et les six jours
 * d'avant. Une semaine calendaire serait vide chaque lundi matin, ce qui est
 * précisément le moment où l'on veut voir ce qu'on vient de faire. C'est aussi
 * la fenêtre du jumeau mobile (`kadens-mobile/src/session/week.ts`), qui la
 * calcule sur sa base locale.
 *
 * **Coût.** Quatre lectures scalaires (statuts, natures en trois projections
 * dans `SessionNature`, séries par exercice) et une hydratation, celle des
 * exercices réellement travaillés dans la semaine, pour leurs zones.
 *
 * @phpstan-import-type ActivityTally from SessionNature
 * @phpstan-import-type BodyLoadResult from BodyLoad
 *
 * @phpstan-type LastSevenDaysData array{start: \DateTimeImmutable, end: \DateTimeImmutable, done: int, activities: list<ActivityTally>, load: BodyLoadResult, plates: list<array<string, mixed>>}
 */
final class LastSevenDays
{
    /** Aujourd'hui compris : J-6 → J. */
    public const DAYS = 7;

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly LoggedSetRepository $loggedSets,
        private readonly ExerciseRepository $exercises,
        private readonly SessionNature $sessionNature,
        private readonly BodyLoad $bodyLoad,
        private readonly BodyPlates $bodyPlates,
    ) {
    }

    /**
     * @param User|null $viewer celui qui regarde, pour sa silhouette : un réglage
     *                          d'affichage, comme dans `VolumePanel`
     *
     * @return LastSevenDaysData
     */
    public function for(User $owner, ?User $viewer = null, ?\DateTimeImmutable $now = null): array
    {
        $end = ($now ?? new \DateTimeImmutable())->setTime(23, 59, 59);
        $start = $end->modify(sprintf('-%d days', self::DAYS - 1))->setTime(0, 0);

        $done = $this->scheduled->countByStatusForOwnerIn($owner, $start, $end)[ScheduledStatus::DONE->value] ?? 0;

        // Une séance faite sans nature (restée vide) compte quand même, sous
        // « sans contenu » : la somme de la répartition doit valoir `done`.
        $natures = array_values($this->sessionNature->dominantByScheduled($owner, $start, $end));
        $natures = array_merge($natures, array_fill(0, max(0, $done - \count($natures)), null));

        [$setsByArea, $unmapped] = $this->setsByArea($owner, $start, $end);
        $silhouette = ($viewer ?? $owner)->getBodySilhouette();

        return [
            'start' => $start,
            'end' => $end,
            'done' => $done,
            'activities' => SessionNature::tally($natures),
            'load' => $this->bodyLoad->for($setsByArea, $unmapped),
            'plates' => [
                $this->bodyPlates->plate($silhouette, BodySide::FRONT),
                $this->bodyPlates->plate($silhouette, BodySide::BACK),
            ],
        ];
    }

    /**
     * Les séries de travail réalisées de la fenêtre, attribuées à chaque zone de
     * leur exercice (règle de `RegionBreakdown` : une série compte pour chaque
     * zone), et celles qu'aucune zone ne reçoit.
     *
     * Les zones vivent sur la définition en bibliothèque : un exercice supprimé
     * depuis (`exerciseId` null) ou sans zone déclarée n'a personne à colorier.
     * Ses séries sont dites en légende plutôt que perdues.
     *
     * @return array{0: array<string, int>, 1: int}
     */
    private function setsByArea(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->loggedSets->gymTotalsByExerciseForOwner($owner, $start, $end);

        $ids = array_values(array_unique(array_filter(
            array_column($rows, 'exerciseId'),
            static fn (?int $id): bool => null !== $id,
        )));

        $areasById = [];
        if ([] !== $ids) {
            foreach ($this->exercises->findBy(['id' => $ids]) as $exercise) {
                $areasById[(int) $exercise->getId()] = $exercise->getTargetAreas() ?? [];
            }
        }

        $setsByArea = [];
        $unmapped = 0;
        foreach ($rows as $row) {
            $areas = null === $row['exerciseId'] ? [] : ($areasById[$row['exerciseId']] ?? []);

            if ([] === $areas) {
                $unmapped += $row['workingSets'];

                continue;
            }

            foreach ($areas as $area) {
                $setsByArea[$area->value] = ($setsByArea[$area->value] ?? 0) + $row['workingSets'];
            }
        }

        return [$setsByArea, $unmapped];
    }
}
