<?php

namespace App\Service;

use App\Entity\Workout;

/**
 * Résultat de `SharedWorkoutCopier::copyFor()` : la copie, et le nombre
 * d'exercices perso de l'auteur recopiés dans la bibliothèque du destinataire
 * (pour le lui dire, plutôt que de les y faire apparaître en silence).
 */
final readonly class SharedWorkoutCopy
{
    public function __construct(
        public Workout $workout,
        public int $copiedExercises,
    ) {
    }
}
