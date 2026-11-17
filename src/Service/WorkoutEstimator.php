<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\PrescriptionType;

/**
 * Estime la durée d'une séance à partir de son contenu. L'utilisateur ne saisit
 * plus la durée à la main : une vraie valeur dérivée du travail prescrit est
 * toujours meilleure qu'une estimation faite de tête. Plus les paramètres
 * (séries, reps, repos, allure) sont renseignés, plus l'estimation est juste.
 *
 * Hypothèses par défaut quand une valeur manque :
 *   - 10 répétitions ≈ 1 minute de travail (6 s / rep) ;
 *   - repos par défaut de 2 min entre les séries de renforcement si non précisé.
 *
 * Les durées sont sommées par bloc puis multipliées par le nombre de tours.
 *
 * Superset : ses membres s'enchaînent sans repos, seul le DERNIER du groupe repose
 * (son repos est celui d'entre deux tours du groupe). Le repos des autres compte
 * donc pour zéro, même renseigné : c'est ce que dit le compositeur (#85).
 */
final class WorkoutEstimator
{
    private const SECONDS_PER_REP = 6;        // 10 reps ≈ 1 min
    private const DEFAULT_REST_SECONDS = 180; // 3 min de repos si non renseigné

    public function __construct(private readonly SupersetGrouper $supersets)
    {
    }

    public function estimateSeconds(Workout $workout): int
    {
        $total = 0;

        foreach ($workout->getBlocks() as $block) {
            $total += $this->estimateBlockSeconds($block);
        }

        return $total;
    }

    /**
     * Durée estimée d'un bloc seul, tours compris. Extrait de estimateSeconds()
     * pour que la timeline de l'onglet « Analyse » et le résumé d'en-tête
     * d'accordéon lisent la même valeur que le total de la séance.
     */
    public function estimateBlockSeconds(Block $block): int
    {
        $seconds = 0;
        foreach ($this->supersets->segments($block) as $segment) {
            $last = count($segment['exercises']) - 1;
            foreach ($segment['exercises'] as $i => $prescribed) {
                $seconds += $this->prescribedSeconds($prescribed, $i < $last);
            }
        }

        return $seconds * max(1, $block->getRounds());
    }

    /**
     * Durée estimée en minutes (arrondie au supérieur), ou null si la séance est
     * vide : les gabarits d'affichage masquent alors la badge de durée.
     */
    public function estimateMinutes(Workout $workout): ?int
    {
        $seconds = $this->estimateSeconds($workout);

        return $seconds > 0 ? (int) ceil($seconds / 60) : null;
    }

    /**
     * @param bool $chained membre d'un superset suivi d'un autre membre : enchaîné
     *                      sans repos
     */
    private function prescribedSeconds(PrescribedExercise $pe, bool $chained): int
    {
        return match ($pe->getPrescriptionType()) {
            PrescriptionType::SETS_REPS => $this->setsReps($pe, $chained),
            PrescriptionType::SETS_TIME => $this->setsTime($pe, $chained),
            PrescriptionType::AMRAP => $pe->getDurationSeconds() ?? 0,
            PrescriptionType::FOR_TIME => $this->forTime($pe),
            PrescriptionType::DISTANCE_PACE => $this->distancePace($pe, $chained),
            PrescriptionType::DURATION => $pe->getDurationSeconds() ?? 0,
            null => 0,
        };
    }

    private function setsReps(PrescribedExercise $pe, bool $chained): int
    {
        $rest = $chained ? 0 : ($pe->getRestSeconds() ?? self::DEFAULT_REST_SECONDS);

        // Mode détaillé : chaque série a ses propres reps (l'échauffement compte
        // aussi dans la durée vécue, contrairement au volume de travail).
        if ($pe->hasDetailedSets()) {
            $total = 0;
            foreach ($pe->getDetailedSets() as $set) {
                $total += ($set->getReps() ?? 10) * self::SECONDS_PER_REP + $rest;
            }

            return $total;
        }

        $sets = max(1, $pe->getSets() ?? 1);
        $reps = $pe->getReps() ?? 10;

        return $sets * ($reps * self::SECONDS_PER_REP + $rest);
    }

    private function setsTime(PrescribedExercise $pe, bool $chained): int
    {
        $rest = $chained ? 0 : ($pe->getRestSeconds() ?? self::DEFAULT_REST_SECONDS);

        if ($pe->hasDetailedSets()) {
            $total = 0;
            foreach ($pe->getDetailedSets() as $set) {
                $total += ($set->getDurationSeconds() ?? 0) + $rest;
            }

            return $total;
        }

        $sets = max(1, $pe->getSets() ?? 1);
        $duration = $pe->getDurationSeconds() ?? 0;

        return $sets * ($duration + $rest);
    }

    private function forTime(PrescribedExercise $pe): int
    {
        // Le cap est la borne haute du temps ; sinon on estime depuis les reps cible.
        if (null !== $pe->getCapSeconds()) {
            return $pe->getCapSeconds();
        }

        return ($pe->getTargetReps() ?? 0) * self::SECONDS_PER_REP;
    }

    private function distancePace(PrescribedExercise $pe, bool $chained): int
    {
        $meters = $pe->getDistanceMeters() ?? 0;
        $pace = $pe->getPaceSecondsPerKm() ?? 0;
        $work = (int) round($meters / 1000 * $pace);

        // Intervalles : chaque répétition refait la distance + sa récup. Même
        // multiplicateur que le volume (WorkoutMetrics::volume), une seule écriture.
        $reps = $pe->getEnduranceRepeats();

        return $reps * ($work + ($chained ? 0 : ($pe->getRestSeconds() ?? 0)));
    }
}
