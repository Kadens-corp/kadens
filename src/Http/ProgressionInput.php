<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\ProgressionField;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Ce que l'outil de progression d'un plan reçoit (#110) : quel exercice de quelle
 * séance, quelle valeur, sur quelles semaines, de combien par semaine, et
 * éventuellement d'où l'on part.
 *
 * Le même formulaire sert à l'aperçu (GET) et à l'application (POST) : les deux
 * passent par `parse()`, donc ce qu'on applique est exactement ce qu'on a vu.
 * Les nombres se saisissent à la française (« 2,5 », « +1 ») dans un champ texte :
 * un champ `number` refuse la virgule selon le navigateur.
 */
final class ProgressionInput
{
    /** Plafonds du pas hebdomadaire : au-delà, c'est une faute de frappe, pas un programme. */
    private const float MAX_WEIGHT_STEP = 50.0;
    private const int MAX_REPS_STEP = 20;
    private const float MAX_WEIGHT = 1000.0;
    private const int MAX_REPS = 1000;

    public function __construct(
        public readonly int $rootId,
        public readonly int $exerciseId,
        public readonly ProgressionField $field,
        public readonly int $fromWeek,
        public readonly int $toWeek,
        public readonly float $step,
        /** Valeur visée la première semaine ; null = celle que la séance prescrit déjà. */
        public readonly ?float $start,
    ) {
    }

    /** Valeur du sélecteur d'exercice : la séance d'origine et l'exercice, ensemble. */
    public static function targetKey(int $rootId, int $exerciseId): string
    {
        return $rootId.'-'.$exerciseId;
    }

    /**
     * @throws \InvalidArgumentException avec un message lisible par l'utilisateur
     */
    public static function parse(ParameterBag $bag, int $weeks): self
    {
        if (1 !== preg_match('/^(\d+)-(\d+)$/', trim((string) $bag->get('target', '')), $m)) {
            throw new \InvalidArgumentException('Choisis l\'exercice à faire progresser.');
        }

        $field = ProgressionField::tryFrom((string) $bag->get('field', ''));
        if (null === $field) {
            throw new \InvalidArgumentException('Choisis la charge ou les répétitions.');
        }

        $from = (int) $bag->get('from', 0);
        $to = (int) $bag->get('to', 0);
        if ($from < 1 || $to > $weeks || $from > $to) {
            throw new \InvalidArgumentException('Choisis une plage de semaines du plan, la première avant la dernière.');
        }

        $step = self::number((string) $bag->get('step', ''));
        if (null === $step) {
            throw new \InvalidArgumentException('Indique de combien progresser chaque semaine, par exemple 2,5.');
        }

        $startText = trim((string) $bag->get('start', ''));
        $start = '' === $startText ? null : self::number($startText);
        if ('' !== $startText && null === $start) {
            throw new \InvalidArgumentException('La valeur de départ doit être un nombre, par exemple 60.');
        }

        if (ProgressionField::REPS === $field) {
            if (floor($step) !== $step || (null !== $start && floor($start) !== $start)) {
                throw new \InvalidArgumentException('Les répétitions se comptent en nombres entiers.');
            }
            if (abs($step) > self::MAX_REPS_STEP) {
                throw new \InvalidArgumentException(\sprintf('Un pas de %d répétitions par semaine au plus.', self::MAX_REPS_STEP));
            }
            if (null !== $start && ($start < 1 || $start > self::MAX_REPS)) {
                throw new \InvalidArgumentException('Le départ doit être d\'au moins une répétition.');
            }
        } else {
            if (abs($step) > self::MAX_WEIGHT_STEP) {
                throw new \InvalidArgumentException(\sprintf('Un pas de %d kg par semaine au plus.', (int) self::MAX_WEIGHT_STEP));
            }
            if (null !== $start && ($start < 0 || $start > self::MAX_WEIGHT)) {
                throw new \InvalidArgumentException('La charge de départ doit être positive.');
            }
        }

        return new self((int) $m[1], (int) $m[2], $field, $from, $to, $step, $start);
    }

    /** « 2,5 », « +2.5 », « -1 », « 1 000 » ; null si ce n'est pas un nombre. */
    private static function number(string $text): ?float
    {
        $normalized = str_replace([',', ' ', "\u{a0}", "\u{202f}"], ['.', '', '', ''], trim($text));

        return 1 === preg_match('/^[+-]?\d+(\.\d+)?$/', $normalized) ? (float) $normalized : null;
    }
}
