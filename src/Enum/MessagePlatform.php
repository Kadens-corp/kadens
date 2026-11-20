<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Où s'affiche un message programmé : le site, l'appli, ou les deux. Le « vu »
 * reste commun : un message affiché des deux côtés et fermé sur l'un disparaît
 * de l'autre.
 */
enum MessagePlatform: string
{
    case BOTH = 'both';
    case WEB = 'web';
    case APP = 'app';

    public function getLabel(): string
    {
        return match ($this) {
            self::BOTH => 'Site et appli',
            self::WEB => 'Site seulement',
            self::APP => 'Appli seulement',
        };
    }

    /**
     * Les réglages qui font apparaître un message sur cette surface. Une
     * surface est `WEB` ou `APP` : `BOTH` n'est pas un endroit où l'on lit.
     *
     * @return non-empty-list<self>
     */
    public static function shownOn(self $surface): array
    {
        if (self::BOTH === $surface) {
            throw new \InvalidArgumentException('Une surface de lecture est WEB ou APP.');
        }

        return [self::BOTH, $surface];
    }
}
