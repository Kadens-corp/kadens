<?php

namespace App\Service;

use App\Entity\Goal;
use App\Entity\User;
use App\Repository\GoalRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * La semaine de calendrier d'un utilisateur : sept jours lundi → dimanche autour
 * d'une date, avec ses séances datées, ses échéances et le bandeau de synthèse
 * (observance, volume estimé).
 *
 * Elle prend le **propriétaire** en paramètre, jamais l'utilisateur courant :
 * c'est ce qui permet au coach de lire la semaine de son athlète avec le même
 * template et les mêmes chiffres que l'athlète voit chez lui (#77, C-14). Le
 * contrôleur décide de qui l'on parle ; ce service ne sait pas qui regarde.
 */
final class CalendarWeek
{
    /** Noms de mois en français (index 1..12), le calendrier étant mono-langue. */
    public const MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    private const DAY_NAMES = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduledWorkouts,
        private readonly GoalRepository $goals,
        private readonly PlanFlattener $planFlattener,
    ) {
    }

    /**
     * Le contexte de rendu de `calendar/week.html.twig` pour la semaine qui
     * contient `$ref`, chez `$owner`.
     *
     * @return array<string, mixed>
     */
    public function for(User $owner, \DateTimeImmutable $ref): array
    {
        // Ancrage au lundi ISO de la semaine contenant la date.
        $monday = $ref->modify(sprintf('-%d days', (int) $ref->format('N') - 1))->setTime(0, 0);
        $sunday = $monday->modify('+6 days');
        $todayKey = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $byDate = [];
        $flattened = [];
        $ids = [];
        foreach ($this->scheduledWorkouts->findByOwnerBetween($owner, $monday, $sunday) as $scheduled) {
            $byDate[$scheduled->getScheduledDate()->format('Y-m-d')][] = $scheduled;
            $ids[] = (int) $scheduled->getId();
            $workout = $scheduled->getWorkout();
            if (null !== $workout) {
                $flattened[(int) $workout->getId()] ??= $this->planFlattener->flattenWorkout($workout);
            }
        }

        $goalsByDate = self::goalsByDate($this->goals->findByOwnerBetween($owner, $monday, $sunday));

        $days = [];
        $totalMinutes = 0;
        $cursor = $monday;
        for ($i = 0; $i < 7; ++$i) {
            $key = $cursor->format('Y-m-d');
            $scheduled = $byDate[$key] ?? [];
            foreach ($scheduled as $s) {
                $totalMinutes += (int) $s->getWorkout()?->getEstimatedDurationMinutes();
            }
            $days[] = [
                'date' => $cursor,
                'dayName' => self::DAY_NAMES[(int) $cursor->format('N')],
                'isToday' => $key === $todayKey,
                'isPast' => $key < $todayKey,
                'weekend' => (int) $cursor->format('N') >= 6,
                'scheduled' => $scheduled,
                'goals' => $goalsByDate[$key] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $counts = $this->scheduledWorkouts->countByStatusForOwnerBetween($owner, $monday, $sunday);
        $pivot = $monday->modify('+3 days');

        return [
            'monday' => $monday,
            'sunday' => $sunday,
            'weekLabel' => self::label($monday, $sunday),
            'isoWeek' => (int) $monday->format('W'),
            'days' => $days,
            'flattened' => $flattened,
            // Le ressenti des séances faites, sur la carte haute : une requête
            // pour la semaine. La vue mois ne l'affiche pas, faute de place.
            'ratings' => $this->scheduledWorkouts->ratingsFor($ids),
            'nextGoal' => $this->goals->findNextForOwner($owner),
            'counts' => $counts,
            'sessionCount' => array_sum($counts),
            'totalMinutes' => $totalMinutes,
            'todayKey' => $todayKey,
            'prevDate' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextDate' => $monday->modify('+7 days')->format('Y-m-d'),
            'monthPivot' => ['year' => (int) $pivot->format('Y'), 'month' => (int) $pivot->format('n')],
        ];
    }

    /**
     * Indexe des objectifs par date d'échéance (Y-m-d). Plusieurs objectifs peuvent
     * tomber le même jour, d'où une liste par clé.
     *
     * @param list<Goal> $goals
     *
     * @return array<string, list<Goal>>
     */
    public static function goalsByDate(array $goals): array
    {
        $byDate = [];
        foreach ($goals as $goal) {
            $date = $goal->getTargetDate();
            if (null !== $date) {
                $byDate[$date->format('Y-m-d')][] = $goal;
            }
        }

        return $byDate;
    }

    /**
     * Libellé lisible d'une semaine : « 21 – 27 juillet 2026 », en compactant le
     * mois/l'année communs aux deux bornes.
     */
    private static function label(\DateTimeImmutable $monday, \DateTimeImmutable $sunday): string
    {
        $mFrom = self::MONTH_NAMES[(int) $monday->format('n')];
        $mTo = self::MONTH_NAMES[(int) $sunday->format('n')];
        $yFrom = $monday->format('Y');
        $yTo = $sunday->format('Y');

        if ($yFrom !== $yTo) {
            return sprintf('%d %s %s – %d %s %s', (int) $monday->format('j'), $mFrom, $yFrom, (int) $sunday->format('j'), $mTo, $yTo);
        }
        if ($mFrom !== $mTo) {
            return sprintf('%d %s – %d %s %s', (int) $monday->format('j'), $mFrom, (int) $sunday->format('j'), $mTo, $yTo);
        }

        return sprintf('%d – %d %s %s', (int) $monday->format('j'), (int) $sunday->format('j'), $mTo, $yTo);
    }
}
