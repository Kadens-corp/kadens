<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ActivityType;
use App\Repository\ImportedActivityRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * La NATURE d'une séance faite : son activité dominante, celle que portent le
 * plus d'exercices. **Une séance n'a qu'une nature**, et c'est ce qui permet à
 * une répartition par activité de s'additionner : la somme vaut le nombre de
 * séances faites. Compter une séance dans chacune de ses activités faisait
 * d'une séance de salle avec un échauffement au rameur deux séances faites
 * (#62).
 *
 * C'est la même activité que l'aplat de couleur d'une séance
 * (`activity.surface()`, la première de `WorkoutMetrics::distinctActivities()`) :
 * la séance se range sous la couleur qu'on lui voit partout ailleurs.
 *
 * Deux consommateurs, une règle : `TrainingHistory` (le calendrier,
 * `/profile/history`) et `TrainingStats` (l'accueil, le profil, `/profile/stats`
 * et leurs jumeaux coach). Deux départages écrits séparément rangeraient la
 * même séance à deux endroits selon la page.
 *
 * **Trois sources, dans cet ordre : le prescrit, l'activité importée, le
 * réalisé logué.** Le prescrit dit ce que la séance était censée être, et c'est
 * la bonne réponse dès qu'il existe, y compris pour une sortie course, qui ne
 * logue rien. Mais une séance peut n'avoir aucun prescrit : tout historique
 * importé (`TrainingHistoryImporter` crée ses séances avec `workout = null`,
 * pour ne pas inventer une intention), la reprise Intervals (`ActivityMatcher`),
 * une séance dont la source a été supprimée. Les sources suivantes prennent
 * alors le relais, sans quoi des centaines de séances de salle se rangeraient
 * sous « sans contenu », ce qui serait faux.
 *
 * L'activité importée passe AVANT le réalisé logué parce qu'elle est mesurée,
 * pas saisie : une sortie importée où l'on aurait logué du gainage reste une
 * sortie. Toutes deux ne font que **compléter** le prescrit, jamais le
 * corriger : une séance prescrite en course dont on aurait logué du gainage
 * reste une course. Le jour où l'on voudrait le contraire, ce serait une
 * décision à prendre, pas un effet de bord à laisser arriver.
 *
 * Départage : à égalité, l'ordre de déclaration de l'enum tranche (la salle
 * d'abord), via `ActivityType::rankByCount()`. Arbitraire mais stable.
 *
 * **Coût.** Trois projections scalaires, aucune hydratation, bornées par la
 * fenêtre quand il y en a une.
 *
 * @phpstan-type ActivityTally array{activity: ActivityType|null, sessions: int}
 */
final class SessionNature
{
    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly ImportedActivityRepository $importedActivities,
    ) {
    }

    /**
     * L'activité dominante de chaque séance faite de la fenêtre, indexée par id
     * de séance datée. Une séance absente de la table n'a de nature dans aucune
     * des trois sources : c'est une séance restée vide.
     *
     * @return array<int, ActivityType>
     */
    public function dominantByScheduled(User $owner, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null): array
    {
        $prescribed = $this->pickDominant(
            $this->scheduled->doneActivityCountsForOwner($owner, $start, $end)
        );

        // Le poids d'une activité y est son nombre de sorties rattachées au
        // lieu d'un nombre d'exercices.
        $imported = $this->pickDominant(array_map(
            static fn (array $row): array => ['scheduledId' => $row['scheduledId'], 'activity' => $row['activity'], 'exercises' => $row['activities']],
            $this->importedActivities->doneActivityCountsByScheduledWorkout($owner, $start, $end),
        ));

        $logged = $this->pickDominant(
            $this->scheduled->doneLoggedActivityCountsForOwner($owner, $start, $end)
        );

        // `+` sur des tableaux garde la valeur de GAUCHE en cas de clé commune :
        // le prescrit prime, l'importé puis le réalisé ne comblent que les
        // séances que les sources précédentes ignorent.
        return $prescribed + $imported + $logged;
    }

    /**
     * La répartition par nature : une ligne par activité, la plus pratiquée
     * d'abord, et une dernière ligne `activity: null` pour les séances restées
     * vides. Chaque séance compte pour UNE : la somme des lignes vaut le nombre
     * de natures reçues, donc le nombre de séances faites.
     *
     * @param iterable<ActivityType|null> $natures une entrée par séance faite
     *
     * @return list<ActivityTally>
     */
    public static function tally(iterable $natures): array
    {
        $counts = [];
        $unknown = 0;

        foreach ($natures as $nature) {
            if (null === $nature) {
                ++$unknown;
                continue;
            }

            $counts[$nature->value] = ($counts[$nature->value] ?? 0) + 1;
        }

        // Le tri est stable depuis PHP 8 : à égalité, l'ordre de rencontre reste.
        arsort($counts);

        $tally = [];
        foreach ($counts as $value => $sessions) {
            $tally[] = ['activity' => ActivityType::from((string) $value), 'sessions' => $sessions];
        }

        if ($unknown > 0) {
            $tally[] = ['activity' => null, 'sessions' => $unknown];
        }

        return $tally;
    }

    /**
     * L'activité la plus portée de chaque séance, à partir de lignes
     * (séance, activité, poids). Écrit une fois pour les trois sources.
     *
     * @param list<array{scheduledId: int, activity: ActivityType, exercises: int}> $rows
     *
     * @return array<int, ActivityType>
     */
    private function pickDominant(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['scheduledId']][$row['activity']->value] = $row['exercises'];
        }

        $dominant = [];
        foreach ($counts as $scheduledId => $byActivity) {
            $ranked = ActivityType::rankByCount($byActivity);
            if ([] !== $ranked) {
                $dominant[$scheduledId] = $ranked[0];
            }
        }

        return $dominant;
    }
}
