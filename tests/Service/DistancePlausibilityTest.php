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

    /** Le cas de l'audit : 400 tapé avec « km » proposé. */
    public function testIntervalTypedInKilometersIsFlagged(): void
    {
        self::assertSame(
            '400 km : vérifie l\'unité (m ou km).',
            $this->plausibility->warning($this->prescribed(ActivityType::RUNNING, 400_000)),
        );
    }

    public function testSingleDistanceSaysTheValueAlone(): void
    {
        self::assertSame(
            '400 km : vérifie l\'unité (m ou km).',
            $this->plausibility->warning($this->prescribed(ActivityType::RUNNING, 400_000)),
        );
    }

    #[DataProvider('plausibleCases')]
    public function testPlausibleDistancesStaySilent(ActivityType $activity, int $meters): void
    {
        self::assertNull($this->plausibility->warning($this->prescribed($activity, $meters)));
    }

    public static function plausibleCases(): \Generator
    {
        yield 'fractionné 400 m' => [ActivityType::RUNNING, 400];
        yield 'marathon' => [ActivityType::RUNNING, 42_195];
        yield 'sortie vélo de 200 km' => [ActivityType::CYCLING, 200_000];
        yield 'natation 100 m' => [ActivityType::SWIMMING, 100];
        yield 'marathon au rameur' => [ActivityType::OTHER, 42_195];
    }

    /** Le seuil suit l'activité : 50 km est plausible à vélo, pas à la nage. */
    public function testThresholdFollowsActivity(): void
    {
        self::assertNull($this->plausibility->warning($this->prescribed(ActivityType::CYCLING, 50_000)));
        self::assertNotNull($this->plausibility->warning($this->prescribed(ActivityType::SWIMMING, 50_000)));
    }

    /** Une distance hors du type d'effort sera effacée à l'enregistrement : rien à dire. */
    public function testDistanceOutsideThePrescriptionTypeIsIgnored(): void
    {
        $prescribed = $this->prescribed(ActivityType::RUNNING, 400_000)
            ->setPrescriptionType(PrescriptionType::DURATION);

        self::assertNull($this->plausibility->warning($prescribed));
    }

    private function prescribed(ActivityType $activity, int $meters): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise((new Exercise())->setName('Test')->setActivity($activity))
            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
            ->setDistanceMeters($meters)
            ->setPosition(0);
    }
}
