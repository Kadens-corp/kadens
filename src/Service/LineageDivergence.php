<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;

/**
 * Ce qu'une case de plan prescrit, comparé à une référence (#93). Deux questions,
 * deux références, parce que ce sont deux besoins distincts :
 *
 * 1. **La case a-t-elle été ajustée depuis la fois d'avant ?** (`repeatsInPlan`)
 *    Dans un plan qui progresse, s'écarter de la séance de bibliothèque est la
 *    norme : un repère « modifiée » par rapport à elle s'allumerait partout. La
 *    référence utile est la case PRÉCÉDENTE de la même lignée dans le plan, et le
 *    repère signale l'exception : la case qui la RÉPÈTE à l'identique (« = S11 »).
 *    Un plan construit par copie de semaine naît marqué partout, chaque case
 *    ajustée perd son repère ; une fois le plan fini, ne restent marquées que les
 *    cases qui ne progressent pas (décharge, entretien).
 *
 * 2. **Le titre dit-il encore ce que contient la case ?** (`titleMayBeStale`)
 *    Une case garde le titre de sa racine. Il ne ment que s'il décrit une
 *    quantité (« Fractionné 6 x 400 m », « Footing 40 min ») et que le contenu a
 *    quitté celui de la racine. « Upper A » reste vrai à 100 kg comme à 80 : un
 *    titre sans chiffre ne déclenche jamais le rappel. Heuristique assumée.
 *
 * L'empreinte compare le CONTENU PRESCRIT, et lui seul : blocs (rôle, tours,
 * libellé), exercices dans leur ordre (exercice, type, superset, toutes les
 * valeurs, notes) et séries détaillées. Ni le titre, ni la description, ni les
 * identifiants ou positions brutes, seulement l'ordre qu'elles donnent.
 */
final class LineageDivergence
{
    /**
     * Les cases qui répètent à l'identique la case précédente de leur lignée dans
     * le plan, indexées par id de leur copie locale, avec la case répétée.
     *
     * « Précédente » : dans l'ordre semaine, jour, puis ordre de la case. Une
     * lignée posée deux fois dans la même semaine se compare donc à sa première
     * pose de la semaine. La première pose d'une lignée n'a pas de référence.
     *
     * @return array<int, array{week: int, day: int}>
     */
    public function repeatsInPlan(PlanTemplate $template): array
    {
        $items = [];
        foreach (array_values($template->getPlanItems()->toArray()) as $index => $item) {
            $items[] = ['item' => $item, 'key' => [(int) $item->getWeekNumber(), (int) $item->getDayOfWeek(), $index]];
        }
        usort($items, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        /** @var array<int, array{item: PlanItem, print: string}> $lastByRoot */
        $lastByRoot = [];
        $repeats = [];
        foreach ($items as ['item' => $item]) {
            $workout = $item->getWorkout();
            $workoutId = $workout?->getId();
            $rootId = $workout?->getOrigin()?->getId();
            if (null === $workout || null === $workoutId || !$workout->isPlanLocal() || null === $rootId) {
                continue;
            }

            $print = self::fingerprint($workout);
            $previous = $lastByRoot[$rootId] ?? null;
            if (null !== $previous && $previous['print'] === $print) {
                $repeats[$workoutId] = [
                    'week' => (int) $previous['item']->getWeekNumber(),
                    'day' => (int) $previous['item']->getDayOfWeek(),
                ];
            }
            $lastByRoot[$rootId] = ['item' => $item, 'print' => $print];
        }

        return $repeats;
    }

    /**
     * Le titre de la case est-il peut-être faux ? Vrai quand elle porte encore le
     * titre de sa racine, que ce titre contient un chiffre, et que le contenu
     * n'est plus celui de la racine. Faux hors plan ou sans racine (SET NULL).
     */
    public function titleMayBeStale(Workout $workout): bool
    {
        $root = $workout->getOrigin();
        if (!$workout->isPlanLocal() || null === $root) {
            return false;
        }

        $title = (string) $workout->getTitle();

        return $title === $root->getTitle()
            && 1 === preg_match('/\d/', $title)
            && self::fingerprint($root) !== self::fingerprint($workout);
    }

    /**
     * Empreinte du contenu prescrit d'une séance. Deux séances de même empreinte
     * prescrivent la même chose.
     */
    public static function fingerprint(Workout $workout): string
    {
        $blocks = $workout->getBlocks()->toArray();
        usort($blocks, static fn (Block $a, Block $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));

        $parts = [];
        foreach ($blocks as $block) {
            $parts[] = ['block', $block->getRole()?->value, $block->getRounds(), $block->getLabel()];

            $exercises = $block->getPrescribedExercises()->toArray();
            usort($exercises, static fn (PrescribedExercise $a, PrescribedExercise $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));

            foreach ($exercises as $prescribed) {
                $parts[] = [
                    'exo',
                    $prescribed->getExercise()?->getId(),
                    $prescribed->getPrescriptionType()?->value,
                    $prescribed->getSupersetGroup(),
                    $prescribed->getSets(),
                    $prescribed->getReps(),
                    self::number($prescribed->getWeightKg()),
                    $prescribed->getDurationSeconds(),
                    $prescribed->getDistanceMeters(),
                    $prescribed->getPaceSecondsPerKm(),
                    $prescribed->getTargetReps(),
                    $prescribed->getCapSeconds(),
                    $prescribed->getIntensityZone(),
                    $prescribed->getElevationGainMeters(),
                    $prescribed->getRpe(),
                    $prescribed->getRestSeconds(),
                    $prescribed->getNotes(),
                ];

                $sets = $prescribed->getDetailedSets()->toArray();
                usort($sets, static fn (PrescribedSet $a, PrescribedSet $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));
                foreach ($sets as $set) {
                    $parts[] = ['set', $set->getSetType()->value, $set->getReps(), self::number($set->getWeightKg()), $set->getDurationSeconds()];
                }
            }
        }

        return hash('xxh128', json_encode($parts, \JSON_THROW_ON_ERROR));
    }

    /** Forme textuelle stable d'une charge : le JSON d'un flottant ne doit pas faire diverger deux valeurs égales au gramme près. */
    private static function number(?float $value): ?string
    {
        return null === $value ? null : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
