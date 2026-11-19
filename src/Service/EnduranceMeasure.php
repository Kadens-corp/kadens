<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Comment une activité d'endurance se chiffre sur les semaines d'un plan : la
 * règle unique que la semaine de l'éditeur de trame (`PlanVolumeAggregator`) et
 * la rampe du plan (`ProgressionAggregator`) appliquent toutes les deux (#122).
 * Avant, chacune avait la sienne : la semaine ne comptait que la distance saisie
 * (le 2,4 km d'un échauffement « 15 min à 6:15/km » n'y entrait pas), la rampe
 * additionnait des durées sans les récupérations d'un fractionné.
 *
 * - **L'unité** est celle qui chiffre le plus de semaines : distance (saisie, ou
 *   déduite de durée × allure) ou durée. À égalité, la distance, l'unité dans
 *   laquelle une sortie se raconte. Elle se choisit sur le plan entier, pas
 *   semaine par semaine, sinon deux semaines voisines ne se compareraient pas.
 * - **La distance** est la saisie plus la déduite. **La durée** est le temps
 *   passé, récupérations comprises (`estimatedSeconds`, écrit par
 *   `WorkoutEstimator`) : celui que la séance annonce.
 * - **« ≈ »** dès qu'une part vient d'une allure ; **`missing`** compte les
 *   efforts qui ne se chiffrent pas dans l'unité retenue, même par déduction.
 *
 * @phpstan-import-type EnduranceVolume from WorkoutMetrics
 *
 * @phpstan-type PlannedMeasure array{value: float, estimated: bool, missing: int}
 */
final class EnduranceMeasure
{
    /** Le volume d'endurance nul, base des cumuls par semaine. */
    public const EMPTY = ['meters' => 0, 'seconds' => 0, 'derivedMeters' => 0, 'derivedSeconds' => 0, 'estimatedSeconds' => 0, 'efforts' => 0, 'withoutDistance' => 0, 'withoutDuration' => 0];

    public function __construct(private readonly UnitFormatter $units)
    {
    }

    /**
     * Ajoute le volume d'une séance au cumul d'une semaine, champ par champ.
     *
     * @param EnduranceVolume $week
     * @param EnduranceVolume $volume
     *
     * @return EnduranceVolume
     */
    public function add(array $week, array $volume): array
    {
        foreach (self::EMPTY as $field => $_) {
            $week[$field] += $volume[$field];
        }

        return $week;
    }

    /**
     * @param iterable<EnduranceVolume> $weeks
     *
     * @return bool true = en distance, false = en durée
     */
    public function prefersDistance(iterable $weeks): bool
    {
        $byDistance = 0;
        $byDuration = 0;
        foreach ($weeks as $week) {
            if ($week['efforts'] > 0) {
                $byDistance += 0 === $week['withoutDistance'] ? 1 : 0;
                $byDuration += 0 === $week['withoutDuration'] ? 1 : 0;
            }
        }

        return $byDistance >= $byDuration;
    }

    /**
     * @param EnduranceVolume $week
     *
     * @return PlannedMeasure
     */
    public function planned(array $week, bool $distance): array
    {
        if ($distance) {
            return [
                'value' => (float) ($week['meters'] + $week['derivedMeters']),
                'estimated' => $week['derivedMeters'] > 0,
                'missing' => $week['withoutDistance'],
            ];
        }

        return [
            'value' => (float) $week['estimatedSeconds'],
            'estimated' => $week['derivedSeconds'] > 0,
            'missing' => $week['withoutDuration'],
        ];
    }

    /**
     * Valeur lisible dans l'unité retenue, sans le « ≈ » (que l'appelant pose
     * selon `estimated`). La durée s'arrondit à la minute supérieure, comme
     * `WorkoutEstimator::estimateMinutes()` : une semaine de deux séances de
     * 44 min se lit 1h28 des deux côtés.
     */
    public function format(float $value, bool $distance): string
    {
        if ($distance) {
            if ($value < 1000) {
                return $this->units->distance((int) round($value));
            }

            return rtrim(rtrim(number_format($value / 1000, 1, ',', ' '), '0'), ',').' km';
        }

        $minutes = (int) ceil($value / 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $rest = $minutes % 60;

        return 0 === $rest ? intdiv($minutes, 60).' h' : sprintf('%dh%02d', intdiv($minutes, 60), $rest);
    }
}
