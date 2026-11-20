<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La valeur qu'on fait progresser d'une semaine à l'autre avec l'outil de
 * progression du plan (#110) : la charge ou les répétitions d'un exercice de
 * force. Les deux seules que le tableau des séries porte pour toutes les lignes.
 */
enum ProgressionField: string
{
    case WEIGHT = 'weight';
    case REPS = 'reps';

    public function getLabel(): string
    {
        return match ($this) {
            self::WEIGHT => 'Charge',
            self::REPS => 'Répétitions',
        };
    }

    /** Unité affichée après le pas (« +2,5 kg par semaine », « +1 rép. par semaine »). */
    public function unit(): string
    {
        return match ($this) {
            self::WEIGHT => 'kg',
            self::REPS => 'rép.',
        };
    }

    /**
     * Le type de prescription porte-t-il cette valeur ? La charge vit sur les deux
     * types de force (séries × répétitions, séries × durée : un gainage lesté), les
     * répétitions sur le premier seulement.
     */
    public function appliesTo(?PrescriptionType $type): bool
    {
        return match ($this) {
            self::WEIGHT => PrescriptionType::SETS_REPS === $type || PrescriptionType::SETS_TIME === $type,
            self::REPS => PrescriptionType::SETS_REPS === $type,
        };
    }
}
