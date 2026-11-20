<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Exercise;
use App\Service\ExerciseImageStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Efface du disque les images qu'un exercice ne cite plus (#38) : celles
 * retirées de sa liste, et toutes celles d'un exercice supprimé.
 *
 * En deux temps, comme `GoalPhotoCleanupListener` : repérées au `onFlush`,
 * effacées au `postFlush`, une fois la transaction validée. Un flush qui échoue
 * ne laisse jamais un exercice pointer une image déjà partie.
 *
 * Même limite connue : une suppression faite par la base seule (compte supprimé,
 * clé étrangère en CASCADE) laisse ses fichiers, inertes.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ExerciseImageCleanupListener
{
    /** @var list<string> */
    private array $pending = [];

    public function __construct(
        private readonly ExerciseImageStorage $storage,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Exercise) {
                array_push($this->pending, ...$entity->getImages());
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Exercise) {
                continue;
            }

            $change = $unitOfWork->getEntityChangeSet($entity)['images'] ?? null;
            if (!\is_array($change)) {
                continue;
            }

            array_push($this->pending, ...array_values(array_diff(self::files($change[0] ?? null), self::files($change[1] ?? null))));
        }
    }

    public function postFlush(): void
    {
        $files = $this->pending;
        $this->pending = [];

        foreach ($files as $file) {
            $this->storage->delete($file);
        }
    }

    /**
     * @return list<string>
     */
    private static function files(mixed $images): array
    {
        return \is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
    }
}
