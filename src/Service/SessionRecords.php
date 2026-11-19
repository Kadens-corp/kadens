<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduledWorkout;
use App\Repository\LoggedSetRepository;

/**
 * Les records BATTUS dans des séances passées (#126) : ce que le flux d'amis
 * annonce sous une séance (« Record · Développé couché · 92,5 kg × 6, +2,5 kg »).
 *
 * C'est la règle de l'écran de clôture du mobile (`kadens-mobile/src/session/
 * records.ts`), rejouée après coup, et elle doit dire la même chose : un ami ne
 * doit pas voir une couronne que l'athlète n'a pas eue en fermant sa séance, ni
 * l'inverse. Elle-même hérite de `PerformanceHistory` :
 *
 * - deux records sur deux séries disjointes : la **charge** (la série chargée la
 *   plus lourde, les répétitions départagent à charge égale) et le **poids du
 *   corps** (la série sans charge la plus longue en répétitions) ;
 * - échauffement, exercice sauté et série non chiffrée exclus (le périmètre de
 *   la requête) ;
 * - **une marque absente n'a rien à battre** : la première fois est une
 *   première, pas un record ;
 * - **une couronne par exercice**, celle de la meilleure série, la charge
 *   d'abord quand les deux tombent.
 *
 * La référence est tout ce que l'auteur a fait **avant** la séance, dans
 * l'ordre (date, identifiant) : deux séances le même jour se départagent par
 * leur ordre d'écriture, comme ailleurs dans l'app.
 *
 * **Une requête pour toute la page**, quel que soit le nombre de séances et
 * d'auteurs (`LoggedSetRepository::loadsBySessionForOwners()`).
 *
 * @phpstan-type SessionRecord array{exerciseId: int, metric: 'load'|'reps', weightKg: float|null, reps: int, gain: string}
 */
final readonly class SessionRecords
{
    public function __construct(
        private LoggedSetRepository $sets,
        private UnitFormatter $units,
    ) {
    }

    /**
     * Les records battus de chaque séance, indexés par séance puis par
     * exercice. Une séance sans record est absente.
     *
     * Les séances doivent porter leur réalisé (hydraté) : c'est lui qui dit
     * quels exercices interroger.
     *
     * @param list<ScheduledWorkout> $sessions
     *
     * @return array<int, array<int, SessionRecord>>
     */
    public function forSessions(array $sessions): array
    {
        $ownerIds = [];
        $exerciseIds = [];
        $until = null;

        foreach ($sessions as $session) {
            $ownerId = $session->getOwner()?->getId();
            if (null === $ownerId) {
                continue;
            }
            $ownerIds[$ownerId] = $ownerId;

            foreach ($session->getLoggedExercises() as $logged) {
                $exerciseId = $logged->getExercise()?->getId();
                if (null !== $exerciseId && !$logged->isSkipped()) {
                    $exerciseIds[$exerciseId] = $exerciseId;
                }
            }

            $date = $session->getScheduledDate();
            if (null === $until || $date > $until) {
                $until = $date;
            }
        }

        if (null === $until) {
            return [];
        }

        // Par auteur et par exercice, chaque séance réduite à ses deux meilleures
        // séries : la plus lourde (avec ses répétitions) et la plus longue à vide.
        /** @var array<string, array<int, array{date: \DateTimeImmutable, id: int, load: array{weightKg: float, reps: int}|null, bodyweight: int|null}>> $peaks */
        $peaks = [];

        foreach ($this->sets->loadsBySessionForOwners(array_values($ownerIds), array_values($exerciseIds), $until) as $row) {
            $key = $row['ownerId'].':'.$row['exerciseId'];
            $id = $row['scheduledWorkoutId'];
            $peak = $peaks[$key][$id] ?? ['date' => $row['date'], 'id' => $id, 'load' => null, 'bodyweight' => null];

            $weight = $row['weightKg'] ?? 0.0;
            if ($weight > 0) {
                $candidate = ['weightKg' => $weight, 'reps' => $row['reps']];
                if (null === $peak['load'] || self::outranks($candidate, $peak['load'])) {
                    $peak['load'] = $candidate;
                }
            } elseif ($row['reps'] > 0 && $row['reps'] > ($peak['bodyweight'] ?? 0)) {
                $peak['bodyweight'] = $row['reps'];
            }

            $peaks[$key][$id] = $peak;
        }

        $records = [];

        foreach ($sessions as $session) {
            $sessionId = $session->getId();
            $ownerId = $session->getOwner()?->getId();
            if (null === $sessionId || null === $ownerId) {
                continue;
            }

            foreach ($session->getLoggedExercises() as $logged) {
                $exerciseId = $logged->getExercise()?->getId();
                if (null === $exerciseId || $logged->isSkipped() || isset($records[$sessionId][$exerciseId])) {
                    continue;
                }

                $record = $this->recordOf($peaks[$ownerId.':'.$exerciseId] ?? [], $session);
                if (null !== $record) {
                    $records[$sessionId][$exerciseId] = ['exerciseId' => $exerciseId] + $record;
                }
            }
        }

        return $records;
    }

    /**
     * @param array<int, array{date: \DateTimeImmutable, id: int, load: array{weightKg: float, reps: int}|null, bodyweight: int|null}> $peaks
     *
     * @return array{metric: 'load'|'reps', weightKg: float|null, reps: int, gain: string}|null
     */
    private function recordOf(array $peaks, ScheduledWorkout $session): ?array
    {
        $today = $peaks[(int) $session->getId()] ?? null;
        if (null === $today) {
            return null;
        }

        $bestLoad = null;
        $bestBodyweight = null;

        foreach ($peaks as $peak) {
            if (!self::before($peak, $today)) {
                continue;
            }
            if (null !== $peak['load'] && (null === $bestLoad || self::outranks($peak['load'], $bestLoad))) {
                $bestLoad = $peak['load'];
            }
            if (null !== $peak['bodyweight'] && $peak['bodyweight'] > ($bestBodyweight ?? 0)) {
                $bestBodyweight = $peak['bodyweight'];
            }
        }

        // La charge d'abord : c'est elle qui fait le record quand il y en a une.
        if (null !== $today['load'] && null !== $bestLoad && self::outranks($today['load'], $bestLoad)) {
            $gain = $today['load']['weightKg'] !== $bestLoad['weightKg']
                ? '+'.$this->units->weight(round($today['load']['weightKg'] - $bestLoad['weightKg'], 2))
                : self::repsGain($today['load']['reps'] - $bestLoad['reps']);

            return ['metric' => 'load', 'weightKg' => $today['load']['weightKg'], 'reps' => $today['load']['reps'], 'gain' => $gain];
        }

        if (null !== $today['bodyweight'] && null !== $bestBodyweight && $today['bodyweight'] > $bestBodyweight) {
            return ['metric' => 'reps', 'weightKg' => null, 'reps' => $today['bodyweight'], 'gain' => self::repsGain($today['bodyweight'] - $bestBodyweight)];
        }

        return null;
    }

    /**
     * @param array{date: \DateTimeImmutable, id: int} $a
     * @param array{date: \DateTimeImmutable, id: int} $b
     */
    private static function before(array $a, array $b): bool
    {
        $byDate = $a['date']->format('Y-m-d') <=> $b['date']->format('Y-m-d');

        return $byDate < 0 || (0 === $byDate && $a['id'] < $b['id']);
    }

    /**
     * La cascade du serveur (`PerformanceHistory`) : la charge, puis les
     * répétitions à charge égale. Une égalité parfaite n'est pas un record.
     *
     * @param array{weightKg: float, reps: int} $a
     * @param array{weightKg: float, reps: int} $b
     */
    private static function outranks(array $a, array $b): bool
    {
        if ($a['weightKg'] !== $b['weightKg']) {
            return $a['weightKg'] > $b['weightKg'];
        }

        return $a['reps'] > $b['reps'];
    }

    private static function repsGain(int $reps): string
    {
        return sprintf('+%d rép.', $reps);
    }
}
