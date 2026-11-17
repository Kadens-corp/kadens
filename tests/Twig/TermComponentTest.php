<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Enum\SetType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Les sigles s'expliquent dans la page (#102) : l'infobulle porte son texte, et
 * le compte des séries met l'échauffement à part.
 */
final class TermComponentTest extends KernelTestCase
{
    public function testTheTooltipCarriesItsDefinitionInThePage(): void
    {
        $html = $this->render("{% import 'components/_term.html.twig' as terms %}{{ terms.abbr('RPE') }}");

        self::assertStringContainsString('<abbr class="kd-term__word" tabindex="0"', $html);
        self::assertStringContainsString('role="tooltip"', $html);
        self::assertStringContainsString('Effort ressenti, de 1', $html);
        // L'infobulle est bien celle que le sigle désigne.
        self::assertMatchesRegularExpression('/aria-describedby="(term-\d+)".*id="\1"/s', $html);
    }

    public function testTheHintNamesTheTermForScreenReaders(): void
    {
        $html = $this->render("{% import 'components/_term.html.twig' as terms %}{{ terms.hint('FTP') }}");

        self::assertStringContainsString('aria-label="Que veut dire FTP ?"', $html);
        self::assertStringContainsString('Puissance tenue environ une heure', $html);
    }

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
