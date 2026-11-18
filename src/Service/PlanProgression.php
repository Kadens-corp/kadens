<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;
use App\Enum\ProgressionField;
use App\Http\ProgressionInput;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Faire progresser un exercice sur tout un plan en un geste (#110) : « +2,5 kg
 * par semaine au développé militaire d'Upper A, de S1 à S4 », au lieu d'ouvrir
 * chaque case une à une.
 *
 * **Portée : une lignée et un exercice.** Les cases visées sont les copies locales
 * (`planLocal`) dont la racine (`origin`) est la séance choisie : « Upper A » de
 * S1 à S4, même renommée. Le même exercice dans « Upper B » n'est pas touché, il
 * a sa propre progression. Modifier la copie locale suffit pour le calendrier :
 * les séances datées issues du plan la référencent.
 *
 * **Règle de calcul : une rampe, pas un cumul.** La semaine `from` vise `start`
 * (par défaut la valeur que sa case prescrit déjà), chaque semaine suivante un pas
 * de plus : S(from + k) vise `start + k × pas`. L'outil est donc idempotent :
 * l'appliquer deux fois donne le même plan, et le refaire à 5 kg au lieu de 2,5
 * remplace la rampe au lieu de s'y ajouter. Deux poses de la même séance dans une
 * semaine visent la même valeur.
 *
 * **Ce qui bouge dans une case : un décalage, pas un écrasement.** La valeur
 * repère de la case est la plus haute de ses séries de travail (la « série la
 * plus lourde » en charge, #106), toutes occurrences de l'exercice confondues.
 * Toutes ses séries de travail sont décalées de l'écart entre la valeur visée et
 * ce repère : une pyramide 60/70/80 qui doit culminer à 82,5 devient 62,5/72,5/
 * 82,5, une série de dégressif garde son écart. L'échauffement ne bouge pas (il
 * ne compte pas comme travail, cf. `SetType::countsAsWorking()`), le nombre de
 * séries non plus : `SetSynchronizer` n'a rien à ajouter ni retirer, il est
 * seulement rappelé pour garder le scalaire d'accord. Une série sans valeur (au
 * poids du corps) le reste, sauf si aucune n'en a : elles reçoivent alors toutes
 * la valeur visée.
 *
 * **Ce qui ne bouge jamais : une case déjà faite.** Son prescrit est celui que
 * l'athlète a suivi, et l'analyse prévu/réalisé (`LogComparator`) se lit contre
 * lui. L'aperçu la montre, avec sa date, et l'application la saute.
 */
final class PlanProgression
{
    public const string CHANGE = 'change';
    public const string SAME = 'same';
    public const string ABSENT = 'absent';
    public const string DONE = 'done';

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduledRepository,
        private readonly SetSynchronizer $setSynchronizer,
        private readonly WorkoutEstimator $estimator,
    ) {
    }

    /**
     * Ce qu'on peut faire progresser dans ce plan : chaque séance posée (par
     * racine de lignée, dans l'ordre où elle apparaît) et ses exercices de force,
     * avec les valeurs qu'ils portent et les semaines où ils apparaissent.
     *
     * @return list<array{root: Workout, exercises: list<array{exercise: Exercise, key: string, fields: list<ProgressionField>, defaultField: ProgressionField, firstWeek: int, lastWeek: int}>}>
     */
    public function targets(PlanTemplate $template): array
    {
        /** @var array<int, array{root: Workout, exercises: array<int, array{exercise: Exercise, key: string, fields: array<string, ProgressionField>, weighted: bool, firstWeek: int, lastWeek: int}>}> $byRoot */
        $byRoot = [];

        foreach ($this->sortedItems($template) as $item) {
            $workout = $item->getWorkout();
            $root = $workout?->getOrigin();
            $rootId = $root?->getId();
            if (null === $workout || null === $root || null === $rootId || !$workout->isPlanLocal()) {
                continue;
            }
            $week = (int) $item->getWeekNumber();
            $byRoot[$rootId] ??= ['root' => $root, 'exercises' => []];

            foreach ($this->prescribedIn($workout) as $prescribed) {
                $exercise = $prescribed->getExercise();
                $exerciseId = $exercise?->getId();
                if (null === $exercise || null === $exerciseId) {
                    continue;
                }
                $fields = array_filter(ProgressionField::cases(), static fn (ProgressionField $f): bool => $f->appliesTo($prescribed->getPrescriptionType()));
                if ([] === $fields) {
                    continue;
                }

                $entry = $byRoot[$rootId]['exercises'][$exerciseId] ?? [
                    'exercise' => $exercise,
                    'key' => ProgressionInput::targetKey($rootId, $exerciseId),
                    'fields' => [],
                    'weighted' => false,
                    'firstWeek' => $week,
                    'lastWeek' => $week,
                ];
                foreach ($fields as $field) {
                    $entry['fields'][$field->value] = $field;
                }
                $entry['weighted'] = $entry['weighted'] || null !== self::reference($this->slots($prescribed, ProgressionField::WEIGHT), ProgressionField::WEIGHT);
                $entry['lastWeek'] = max($entry['lastWeek'], $week);
                $byRoot[$rootId]['exercises'][$exerciseId] = $entry;
            }
        }

        $targets = [];
        foreach ($byRoot as $lineage) {
            if ([] === $lineage['exercises']) {
                continue;
            }
            $exercises = [];
            foreach ($lineage['exercises'] as $entry) {
                $fields = array_values(array_filter(ProgressionField::cases(), static fn (ProgressionField $f): bool => isset($entry['fields'][$f->value])));
                if ([] === $fields) {
                    continue;
                }
                // Une traction au poids du corps progresse en répétitions : proposer
                // la charge d'abord, c'est proposer une valeur qu'elle n'a pas.
                $defaultField = !$entry['weighted'] && \in_array(ProgressionField::REPS, $fields, true) ? ProgressionField::REPS : $fields[0];
                $exercises[] = [
                    'exercise' => $entry['exercise'],
                    'key' => $entry['key'],
                    'fields' => $fields,
                    'defaultField' => $defaultField,
                    'firstWeek' => $entry['firstWeek'],
                    'lastWeek' => $entry['lastWeek'],
                ];
            }
            $targets[] = ['root' => $lineage['root'], 'exercises' => $exercises];
        }

        return $targets;
    }

    /**
     * Ce que l'application changerait, case par case, sans rien écrire.
     *
     * @return array{root: Workout, exercise: Exercise, start: float, rows: list<array{item: PlanItem, week: int, day: int, status: string, before: list<float|null>, after: list<float|null>, doneOn: \DateTimeImmutable|null, slots: list<PrescribedExercise|PrescribedSet>}>, changes: int}
     *
     * @throws \InvalidArgumentException si la cible n'est pas dans le plan ou que rien ne dit d'où partir
     */
    public function preview(PlanTemplate $template, ProgressionInput $input): array
    {
        $root = null;
        $exercise = null;
        $cells = [];
        foreach ($this->sortedItems($template) as $item) {
            $workout = $item->getWorkout();
            $origin = $workout?->getOrigin();
            $week = (int) $item->getWeekNumber();
            if (null === $workout || null === $origin || !$workout->isPlanLocal() || $origin->getId() !== $input->rootId) {
                continue;
            }
            $root ??= $origin;

            $slots = [];
            foreach ($this->prescribedIn($workout) as $prescribed) {
                if ($prescribed->getExercise()?->getId() === $input->exerciseId && $input->field->appliesTo($prescribed->getPrescriptionType())) {
                    $exercise ??= $prescribed->getExercise();
                    $slots = [...$slots, ...$this->slots($prescribed, $input->field)];
                }
            }

            if ($week >= $input->fromWeek && $week <= $input->toWeek) {
                $cells[] = ['item' => $item, 'workout' => $workout, 'week' => $week, 'slots' => $slots];
            }
        }

        if (null === $root || null === $exercise) {
            throw new \InvalidArgumentException('Cet exercice n\'est plus dans ce plan, ou n\'a pas cette valeur.');
        }
        $withExercise = array_values(array_filter($cells, static fn (array $c): bool => [] !== $c['slots']));
        if ([] === $withExercise) {
            throw new \InvalidArgumentException(\sprintf('Aucune case de la semaine %d à la semaine %d n\'a cet exercice.', $input->fromWeek, $input->toWeek));
        }

        $start = $input->start ?? self::reference($withExercise[0]['slots'], $input->field);
        if (null === $start) {
            throw new \InvalidArgumentException(ProgressionField::WEIGHT === $input->field
                ? 'Aucune charge n\'est encore prescrite pour cet exercice : indique celle de départ.'
                : 'Aucune répétition n\'est encore prescrite pour cet exercice : indique le départ.');
        }
        $start = self::clamp($input->field, $start);

        $doneOn = $this->scheduledRepository->lastDoneDateByWorkout(
            array_map(static fn (array $c): int => (int) $c['workout']->getId(), $cells),
        );

        $rows = [];
        $changes = 0;
        foreach ($cells as $cell) {
            $before = array_map(static fn (PrescribedExercise|PrescribedSet $s): ?float => self::valueOf($s, $input->field), $cell['slots']);
            $target = self::clamp($input->field, $start + ($cell['week'] - $input->fromWeek) * $input->step);
            $after = self::shifted($input->field, $before, $target);
            $done = $doneOn[(int) $cell['workout']->getId()] ?? null;

            $status = match (true) {
                [] === $cell['slots'] => self::ABSENT,
                null !== $done => self::DONE,
                $before === $after => self::SAME,
                default => self::CHANGE,
            };
            if (self::CHANGE === $status) {
                ++$changes;
            }

            $rows[] = [
                'item' => $cell['item'],
                'week' => $cell['week'],
                'day' => (int) $cell['item']->getDayOfWeek(),
                'status' => $status,
                'before' => $before,
                'after' => self::DONE === $status ? $before : $after,
                'doneOn' => $done,
                'slots' => $cell['slots'],
            ];
        }

        return ['root' => $root, 'exercise' => $exercise, 'start' => $start, 'rows' => $rows, 'changes' => $changes];
    }

    /**
     * Applique l'aperçu : écrit les valeurs des cases à changer, recalcule leur
     * durée estimée (les répétitions la font varier). Le flush reste à l'appelant.
     *
     * @return array{root: Workout, exercise: Exercise, start: float, changes: int}
     *
     * @throws \InvalidArgumentException comme `preview()`
     */
    public function apply(PlanTemplate $template, ProgressionInput $input): array
    {
        $preview = $this->preview($template, $input);

        foreach ($preview['rows'] as $row) {
            if (self::CHANGE !== $row['status']) {
                continue;
            }

            $touched = [];
            foreach ($row['slots'] as $index => $slot) {
                $this->write($slot, $input->field, $row['after'][$index]);
                if ($slot instanceof PrescribedSet && null !== $slot->getPrescribedExercise()) {
                    $touched[spl_object_id($slot->getPrescribedExercise())] = $slot->getPrescribedExercise();
                }
            }
            foreach ($touched as $prescribed) {
                $this->setSynchronizer->syncScalarFromDetailed($prescribed);
            }

            $workout = $row['item']->getWorkout();
            if (null !== $workout) {
                $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
            }
        }

        return ['root' => $preview['root'], 'exercise' => $preview['exercise'], 'start' => $preview['start'], 'changes' => $preview['changes']];
    }

    /**
     * Cases du plan dans l'ordre de lecture : semaine, jour, puis ordre de pose
     * (même ordre que `LineageDivergence`).
     *
     * @return list<PlanItem>
     */
    private function sortedItems(PlanTemplate $template): array
    {
        $keyed = [];
        foreach (array_values($template->getPlanItems()->toArray()) as $index => $item) {
            $keyed[] = ['item' => $item, 'key' => [(int) $item->getWeekNumber(), (int) $item->getDayOfWeek(), $index]];
        }
        usort($keyed, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_map(static fn (array $k): PlanItem => $k['item'], $keyed);
    }

    /**
     * Exercices prescrits d'une séance, blocs puis positions.
     *
     * @return list<PrescribedExercise>
     */
    private function prescribedIn(Workout $workout): array
    {
        $blocks = $workout->getBlocks()->toArray();
        usort($blocks, static fn (Block $a, Block $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));

        $all = [];
        foreach ($blocks as $block) {
            $exercises = $block->getPrescribedExercises()->toArray();
            usort($exercises, static fn (PrescribedExercise $a, PrescribedExercise $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));
            $all = [...$all, ...$exercises];
        }

        return $all;
    }

    /**
     * Les endroits où vit la valeur : les séries de travail en mode détaillé
     * (elles priment), le scalaire sinon.
     *
     * @return list<PrescribedExercise|PrescribedSet>
     */
    private function slots(PrescribedExercise $prescribed, ProgressionField $field): array
    {
        if (!$field->appliesTo($prescribed->getPrescriptionType())) {
            return [];
        }
        if (!$prescribed->hasDetailedSets()) {
            return [$prescribed];
        }

        $sets = array_values(array_filter(
            $prescribed->getDetailedSets()->toArray(),
            static fn (PrescribedSet $set): bool => $set->getSetType()->countsAsWorking(),
        ));
        usort($sets, static fn (PrescribedSet $a, PrescribedSet $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));

        return $sets;
    }

    /**
     * Valeur repère d'une case : la plus haute de ses séries de travail, null si
     * aucune n'en porte.
     *
     * @param list<PrescribedExercise|PrescribedSet> $slots
     */
    private static function reference(array $slots, ProgressionField $field): ?float
    {
        $top = null;
        foreach ($slots as $slot) {
            $value = self::valueOf($slot, $field);
            if (null !== $value) {
                $top = null === $top ? $value : max($top, $value);
            }
        }

        return $top;
    }

    /**
     * Décale toutes les valeurs pour que la plus haute atteigne `$target`.
     *
     * @param list<float|null> $values
     *
     * @return list<float|null>
     */
    private static function shifted(ProgressionField $field, array $values, float $target): array
    {
        $present = array_filter($values, static fn (?float $v): bool => null !== $v);
        if ([] === $present) {
            return array_map(static fn (): float => $target, $values);
        }

        $delta = $target - max($present);

        return array_map(static fn (?float $v): ?float => null === $v ? null : self::clamp($field, $v + $delta), $values);
    }

    /** Arrondi au centième de kilo ou à la répétition, jamais sous zéro (ni sous une répétition). */
    private static function clamp(ProgressionField $field, float $value): float
    {
        return ProgressionField::REPS === $field ? max(1.0, round($value)) : max(0.0, round($value, 2));
    }

    private static function valueOf(PrescribedExercise|PrescribedSet $slot, ProgressionField $field): ?float
    {
        if (ProgressionField::WEIGHT === $field) {
            return $slot->getWeightKg();
        }

        return null === $slot->getReps() ? null : (float) $slot->getReps();
    }

    private function write(PrescribedExercise|PrescribedSet $slot, ProgressionField $field, ?float $value): void
    {
        if (ProgressionField::WEIGHT === $field) {
            $slot->setWeightKg($value);

            return;
        }

        $slot->setReps(null === $value ? null : (int) $value);
    }
}
