<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\BodySide;
use App\Repository\FriendshipRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\ExerciseVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le flux d'amis (#126) : les séances de salle faites de ses amis et les
 * siennes, de la plus récente à la plus ancienne, chacune avec la liste
 * complète de ses exercices tels qu'ils ont été faits.
 *
 * Ce qu'il montre est décidé une fois, par `ScheduledWorkoutRepository::
 * feedQuery()` : séance `DONE` avec un réalisé, partage de l'auteur ouvert, non
 * masquée — sauf pour ses propres séances, qu'on voit toujours. `canRead()`
 * pose la même question pour une seule séance, avant de la laisser copier.
 *
 * Pas de réaction, pas de compteur (« Lire, copier, c'est tout ») : une carte
 * dit qui, quoi, combien, le record battu s'il y en a un, le ressenti écrit, et
 * les exercices. Les exercices perso de l'auteur se lisent par leur nom, en
 * lecture seule : on ne les ouvre pas, on peut seulement copier la séance.
 *
 * Une page = `PAGE_SIZE` séances hydratées avec leur réalisé : c'est la borne
 * qui tient la contrainte de coût du projet (une liste qui hydrate le réalisé
 * ne tient que bornée à une dizaine de lignes).
 *
 * **Les muscles travaillés (#135)** se lisent sur le réalisé (`LogMetrics`) et
 * se peignent par `BodyLoad`, comme la carte de l'accueil. La silhouette est
 * celle de l'**auteur**, pas du lecteur : partout ailleurs elle est un réglage
 * d'affichage de celui qui regarde, mais ici la carte montre quelqu'un, et
 * c'est lui qui a choisi comment il est dessiné. Les tracés (~35 Ko par
 * planche) ne se répètent pas de carte en carte : la page les pose une fois en
 * `<symbol>` (`plates`), et chaque carte n'y renvoie qu'avec ses paliers.
 *
 * @phpstan-import-type SessionRecord from SessionRecords
 * @phpstan-import-type BodyLoadResult from BodyLoad
 * @phpstan-import-type BodyPlate from BodyPlates
 *
 * @phpstan-type FeedExercise array{n: int, exercise: Exercise|null, name: string, perso: bool, addedToMine: bool, summary: string, rpe: string|null, record: bool}
 * @phpstan-type FeedRecord array{exercise: Exercise|null, name: string, value: string, gain: string}
 * @phpstan-type FeedPlateRef array{key: string, side: string, viewBox: string}
 * @phpstan-type FeedBody array{load: BodyLoadResult, plates: list<FeedPlateRef>, firstSide: string}
 * @phpstan-type FeedEntry array{session: ScheduledWorkout, author: User, mine: bool, hidden: bool, dayKey: string, dayLabel: string, title: string, durationMinutes: int|null, tonnageKg: float, exerciseCount: int, note: string|null, exercises: list<FeedExercise>, records: list<FeedRecord>, copiedBy: list<Workout>, myCopy: Workout|null, copyTitle: string, addedCount: int, body: FeedBody|null}
 */
final readonly class FriendFeed
{
    public const PAGE_SIZE = 10;

    /** Exercices montrés avant « Voir les N autres exercices ». */
    public const PREVIEW_EXERCISES = 3;

    private const DAYS = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public function __construct(
        private FriendshipRepository $friendships,
        private ScheduledWorkoutRepository $sessions,
        private WorkoutRepository $workouts,
        private LogMetrics $metrics,
        private SessionRecords $records,
        private UnitFormatter $units,
        private Security $security,
        private BodyLoad $bodyLoad,
        private BodyPlates $bodyPlates,
    ) {
    }

    /**
     * Une page du flux de `$viewer`, restreinte à un auteur s'il est donné
     * (un ami, ou lui-même pour « Mes séances »). L'appelant ne passe qu'un
     * auteur autorisé : `authorFor()`.
     *
     * `plates` : les planches que les cartes de la page dessinent, indexées
     * `silhouette-côté`, à poser une fois en `<symbol>`. Celles d'une carte
     * masquée en font partie : la repartager la redessine sans recharger.
     *
     * @return array{entries: list<FeedEntry>, hasMore: bool, plates: array<string, BodyPlate>}
     */
    public function page(User $viewer, ?User $only, int $page): array
    {
        $authors = null !== $only ? [$only] : [$viewer, ...$this->friendships->friendsOf($viewer)];
        $page = max(1, $page);

        // Une séance de plus que la page : c'est elle qui dit s'il en reste.
        $sessions = $this->sessions->findFeedPage($viewer, $authors, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE + 1);
        $hasMore = \count($sessions) > self::PAGE_SIZE;
        $sessions = \array_slice($sessions, 0, self::PAGE_SIZE);

        $records = $this->records->forSessions($sessions);
        $copies = $this->workouts->findCopiesOfSessions(array_map(static fn (ScheduledWorkout $s): int => (int) $s->getId(), $sessions));

        $entries = [];
        foreach ($sessions as $session) {
            $entries[] = $this->entry($viewer, $session, $records[(int) $session->getId()] ?? [], $copies[(int) $session->getId()] ?? []);
        }

        $plates = [];
        foreach ($entries as $entry) {
            foreach ($entry['body']['plates'] ?? [] as $ref) {
                $plates[$ref['key']] ??= $this->bodyPlates->all()[$ref['key']];
            }
        }

        return ['entries' => $entries, 'hasMore' => $hasMore, 'plates' => $plates];
    }

    /**
     * La carte d'une seule séance, telle que le flux la rend : ce qu'une réponse
     * Turbo Stream remplace après une copie ou un masquage, sans recharger la
     * page. Mêmes records, mêmes copies que `page()`, lus pour cette séance seule.
     *
     * @return FeedEntry
     */
    public function entryFor(User $viewer, ScheduledWorkout $session): array
    {
        $id = (int) $session->getId();

        return $this->entry(
            $viewer,
            $session,
            $this->records->forSessions([$session])[$id] ?? [],
            $this->workouts->findCopiesOfSessions([$id])[$id] ?? [],
        );
    }

    /**
     * L'auteur auquel restreindre le flux, d'après le filtre reçu : `moi`, ou
     * l'identifiant d'un ami. Tout le reste (inconnu, ancien ami, quelqu'un qui
     * n'en est pas un) se lit comme « tous » : un filtre hors droits n'est pas
     * une erreur, il ne filtre rien.
     */
    public function authorFor(User $viewer, string $filter): ?User
    {
        if ('moi' === $filter) {
            return $viewer;
        }

        foreach ($this->friendships->friendsOf($viewer) as $friend) {
            if ((string) $friend->getId() === $filter) {
                return $friend;
            }
        }

        return null;
    }

    /**
     * `$viewer` voit-il cette séance d'ami dans son flux ? La garde de la copie,
     * posée sur le périmètre même du flux : ce qu'on ne voit pas ne se copie pas.
     * Ses propres séances ne se copient pas depuis ici, elles sont déjà à lui.
     */
    public function canRead(User $viewer, ScheduledWorkout $session): bool
    {
        $owner = $session->getOwner();
        if (null === $owner || $owner->getId() === $viewer->getId() || !$this->friendships->areFriends($viewer, $owner)) {
            return false;
        }

        return 0 < (int) $this->sessions->feedQuery($viewer, [$owner])
            ->select('COUNT(s.id)')
            ->andWhere('s = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Le titre proposé à la copie : celui de la séance, suivi du prénom de
     * l'auteur (« Upper A (Léa) »), pour qu'on la reconnaisse dans sa
     * bibliothèque à côté de la sienne.
     */
    public static function copyTitle(ScheduledWorkout $session): string
    {
        $author = $session->getOwner()?->getDisplayName();

        return null === $author ? $session->getDisplayTitle() : sprintf('%s (%s)', $session->getDisplayTitle(), self::firstName($author));
    }

    /**
     * @param array<int, SessionRecord> $records
     * @param list<Workout>             $copies
     *
     * @return FeedEntry
     */
    private function entry(User $viewer, ScheduledWorkout $session, array $records, array $copies): array
    {
        $author = $session->getOwner() ?? throw new \LogicException('Séance du flux sans propriétaire.');
        $mine = $author->getId() === $viewer->getId();
        $summary = $this->metrics->summary($session);
        $duration = $this->metrics->durationSeconds($session);

        $exercises = [];
        $recordRows = [];
        $added = 0;
        $n = 0;

        foreach ($session->getLoggedExercises() as $logged) {
            if ($logged->isSkipped()) {
                continue;
            }

            $exercise = $logged->getExercise();
            $exerciseId = $exercise?->getId();
            $record = null !== $exerciseId ? ($records[$exerciseId] ?? null) : null;
            // Une couronne par exercice : la première ligne qui le porte.
            if (null !== $exerciseId) {
                unset($records[$exerciseId]);
            }

            $perso = null !== $exercise && null !== $exercise->getOwner();
            $addedToMine = !$mine && $perso
                && !$this->security->isGrantedForUser($viewer, ExerciseVoter::VIEW, $exercise);
            $added += $addedToMine ? 1 : 0;

            $exercises[] = [
                'n' => ++$n,
                'exercise' => $exercise,
                'name' => (string) $logged->getExerciseName(),
                'perso' => $perso,
                'addedToMine' => $addedToMine,
                'summary' => $this->summarize($logged),
                'rpe' => $this->rpe($logged),
                'record' => null !== $record,
            ];

            if (null !== $record) {
                $recordRows[] = [
                    'exercise' => $exercise,
                    'name' => (string) $logged->getExerciseName(),
                    'value' => 'load' === $record['metric']
                        ? sprintf('%s × %d', $this->units->weight((float) $record['weightKg']), $record['reps'])
                        : sprintf('%d rép. au poids du corps', $record['reps']),
                    'gain' => $record['gain'],
                ];
            }
        }

        $myCopy = null;
        $copiedBy = [];
        foreach ($copies as $copy) {
            $copier = $copy->getOwner();
            if (null === $copier || $copier->getId() === $author->getId()) {
                continue;
            }
            if ($mine) {
                $copiedBy[] = $copy;
            } elseif ($copier->getId() === $viewer->getId()) {
                // La plus récente : les copies arrivent de la plus ancienne.
                $myCopy = $copy;
            }
        }

        $date = $session->getScheduledDate();

        return [
            'session' => $session,
            'author' => $author,
            'mine' => $mine,
            'hidden' => $mine && $session->isHiddenFromFriends(),
            'dayKey' => $date->format('Y-m-d'),
            'dayLabel' => $this->dayLabel($date),
            'title' => $session->getDisplayTitle(),
            'durationMinutes' => null !== $duration ? (int) round($duration / 60) : null,
            'tonnageKg' => $summary['tonnageKg'] ?? 0.0,
            'exerciseCount' => $summary['exerciseCount'] ?? 0,
            'note' => '' !== trim((string) $session->getCompletionNotes()) ? $session->getCompletionNotes() : null,
            'exercises' => $exercises,
            'records' => $recordRows,
            'copiedBy' => $copiedBy,
            'myCopy' => $myCopy,
            'copyTitle' => self::copyTitle($session),
            'addedCount' => $added,
            'body' => $this->body($author, $summary),
        ];
    }

    /**
     * La carte musculaire d'une séance : ce que ses séries de travail ont
     * chargé, sur la silhouette de l'auteur : face puis dos, et `firstSide`,
     * le côté le plus chargé, que le petit écran montre seul d'abord. Null quand rien ne se peint (aucune
     * zone déclarée, ou rien que du « corps entier ») : une silhouette nue sur
     * une carte de flux n'apprendrait rien.
     *
     * @param array{setsByArea: array<string, int>, unmappedSets: int}|null $summary
     *
     * @return FeedBody|null
     */
    private function body(User $author, ?array $summary): ?array
    {
        if (null === $summary) {
            return null;
        }

        $load = $this->bodyLoad->for($summary['setsByArea'], $summary['unmappedSets']);
        if ([] === $load['areas']) {
            return null;
        }

        $setsBySlug = [];
        foreach ($load['areas'] as $item) {
            // « Corps entier » n'a pas de muscle en face (BodyLoad l'écarte déjà).
            $slug = BodyLoad::AREA_TO_SLUG[$item['area']->value] ?? null;
            if (null !== $slug) {
                $setsBySlug[$slug] = $item['sets'];
            }
        }

        $silhouette = $author->getBodySilhouette();
        $plates = [];
        $weights = [];
        foreach ([BodySide::FRONT, BodySide::BACK] as $side) {
            $plate = $this->bodyPlates->plate($silhouette, $side);
            $plates[] = [
                'key' => $silhouette->value.'-'.$side->value,
                'side' => $side->value,
                'viewBox' => $plate['viewBox'],
            ];
            // Les séries des muscles que cette planche montre. Ceux qui se voient
            // des deux côtés (épaules, triceps, mollets…) comptent pour les deux
            // et s'annulent : la face gagne par les pectoraux, les abdos, les
            // quadriceps ; le dos par le haut du dos, les lombaires, les fessiers.
            $weights[] = array_sum(array_intersect_key($setsBySlug, array_flip(array_column($plate['muscles'], 'slug'))));
        }

        // Sur un petit écran, une seule planche, la plus chargée ; l'autre se
        // retourne. À égalité, la face : c'est le sens de lecture d'un corps.
        return [
            'load' => $load,
            'plates' => $plates,
            'firstSide' => $weights[1] > $weights[0] ? BodySide::BACK->value : BodySide::FRONT->value,
        ];
    }

    /**
     * Ce qui a été fait, en une ligne : « 4 × 6 · 92,5 kg », « 3 × 8 · PDC »,
     * « 2 × 45 s », ou « 4 séries · jusqu'à 92,5 kg » quand les séries
     * diffèrent. Lu sur les séries de TRAVAIL chiffrées, la définition du volume
     * (`LoggedSet::countsAsWorking()`) ; un exercice qui n'a que de
     * l'échauffement ou des séries sans valeur dit seulement son nombre de séries.
     */
    private function summarize(LoggedExercise $logged): string
    {
        $sets = array_values(array_filter(
            $logged->getLoggedSets()->toArray(),
            static fn (LoggedSet $set): bool => $set->countsAsWorking(),
        ));

        if ([] === $sets) {
            $count = $logged->getLoggedSets()->count();

            return sprintf('%d série%s', $count, $count > 1 ? 's' : '');
        }

        $count = \count($sets);
        $reps = array_filter(array_map(static fn (LoggedSet $s): int => $s->getReps() ?? 0, $sets));
        $durations = array_filter(array_map(static fn (LoggedSet $s): int => $s->getDurationSeconds() ?? 0, $sets));
        $weights = array_map(static fn (LoggedSet $s): float => $s->getWeightKg() ?? 0.0, $sets);
        $hasReps = [] !== $reps;

        // Des séries inégales se lisent en fourchette (« 4 × 9-12 »), pas en
        // simple compte : on voit ce qui a été fait sans détailler chaque série.
        if ($hasReps) {
            $low = min($reps);
            $high = max($reps);
            $head = $low === $high ? sprintf('%d × %d', $count, $low) : sprintf('%d × %d-%d', $count, $low, $high);
        } elseif ([] === $durations) {
            $head = sprintf('%d séries', $count);
        } else {
            $low = min($durations);
            $high = max($durations);
            $head = match (true) {
                $low === $high => sprintf('%d × %s', $count, $this->seconds($low)),
                $high < 60 => sprintf('%d × %d-%d s', $count, $low, $high),
                default => sprintf('%d × %s-%s', $count, $this->seconds($low), $this->seconds($high)),
            };
        }

        $top = max($weights);
        if ($top <= 0) {
            return $hasReps ? $head.' · PDC' : $head;
        }

        return 1 === \count(array_unique(array_map('strval', $weights)))
            ? $head.' · '.$this->units->weight($top)
            : $head.' · jusqu\'à '.$this->units->weight($top);
    }

    /** Le RPE moyen des séries de travail qui en portent un, s'il y en a. */
    private function rpe(LoggedExercise $logged): ?string
    {
        $values = [];
        foreach ($logged->getLoggedSets() as $set) {
            if ($set->countsAsWorking() && null !== $set->getRpe()) {
                $values[] = $set->getRpe();
            }
        }

        if ([] === $values) {
            return null;
        }

        $average = round(array_sum($values) / \count($values), 1);

        return 'RPE '.str_replace('.', ',', (string) $average);
    }

    private function seconds(int $seconds): string
    {
        return $seconds < 60 ? $seconds.' s' : $this->units->duration($seconds);
    }

    /** « Aujourd'hui · ven. 20 nov. », « Hier · jeu. 19 nov. », « mer. 18 nov. ». */
    private function dayLabel(\DateTimeImmutable $date): string
    {
        $short = sprintf(
            '%s %d %s',
            self::DAYS[(int) $date->format('N') - 1],
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n') - 1],
        );

        $today = new \DateTimeImmutable('today');
        $day = $date->format('Y-m-d');

        return match ($day) {
            $today->format('Y-m-d') => 'Aujourd\'hui · '.$short,
            $today->modify('-1 day')->format('Y-m-d') => 'Hier · '.$short,
            default => $short,
        };
    }

    /** Le premier mot d'un nom affiché : « Léa » pour « Léa Martin ». */
    private static function firstName(string $displayName): string
    {
        $first = strtok(trim($displayName), ' ');

        return false === $first ? $displayName : $first;
    }
}
