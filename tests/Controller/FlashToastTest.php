<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Un message flash ne déplace jamais la page (#129) : il se rend en toast, dans
 * la pile fixe que pose `base.html.twig` (`.kd-toasts`).
 */
final class FlashToastTest extends TestCase
{
    /**
     * Garde-fou : `components/_flash_toasts` est le seul lecteur des flashes de
     * succès et d'erreur. Un template qui y bouclerait de nouveau consommerait le
     * message avant la pile, et le rendrait en tête de page.
     */
    public function testNoTemplateReadsTheFlashesItself(): void
    {
        $readers = [];
        $finder = (new Finder())->files()->in(\dirname(__DIR__, 2).'/templates')->name('*.twig');
        foreach ($finder as $file) {
            if (preg_match("/app\.flashes\(\s*(?:'(?:success|error)'\s*)?\)/", $file->getContents())) {
                $readers[] = $file->getRelativePathname();
            }
        }

        self::assertSame(['components/_flash_toasts.html.twig'], $readers);
    }
}
