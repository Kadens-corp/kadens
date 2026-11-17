<?php

namespace App\Tests\Enum;

use App\Enum\ActivityType;
use App\Enum\PaceUnit;
use PHPUnit\Framework\TestCase;

final class PaceUnitTest extends TestCase
{
    /** Rameur et ski-erg vivent dans « Autre » : leur allure se lit au 500 m (#97). */
    public function testOtherActivityUsesMinutesPer500m(): void
    {
        self::assertSame(PaceUnit::MIN_PER_500M, PaceUnit::forActivity(ActivityType::OTHER));
        self::assertSame(PaceUnit::MIN_PER_KM, PaceUnit::forActivity(ActivityType::RUNNING));
        self::assertSame(PaceUnit::MIN_PER_KM, PaceUnit::forActivity(null));
    }

    public function testMinutesPer500mRoundTrip(): void
    {
        // 2:00/500m = 240 s/km.
        self::assertSame(240, PaceUnit::MIN_PER_500M->toSecondsPerKm('2:00'));
        self::assertSame('2:00', PaceUnit::MIN_PER_500M->toInputValue(240));
        self::assertSame('1:55/500m', PaceUnit::MIN_PER_500M->format(230));
        self::assertSame('min/500m', PaceUnit::MIN_PER_500M->label());
    }

    public function testPlaceholdersAreMarkedAsExamples(): void
    {
        foreach (PaceUnit::cases() as $unit) {
            self::assertStringStartsWith('ex. ', $unit->placeholder());
        }
    }
}
