<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Pose `addedBy` (#80) sur une séance, un plan ou une séance datée créés par
 * quelqu'un d'autre que leur propriétaire : en pratique, un coach chez son
 * athlète (`setOwner($athlete)`).
 *
 * **Pourquoi un écouteur et non un appel à chaque point de création.** Le coach
 * crée chez l'athlète par une dizaine de chemins (séance ou plan neufs, copie,
 * pose au calendrier, placement d'un plan, case posée dans la trame, copie de
 * bibliothèque depuis la palette, duplication, resync…) et il s'en ajoutera. Un
 * oubli ne casserait rien de visible, il ferait juste disparaître un badge.
 * Même raisonnement que `TombstoneListener`.
 *
 * La règle tient en une ligne : un utilisateur connecté, propriétaire connu et
 * différent de lui. Tout le reste en découle sans cas particulier : l'athlète
 * chez lui, le mobile (jeton du propriétaire), la console et les imports (pas
 * d'utilisateur) laissent `null` ; la copie depuis un lien public est posée chez
 * celui qui copie, donc `null` aussi. Le don d'un plan n'est pas une création :
 * `PlanTransfer` le marque lui-même.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class ContentAuthorListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Workout && !$entity instanceof PlanTemplate && !$entity instanceof ScheduledWorkout) {
            return;
        }

        if (null !== $entity->getAddedBy()) {
            return;
        }

        $author = $this->security->getUser();
        $owner = $entity->getOwner();
        if (!$author instanceof User || null === $owner || $owner->getId() === $author->getId()) {
            return;
        }

        $entity->setAddedBy($author);
    }
}
