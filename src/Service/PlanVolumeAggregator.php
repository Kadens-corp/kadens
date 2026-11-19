<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Enum\ActivityType;
use App\Enum\TargetArea;

/**
 * Agrège la charge d'une trame PAR SEMAINE et PAR ACTIVITÉ, pour équilibrer d'un
 * coup d'œil : salle = séries par groupe musculaire (+ tonnage), course/vélo/
 * natation = distance ou durée. S'appuie sur WorkoutMetrics (volume par séance)
 * et UnitFormatter (mise en forme lisible). Consommé par l'éditeur de trame.
 *
 * L'endurance se chiffre comme sur la rampe du plan, par la même règle
 * (`EnduranceMeasure`, #122) : une semaine de l'éditeur et la même semaine de la
 * vue du plan disent le même nombre, dans la même unité. Une semaine qui ne se
 * chiffre pas du tout dans l'unité du plan retombe sur l'autre plutôt que de
 * taire l'activité.
 *
 * @phpstan-import-type EnduranceVolume from WorkoutMetrics
 *
 * @phpstan-type VolumeChip array{activity: ActivityType, label: string, value: string}
 * @phpstan-type GymArea array{label: string, sets: int}
 * @phpstan-type WeekVolume array{sessions: int, totalMinutes: int, totalTime: string, chips: list<VolumeChip>, gymAreas: list<GymArea>, tonnage: ?string}
 */
final class PlanVolumeAggregator
{
    /** Libellés courts par activité (le libellé long de l'enum est trop verbeux en chip). */
    private const SHORT_LABELS = [
        'gym' => 'Salle',
        'running' => 'Course',
        'cycling' => 'Vélo',
        'swimming' => 'Natation',
    ];

    public function __construct(
        private readonly WorkoutMetrics $metrics,
        private readonly UnitFormatter $units,
        private readonly EnduranceMeasure $endurance,
    ) {
    }

    /**
     * @return array<int, WeekVolume> indexé par numéro de semaine (1..durationWeeks)
     */
    public function byWeek(PlanTemplate $template): array
    {
        $durationWeeks = (int) $template->getDurationWeeks();

        // Accumulateurs bruts par semaine.
        $raw = [];
        for ($w = 1; $w <= $durationWeeks; ++$w) {
            $raw[$w] = [
                'sessions' => 0,
                'totalMinutes' => 0,
                'gymSets' => 0,
                'tonnage' => 0.0,
                'byArea' => [],
                'endurance' => [
                    'running' => EnduranceMeasure::EMPTY,
                    'cycling' => EnduranceMeasure::EMPTY,
                    'swimming' => EnduranceMeasure::EMPTY,
                ],
            ];
        }

        foreach ($template->getPlanItems() as $item) {
            $week = (int) $item->getWeekNumber();
            $workout = $item->getWorkout();
            if (!isset($raw[$week]) || null === $workout) {
                continue;
            }
            $vol = $this->metrics->volume($workout);

            ++$raw[$week]['sessions'];
            $raw[$week]['totalMinutes'] += (int) ($workout->getEstimatedDurationMinutes() ?? 0);
            $raw[$week]['gymSets'] += $vol['gym']['totalSets'];
            $raw[$week]['tonnage'] += $vol['gym']['tonnageKg'];
            foreach ($vol['gym']['setsByArea'] as $area => $sets) {
                $raw[$week]['byArea'][$area] = ($raw[$week]['byArea'][$area] ?? 0) + $sets;
            }
            foreach (['running', 'cycling', 'swimming'] as $key) {
                $raw[$week]['endurance'][$key] = $this->endurance->add($raw[$week]['endurance'][$key], $vol[$key]);
            }
        }

        // L'unité d'une activité se choisit sur le plan entier, comme la rampe.
        $byDistance = [];
        foreach (['running', 'cycling', 'swimming'] as $key) {
            $byDistance[$key] = $this->endurance->prefersDistance(array_map(
                static fn (array $data): array => $data['endurance'][$key],
                $raw,
            ));
        }

        $weeks = [];
        foreach ($raw as $week => $data) {
            $weeks[$week] = $this->finalize($data, $byDistance);
        }

        return $weeks;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, bool>  $byDistance activité => chiffrée en distance sur ce plan
     *
     * @return WeekVolume
     */
    private function finalize(array $data, array $byDistance): array
    {
        $chips = [];

        if ($data['gymSets'] > 0) {
            $chips[] = [
                'activity' => ActivityType::GYM,
                'label' => self::SHORT_LABELS['gym'],
                'value' => sprintf('%d série%s', $data['gymSets'], $data['gymSets'] > 1 ? 's' : ''),
            ];
        }

        $enduranceActivities = [
            'running' => ActivityType::RUNNING,
            'cycling' => ActivityType::CYCLING,
            'swimming' => ActivityType::SWIMMING,
        ];
        foreach ($enduranceActivities as $key => $activity) {
            $value = $this->enduranceValue($data['endurance'][$key], $byDistance[$key]);
            if (null === $value) {
                continue;
            }
            $chips[] = [
                'activity' => $activity,
                'label' => self::SHORT_LABELS[$key],
                'value' => $value,
            ];
        }

        // Séries par groupe musculaire, triées du plus au moins sollicité.
        arsort($data['byArea']);
        $gymAreas = [];
        foreach ($data['byArea'] as $areaValue => $sets) {
            $area = TargetArea::tryFrom((string) $areaValue);
            $gymAreas[] = [
                'label' => null !== $area ? $area->getLabel() : (string) $areaValue,
                'sets' => $sets,
            ];
        }

        return [
            'sessions' => $data['sessions'],
            'totalMinutes' => $data['totalMinutes'],
            'totalTime' => $this->humanMinutes($data['totalMinutes']),
            'chips' => $chips,
            'gymAreas' => $gymAreas,
            'tonnage' => $data['tonnage'] > 0 ? $this->units->tonnage($data['tonnage']) : null,
        ];
    }

    /**
     * @param EnduranceVolume $week
     */
    private function enduranceValue(array $week, bool $distance): ?string
    {
        foreach ([$distance, !$distance] as $unit) {
            $measure = $this->endurance->planned($week, $unit);
            if ($measure['value'] > 0.0) {
                return ($measure['estimated'] ? '≈ ' : '').$this->endurance->format($measure['value'], $unit);
            }
        }

        return null;
    }

    private function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 0 === $rest ? $hours . ' h' : sprintf('%dh%02d', $hours, $rest);
    }
}
