<?php

declare(strict_types=1);

namespace App\Enum;

enum PrescriptionType: string
{
    case SETS_REPS = 'sets_reps';
    case SETS_TIME = 'sets_time';
    case AMRAP = 'amrap';
    case FOR_TIME = 'for_time';
    case DISTANCE_PACE = 'distance_pace';
    case DURATION = 'duration';

    public function getLabel(): string
    {
        return match ($this) {
            self::SETS_REPS => 'Séries × répétitions',
            self::SETS_TIME => 'Séries × durée',
            self::AMRAP => 'Max de répétitions',
            self::FOR_TIME => 'Le plus vite possible',
            self::DISTANCE_PACE => 'Distance × allure',
            self::DURATION => 'Durée',
        };
    }

    /**
     * Sous-ensemble de champs de valeurs pertinent pour ce type de prescription.
     *
     * Source unique consommée par : le form (affichage dynamique des champs),
     * le nettoyage serveur (on annule tout champ hors sous-ensemble) et le rendu
     * via PlanFlattener. `rpe` et `notes` sont transverses (valables pour tous les
     * types), donc absents d'ici.
     *
     * Le cardio se prescrit en durée ou distance, intensité et allure, rien de
     * plus. Ni séries ni repos : un fractionné, c'est un bloc à plusieurs tours
     * (l'effort, puis un footing ou une marche de récup, qui compte dans le
     * volume) ; le terrain (côte, D+) va dans les notes.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::SETS_REPS => ['sets', 'reps', 'weightKg', 'restSeconds'],
            self::SETS_TIME => ['sets', 'durationSeconds', 'weightKg', 'restSeconds'],
            self::AMRAP => ['durationSeconds', 'targetReps', 'restSeconds'],
            self::FOR_TIME => ['targetReps', 'capSeconds', 'restSeconds'],
            self::DISTANCE_PACE => ['distanceMeters', 'paceSecondsPerKm', 'intensityZone'],
            self::DURATION => ['durationSeconds', 'paceSecondsPerKm', 'intensityZone'],
        };
    }

    /**
     * Carte `type => champs pertinents`, pour l'affichage dynamique côté client
     * (contrôleur Stimulus) sans dupliquer la logique de `fields()`.
     *
     * @return array<string, list<string>>
     */
    public static function fieldsMap(): array
    {
        $map = [];
        foreach (self::cases() as $case) {
            $map[$case->value] = $case->fields();
        }

        return $map;
    }
}
