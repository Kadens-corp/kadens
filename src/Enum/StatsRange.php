<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Fenêtre de lecture des statistiques d'entraînement.
 *
 * Six cas, parce qu'ils répondent à six questions différentes : « où j'en
 * suis là » (4 semaines), « est-ce que la saison tient » (6 mois), « où en
 * est mon année » (depuis le 1er janvier), « qu'est-ce que j'ai accumulé »
 * (tous temps), « ce mois-là précisément » (un mois choisi) et « cette
 * année-là » (une année passée, pour la comparer à la courante).
 *
 * MONTH et YEAR sont les deux cas paramétrés : leur valeur d'URL n'est pas
 * `month` ou `year` mais la période elle-même (`2026-07`, `2025`). L'enum dit le
 * *type* de fenêtre, StatsPeriod la résout en bornes de dates — c'est lui, et
 * lui seul, qui lit la chaîne brute.
 */
enum StatsRange: string
{
    case FOUR_WEEKS = '4w';
    case SIX_MONTHS = '6m';
    case YEAR_TO_DATE = 'ytd';
    case ALL = 'all';
    case MONTH = 'month';
    case YEAR = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::FOUR_WEEKS => '4 dernières semaines',
            self::SIX_MONTHS => '6 derniers mois',
            self::YEAR_TO_DATE => 'Depuis le début de l\'année',
            self::ALL => 'Depuis le début',
            self::MONTH => 'Un mois',
            self::YEAR => 'Une année',
        };
    }

    /**
     * Libellé court du sélecteur (segmenté), en condensé capitales côté CSS.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::FOUR_WEEKS => '4 semaines',
            self::SIX_MONTHS => '6 mois',
            self::YEAR_TO_DATE => 'Cette année',
            self::ALL => 'Tout',
            self::MONTH => 'Par mois',
            self::YEAR => 'Par année',
        };
    }

    /**
     * Les quatre fenêtres directement cliquables. MONTH et YEAR en sont exclus :
     * ils ne se choisissent pas d'un clic, ils se choisissent avec une valeur.
     *
     * @return list<self>
     */
    public static function pickable(): array
    {
        return [self::FOUR_WEEKS, self::SIX_MONTHS, self::YEAR_TO_DATE, self::ALL];
    }

    /**
     * Les fenêtres lues semaine par semaine dans les rampes. Au-delà d'un mois,
     * une barre par semaine se lit mal : les autres se découpent par mois.
     */
    public function isWeekly(): bool
    {
        return self::FOUR_WEEKS === $this || self::MONTH === $this;
    }
}
