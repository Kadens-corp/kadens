<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Cycle de vie d'une amitié (#126). Deux états seulement, contrairement au
 * coaching : une demande refusée, annulée ou une amitié retirée **supprime la
 * ligne**. Il n'y a rien à garder d'une amitié finie (pas de notes, pas de
 * contenu co-écrit, pas de « anciens amis »), et la personne peut redemander
 * comme la première fois.
 */
enum FriendshipStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
}
