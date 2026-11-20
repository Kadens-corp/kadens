<?php

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Entity\Workout;

/**
 * Partage par lien d'une séance ou d'un plan (#61). Privé par défaut : rien n'a
 * de page publique tant que son propriétaire n'a pas ouvert le partage.
 *
 * Le lien est un jeton aléatoire, jamais un dérivé du titre : il ne se devine
 * pas, ne dit rien du contenu et ne vieillit pas quand on renomme. Il vaut à lui
 * seul autorisation de lecture, comme le jeton du flux calendrier.
 *
 * Fermer = révoquer. Le jeton est effacé, donc l'adresse déjà envoyée tombe sur
 * exactement la même réponse qu'une adresse inventée (aucune confirmation que la
 * séance a existé), et rouvrir en tire un nouveau.
 *
 * Qui a le droit d'ouvrir ou de fermer (le propriétaire seul, jamais son coach)
 * se décide au contrôleur. Ne flushe pas.
 */
final class ShareLink
{
    /** Format d'un jeton (128 bits en hexadécimal), garde-fou des routes publiques. */
    public const TOKEN = '[0-9a-f]{32}';

    public function open(Workout|PlanTemplate $subject): void
    {
        // Une case de plan n'a jamais de lien à elle : elle se lit sous le lien
        // de son plan (`PublicShareController::planItem`).
        if ($subject instanceof Workout && $subject->isPlanLocal()) {
            throw new \LogicException('Une séance de plan se partage avec son plan.');
        }

        if (null === $subject->getShareToken()) {
            $subject->setShareToken(bin2hex(random_bytes(16)));
        }
    }

    public function close(Workout|PlanTemplate $subject): void
    {
        $subject->setShareToken(null);
    }
}
