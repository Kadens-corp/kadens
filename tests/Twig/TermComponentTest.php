<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Enum\SetType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Le compte des séries met l'échauffement à part (#102).
 */
final class TermComponentTest extends KernelTestCase
{
    public function testTheSetCountPutsTheWarmUpApart(): void
    {
        $count = fn (array $types): string => trim($this->render(
            "{% import 'components/_set_type.html.twig' as settype %}{{ settype.count(types) }}",
            ['types' => $types],
        ));

        self::assertSame('1 échauf. + 3 séries', $count([SetType::WARMUP, SetType::NORMAL, SetType::NORMAL, SetType::DROP_SET]));
        self::assertSame('3 séries', $count([SetType::NORMAL, SetType::NORMAL, SetType::NORMAL]));
        self::assertSame('1 série', $count([SetType::NORMAL]));
        self::assertSame('2 échauf.', $count([SetType::WARMUP, SetType::WARMUP]));
    }

    /** @param array<string, mixed> $context */
    private function render(string $source, array $context = []): string
    {
        self::bootKernel();
        return self::getContainer()->get(Environment::class)->createTemplate($source)->render($context);
    }
}
