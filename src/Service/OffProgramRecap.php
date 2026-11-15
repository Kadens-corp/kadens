<?php

namespace App\Service;

use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Ce que l'athlète a fait **hors programme** la dernière fois, au moment où l'on
 * programme la suite.
 *
 * L'analyse d'une séance datée montre déjà les exercices ajoutés en cours de
 * séance. Mais la pose « d'après le dernier réalisé » (`LineageSeed`) ne les
 * reporte pas — à dessein : un ajout ponctuel de l'athlète ne devient pas une
 * prescription sans que le coach le décide. Sans ce service, ils disparaissaient
 * donc de la vue au moment même où l'on prépare la semaine suivante.
 *
 * Ils sont désormais **signalés** là où l'on programme (carte de palette, édition
 * rapide d'une case, compositeur) et s'ajoutent d'un clic, valeurs reportées par
 * `LineageSeed::prescribeFromLog()`.
 *
 * Deux sources qui font autorité, aucune réécrite ici :
 * - « hors programme » = `LogComparator::offProgram()`, l'appariement même de
 *   l'analyse ;
 * - « la dernière fois » = la dernière séance faite de la LIGNÉE
 *   (`LineageSeed::latestDone()`), celle dont la pose repartirait.
 *
 * @phpstan-type OffProgramExtra array{logged: LoggedExercise, summary: string, addable: bool}
 * @phpstan-type OffProgram array{date: \DateTimeImmutable, scheduled: ScheduledWorkout, extras: non-empty-list<OffProgramExtra>}
 */
final class OffProgramRecap
{
    public function __construct(
        private readonly LineageSeed $lineageSeed,
        private readonly LogComparator $comparator,
        private readonly ScheduledWorkoutRepository $scheduledRepository,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * Les hors programme de la dernière fois, pour la séance qu'on édite. Null
     * s'il n'y a rien à dire : séance jamais faite, rien d'ajouté, ou tout déjà
     * repris (un exercice déjà présent dans `$workout` n'est plus à proposer).
     *
     * @return OffProgram|null
     */
    public function forWorkout(Workout $workout): ?array
    {
        // Une case de plan lit sa lignée par sa racine, comme la pose.
        $root = $workout->isPlanLocal() ? $workout->getOrigin() : $workout;
        if (null === $root) {
            return null;
        }

        $latest = $this->lineageSeed->latestDone($root);
        if (null === $latest) {
            return null;
        }

        $present = [];
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $present[(int) $prescribed->getExercise()?->getId()] = true;
            }
        }

        $extras = [];
        foreach ($this->comparator->offProgram($latest) as $logged) {
            $exercise = $logged->getExercise();
            if (null !== $exercise && isset($present[(int) $exercise->getId()])) {
                continue;
            }
            $extras[] = [
                'logged' => $logged,
                'summary' => $this->summary($logged),
                // Supprimé de la bibliothèque depuis : il n'en reste que le nom,
                // on le montre, on ne peut plus le prescrire.
                'addable' => null !== $exercise,
            ];
        }

        if ([] === $extras) {
            return null;
        }

        return [
            'date' => $latest->getScheduledDate(),
            'scheduled' => $latest,
            'extras' => $extras,
        ];
    }

    /**
     * Combien d'exercices hors programme la dernière fois, par racine de lignée :
     * le signal de la carte de palette. Les lignées sans rien à dire sont absentes.
     *
     * @return array<int, int> id de la séance de bibliothèque → nombre
     */
    public function countsByLineage(User $owner): array
    {
        $counts = [];
        foreach ($this->scheduledRepository->findLatestDoneByLineageWithLog($owner) as $root => $scheduled) {
            $count = \count($this->comparator->offProgram($scheduled));
            if ($count > 0) {
                $counts[$root] = $count;
            }
        }

        return $counts;
    }

    /**
     * « 3 × 12 · 15 kg » quand toutes les séries se ressemblent, « 3 séries ·
     * 12/10/8 · jusqu'à 20 kg » sinon. Seules les séries chiffrées comptent, comme
     * au report (`LineageSeed`) : une série cochée « ? » ne dit rien.
     */
    private function summary(LoggedExercise $logged): string
    {
        $sets = array_values(array_filter(
            $logged->getLoggedSets()->toArray(),
            static fn (LoggedSet $set): bool => ($set->getReps() ?? 0) > 0 || ($set->getDurationSeconds() ?? 0) > 0,
        ));
        usort($sets, static fn (LoggedSet $a, LoggedSet $b): int => $a->getPosition() <=> $b->getPosition());

        $count = \count($sets);
        if (0 === $count) {
            $done = $logged->getLoggedSets()->count();

            return sprintf('%d série%s sans valeur', $done, $done > 1 ? 's' : '');
        }

        $reps = array_values(array_filter(array_map(static fn (LoggedSet $s): ?int => $s->getReps(), $sets), static fn (?int $r): bool => ($r ?? 0) > 0));
        $durations = array_values(array_filter(array_map(static fn (LoggedSet $s): ?int => $s->getDurationSeconds(), $sets), static fn (?int $d): bool => ($d ?? 0) > 0));
        $weights = array_values(array_filter(array_map(static fn (LoggedSet $s): ?float => $s->getWeightKg(), $sets), static fn (?float $w): bool => ($w ?? 0.0) > 0.0));

        if ([] !== $reps) {
            $volume = 1 === \count(array_unique($reps))
                ? $count.' × '.$reps[0]
                : $count.' séries · '.implode('/', $reps);
        } else {
            $volume = 1 === \count(array_unique($durations))
                ? $count.' × '.$this->units->duration($durations[0])
                : $count.' séries · '.implode('/', array_map(fn (int $d): string => $this->units->duration($d), $durations));
        }

        if ([] === $weights) {
            return $volume;
        }

        return $volume.' · '.(1 === \count(array_unique($weights))
            ? $this->units->weight($weights[0])
            : 'jusqu\'à '.$this->units->weight(max($weights)));
    }
}
