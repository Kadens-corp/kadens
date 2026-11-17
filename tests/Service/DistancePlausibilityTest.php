<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Enum\ActivityType;
use App\Enum\PrescriptionType;
use App\Service\DistancePlausibility;
use App\Service\UnitFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Alerte « vérifie l'unité » sur une distance prescrite (#97) : le total
 * (distance × répétitions) au-delà d'un seuil par activité.
 */
final class DistancePlausibilityTest extends TestCase
{
    private DistancePlausibility $plausibility;

    protected function setUp(): void
    {
        $this->plausibility = new DistancePlausibility(new UnitFormatter());
    }

    /** Le cas de l'audit : 400 tapé avec « km » proposé, six répétitions. */
    public function testIntervalTypedInKilometersIsFlagged(): void
    {
        self::assertSame(
            '6 × 400 km, soit 2 400 km au total : vérifie l\'unité (m ou km).',
            $this->plausibility->warning($this->prescribed(ActivityType::RUNNING, 400_000, 6)),
        );
    }

    public function testSingleDistanceSaysTheValueAlone(): void
    {
        self::assertSame(
            '400 km : vérifie l\'unité (m ou km).',
            $this->plausibility->warning($this->prescribed(ActivityType::RUNNING, 400_000, null)),
        );
    }

    #[DataProvider('plausibleCases')]
    public function testPlausibleDistancesStaySilent(ActivityType $activity, int $meters, ?int $sets): void
    {
        self::assertNull($this->plausibility->warning($this->prescribed($activity, $meters, $sets)));
    }

    public static function plausibleCases(): \Generator
    {
        yield 'fractionné 6 × 400 m' => [ActivityType::RUNNING, 400, 6];
        yield 'marathon' => [ActivityType::RUNNING, 42_195, null];
        yield 'sortie vélo de 200 km' => [ActivityType::CYCLING, 200_000, null];
        yield 'natation 10 × 100 m' => [ActivityType::SWIMMING, 100, 10];
        yield 'marathon au rameur' => [ActivityType::OTHER, 42_195, null];
    }

    /** Le seuil suit l'activité : 50 km est plausible à vélo, pas à la nage. */
    public function testThresholdFollowsActivity(): void
    {
        self::assertNull($this->plausibility->warning($this->prescribed(ActivityType::CYCLING, 50_000, null)));
        self::assertNotNull($this->plausibility->warning($this->prescribed(ActivityType::SWIMMING, 50_000, null)));
    }

    /** Une distance hors du type d'effort sera effacée à l'enregistrement : rien à dire. */
    public function testDistanceOutsideThePrescriptionTypeIsIgnored(): void
    {
        $prescribed = $this->prescribed(ActivityType::RUNNING, 400_000, 6)
            ->setPrescriptionType(PrescriptionType::DURATION);

        self::assertNull($this->plausibility->warning($prescribed));
    }

    private function prescribed(ActivityType $activity, int $meters, ?int $sets): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise((new Exercise())->setName('Test')->setActivity($activity))
            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
            ->setDistanceMeters($meters)
            ->setSets($sets)
            ->setPosition(0);
    }
}
