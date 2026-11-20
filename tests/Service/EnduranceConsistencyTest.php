<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Service\BodyLoad;
use App\Service\BodyPlates;
use App\Service\EnduranceMeasure;
use App\Service\LogMetrics;
use App\Service\PlanVolumeAggregator;
use App\Service\ProgressionAggregator;
use App\Service\RegionBreakdown;
use App\Service\SupersetGrouper;
use App\Service\UnitFormatter;
use App\Service\VolumePanel;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Une séance de course, trois lectures, un seul chiffre (#122) : la séance
 * (`WorkoutEstimator`, `VolumePanel`), la semaine de l'éditeur de trame
 * (`PlanVolumeAggregator`) et la rampe du plan (`ProgressionAggregator`).
 *
 * Le cas est celui de l'audit : un fractionné qui mélange une durée avec allure
 * (échauffement 15 min à 6:15/km), une distance avec allure et récupérations
 * (6 × 400 m à 4:00/km, récup 90 s) et une durée sans allure (retour au calme
 * 10 min). Avant, la séance disait 44 min, la semaine de deux séances 4,8 km (la
 * distance saisie seule) et la rampe 1h09 (les récupérations oubliées).
 */
final class EnduranceConsistencyTest extends TestCase
{
    private WorkoutEstimator $estimator;
    private WorkoutMetrics $metrics;
    private PlanVolumeAggregator $week;
    private ProgressionAggregator $ramp;

    protected function setUp(): void
    {
        $supersets = new SupersetGrouper();
        $units = new UnitFormatter();
        $regions = new RegionBreakdown();
        $measure = new EnduranceMeasure($units);

        $this->estimator = new WorkoutEstimator($supersets);
        $this->metrics = new WorkoutMetrics($this->estimator, $supersets, $regions);
        $this->week = new PlanVolumeAggregator($this->metrics, $units, $measure);
        $this->ramp = new ProgressionAggregator($units, $this->metrics, new LogMetrics($regions), $measure);
    }

    public function testTimeSpentOnTheRunIsTheSessionDuration(): void
    {
        $workout = $this->intervals(cooldownPace: null);

        // 15 min + 6 × (1 min 36 + 1 min 30) + 10 min = 43 min 36 → 44 min.
        self::assertSame(2616, $this->estimator->estimateSeconds($workout));
        self::assertSame(44, $this->estimator->estimateMinutes($workout));

        $running = $this->metrics->volume($workout)['running'];
        self::assertSame(2616, $running['estimatedSeconds']);
        // Le temps en mouvement reste celui d'une allure : sans les récupérations.
        self::assertSame(900 + 600, $running['seconds']);
        self::assertSame(6 * 96, $running['derivedSeconds']);
    }

    public function testSessionPanelSaysTheSessionDuration(): void
    {
        $panel = new VolumePanel($this->metrics, new BodyLoad(), new BodyPlates(\dirname(__DIR__, 2)), new UnitFormatter(), new EnduranceMeasure(new UnitFormatter()));

        $line = $panel->for($this->intervals(cooldownPace: null), null)['endurance'][0];

        self::assertSame('4,8 km', $line['distanceLabel']);
        // Et non plus « 25:00 », les seules durées saisies.
        self::assertSame('≈ 44 min', $line['durationLabel']);
    }

    public function testWeekAndRampAgreeOnDurationWhenADistanceIsMissing(): void
    {
        // Le retour au calme sans allure n'a pas de distance : le plan se lit en durée.
        $plan = $this->plan([[1, $this->intervals(cooldownPace: null)], [1, $this->intervals(cooldownPace: null)]]);

        $week = $this->week->byWeek($plan)[1];
        self::assertSame('~1h28', '~'.$week['totalTime']);
        self::assertSame(['Course · ≈ 1h28'], $this->chips($week));

        $series = $this->runningSeries($plan);
        self::assertSame('en durée', $series['unitLabel']);
        self::assertSame('≈ 1h28', $series['points'][0]['label']);
        // En durée, chaque effort se chiffre : la semaine est complète.
        self::assertSame(0, $series['points'][0]['missing']);
    }

    public function testWeekAndRampAgreeOnDistanceWhenEveryEffortHasOne(): void
    {
        // Retour au calme à 6:15/km : 1,6 km déduits, la distance est complète.
        $plan = $this->plan([[1, $this->intervals(cooldownPace: 375)], [1, $this->intervals(cooldownPace: 375)]]);

        // 2 × (2,4 + 2,4 + 1,6) km, dont une part déduite de l'allure.
        self::assertSame(['Course · ≈ 12,8 km'], $this->chips($this->week->byWeek($plan)[1]));

        $series = $this->runningSeries($plan);
        self::assertSame('en distance', $series['unitLabel']);
        self::assertSame('≈ 12,8 km', $series['points'][0]['label']);
        self::assertSame(0, $series['points'][0]['missing']);
    }

    public function testAWeekOutsideThePlanUnitFallsBackInsteadOfHidingTheRun(): void
    {
        // S1 et S2 : « 10 km » sans allure, chiffrés en distance seulement. S3 :
        // un footing de 40 min sans allure, en durée seulement. Le plan se lit en
        // distance ; la rampe montre en S3 un trou signalé, la semaine garde sa durée.
        $long = fn (): Workout => $this->workout([[PrescriptionType::DISTANCE_PACE, ['distanceMeters' => 10000]]]);
        $footing = $this->workout([[PrescriptionType::DURATION, ['durationSeconds' => 2400]]]);
        $plan = $this->plan([[1, $long()], [2, $long()], [3, $footing]], weeks: 3);

        self::assertSame(['Course · 10 km'], $this->chips($this->week->byWeek($plan)[1]));
        self::assertSame(['Course · 40 min'], $this->chips($this->week->byWeek($plan)[3]));

        $series = $this->runningSeries($plan);
        self::assertSame('en distance', $series['unitLabel']);
        self::assertSame('—', $series['points'][2]['label']);
        self::assertSame(1, $series['points'][2]['missing']);
    }

    private function intervals(?int $cooldownPace): Workout
    {
        $cooldown = ['durationSeconds' => 600];
        if (null !== $cooldownPace) {
            $cooldown['paceSecondsPerKm'] = $cooldownPace;
        }

        return $this->workout(
            [[PrescriptionType::DURATION, ['durationSeconds' => 900, 'paceSecondsPerKm' => 375]]],
            [[PrescriptionType::DISTANCE_PACE, ['sets' => 6, 'distanceMeters' => 400, 'paceSecondsPerKm' => 240, 'restSeconds' => 90]]],
            [[PrescriptionType::DURATION, $cooldown]],
        );
    }

    /**
     * Une séance de course, un bloc par argument, dans l'ordre.
     *
     * @param list<array{0: PrescriptionType, 1: array<string, int>}> ...$blocks
     */
    private function workout(array ...$blocks): Workout
    {
        $run = (new Exercise())->setName('Course')->setActivity(ActivityType::RUNNING);
        $workout = (new Workout())->setTitle('Séance')->setSlug('seance-'.uniqid());

        foreach (array_values($blocks) as $position => $efforts) {
            $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition($position);
            foreach ($efforts as $index => [$type, $params]) {
                $pe = (new PrescribedExercise())->setPrescriptionType($type)->setExercise($run)->setPosition($index);
                foreach ($params as $setter => $value) {
                    $pe->{'set'.ucfirst($setter)}($value);
                }
                $block->addPrescribedExercise($pe);
            }
            $workout->addBlock($block);
        }

        // Ce que le compositeur stocke à chaque mutation : la durée de l'en-tête.
        return $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
    }

    /**
     * @param list<array{0: int, 1: Workout}> $items semaine => séance
     */
    private function plan(array $items, int $weeks = 1): PlanTemplate
    {
        $template = (new PlanTemplate())->setTitle('Plan')->setDurationWeeks($weeks);
        foreach ($items as $day => [$week, $workout]) {
            $template->addPlanItem((new PlanItem())->setWeekNumber($week)->setDayOfWeek($day % 7 + 1)->setWorkout($workout));
        }

        return $template;
    }

    /**
     * @param array{chips: list<array{label: string, value: string}>} $week
     *
     * @return list<string>
     */
    private function chips(array $week): array
    {
        return array_map(static fn (array $chip): string => $chip['label'].' · '.$chip['value'], $week['chips']);
    }

    /**
     * @return array{unitLabel: string|null, points: list<array{label: string, missing: int}>}
     */
    private function runningSeries(PlanTemplate $plan): array
    {
        foreach ($this->ramp->weeklyVolume($plan) as $series) {
            if ('running' === $series['key']) {
                return $series;
            }
        }

        self::fail('Série « running » absente de la rampe du plan.');
    }
}
