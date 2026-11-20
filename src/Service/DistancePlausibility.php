<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PrescribedExercise;
use App\Enum\ActivityType;
use App\Enum\PrescriptionType;

/**
 * Alerte de vraisemblance sur une distance prescrite (#97). « 6 × 400 km » passait
 * sans un mot : la personne voulait 400 m, l'unité proposée était le km.
 *
 * On juge le **total** (distance × répétitions), pas la répétition seule : c'est
 * lui qui trahit l'erreur d'unité (6 × 400 km = 2 400 km), et une sortie longue
 * d'un seul tenant reste sous le seuil. Le seuil dépend de l'activité, au-dessus
 * de ce qu'une séance réelle atteint d'ordinaire, mais pas de ce qu'elle peut
 * atteindre : un ultra de 160 km existe. D'où une **alerte**, jamais un refus. La
 * valeur est enregistrée telle quelle ; la phrase dit seulement quoi vérifier.
 */
final class DistancePlausibility
{
    /** Total au-delà duquel on alerte, en mètres, par activité. */
    private const array THRESHOLDS = [
        ActivityType::RUNNING->value => 100_000,
        ActivityType::CYCLING->value => 400_000,
        ActivityType::SWIMMING->value => 10_000,
        // Rameur, ski-erg : un marathon au rameur (42 195 m) passe.
        ActivityType::OTHER->value => 50_000,
    ];

    /** Seuil des activités sans distance attendue (salle, mobilité, exercice retiré). */
    private const int DEFAULT_THRESHOLD = 50_000;

    public function __construct(
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * La phrase d'alerte, ou null quand la distance est vraisemblable, absente ou
     * hors sujet pour le type d'effort (elle sera effacée à l'enregistrement).
     */
    public function warning(PrescribedExercise $prescribed): ?string
    {
        $meters = $prescribed->getDistanceMeters();
        if (null === $meters || $meters <= 0
            || !\in_array('distanceMeters', ($prescribed->getPrescriptionType() ?? PrescriptionType::SETS_REPS)->fields(), true)) {
            return null;
        }

        $reps = max(1, $prescribed->getSets() ?? 1);
        $total = $meters * $reps;
        if ($total <= $this->thresholdFor($prescribed->getExercise()?->getActivity())) {
            return null;
        }

        $distance = $this->units->distance($meters);
        $said = $reps > 1
            ? sprintf('%d × %s, soit %s au total', $reps, $distance, $this->units->distance($total))
            : $distance;

        return $said.' : vérifie l\'unité (m ou km).';
    }

    public function thresholdFor(?ActivityType $activity): int
    {
        return null === $activity ? self::DEFAULT_THRESHOLD : (self::THRESHOLDS[$activity->value] ?? self::DEFAULT_THRESHOLD);
    }
}
