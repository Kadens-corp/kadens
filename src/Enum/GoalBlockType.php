<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un bloc du récit d'un objectif. Le récit est une suite ordonnée de
 * blocs, comme une séance est une suite de blocs d'exercices : du texte, une
 * photo, ou une **référence** à un fait déjà consigné ailleurs (une sortie
 * importée, une séance faite). Une référence ne recopie rien : elle se rend à
 * partir de la source qui fait autorité sur ses chiffres.
 */
enum GoalBlockType: string
{
    case TEXT = 'text';
    case PHOTO = 'photo';
    case ACTIVITY = 'activity';
    case SESSION = 'session';

    public function getLabel(): string
    {
        return match ($this) {
            self::TEXT => 'Paragraphe',
            self::PHOTO => 'Photo',
            self::ACTIVITY => 'Activité importée',
            self::SESSION => 'Séance faite',
        };
    }

    /**
     * Le nom de l'élément tel qu'on le désigne dans une action (« Monter ce
     * paragraphe »). « Bloc » avait un autre sens dans les séances (#105).
     */
    public function demonstrative(): string
    {
        return match ($this) {
            self::TEXT => 'ce paragraphe',
            self::PHOTO => 'cette photo',
            self::ACTIVITY => 'cette activité',
            self::SESSION => 'cette séance',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TEXT => 'lucide:pilcrow',
            self::PHOTO => 'lucide:image',
            self::ACTIVITY => 'lucide:activity',
            self::SESSION => 'lucide:calendar-check',
        };
    }

    /** Le bloc pointe-t-il vers une entité consignée ailleurs ? */
    public function isReference(): bool
    {
        return self::ACTIVITY === $this || self::SESSION === $this;
    }
}
