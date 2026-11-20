<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Block;
use App\Entity\LoggedExercise;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Copier dans mes séances » depuis le flux d'amis (#126) : une séance FAITE
 * d'un ami devient une séance de bibliothèque chez soi, **telle qu'il l'a
 * faite** — séries, répétitions et charges du réalisé, pas de son prescrit.
 *
 * Trois règles, toutes héritées :
 *
 * - **Le réalisé devient du prescrit comme partout ailleurs.** La structure de
 *   la séance d'origine est clonée (`WorkoutCloner`), puis chaque exercice fait
 *   y reporte ses séries par `LineageSeed::applyLogged()` ; un exercice fait hors
 *   programme est ajouté par `LineageSeed::prescribeFromLog()`, en fin de
 *   séance. Une séance libre, sans séance d'origine, devient un bloc principal
 *   de ses exercices faits. Une seule définition de « reprendre ce qui a été
 *   fait », celle de la reprogrammation.
 * - **Ce qui n'a pas été fait ne part pas.** Un exercice de force passé ou
 *   jamais consigné sort de la copie (« telle qu'il l'a faite »). Ce qui ne se
 *   logue pas (un footing d'échauffement, une mobilité en durée libre) reste
 *   tel que prescrit : rien ne dit qu'il n'a pas eu lieu.
 * - **Jamais de référence à un exercice qu'on ne peut pas lire** : les exercices
 *   perso de l'ami sont recopiés dans sa bibliothèque
 *   (`SharedWorkoutCopier::rebindExercises()`), homonyme réutilisé.
 *
 * La copie garde un repère vers la séance source (`Workout.copiedFromSession`),
 * ni lignée ni droit : c'est ce qui écrit « Copiée par » chez l'auteur. Rien ne
 * lui est envoyé. Le bloc-notes privé ne suit pas (`WorkoutCloner`), le
 * ressenti de l'ami non plus : il décrit sa séance, pas la tienne.
 *
 * Persiste, ne flushe pas.
 */
final readonly class FriendSessionCopier
{
    private const LOGGABLE = [PrescriptionType::SETS_REPS, PrescriptionType::SETS_TIME];

    public function __construct(
        private WorkoutCloner $cloner,
        private LineageSeed $lineageSeed,
        private SharedWorkoutCopier $sharedCopier,
        private SupersetGrouper $supersetGrouper,
        private SlugGenerator $slugGenerator,
        private WorkoutEstimator $estimator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function copy(ScheduledWorkout $source, User $recipient, string $title): SharedWorkoutCopy
    {
        $base = $source->getWorkout();
        $offProgram = [];

        if (null !== $base && !$base->getBlocks()->isEmpty()) {
            /** @var \SplObjectStorage<PrescribedExercise, PrescribedExercise> $map */
            $map = new \SplObjectStorage();
            $copy = $this->cloner->cloneWorkout($base, $recipient, $title, false, $map);

            /** @var \SplObjectStorage<PrescribedExercise, true> $done */
            $done = new \SplObjectStorage();
            foreach ($source->getLoggedExercises() as $logged) {
                $prescribed = $logged->getSourcePrescribedExercise();
                if (null !== $prescribed && $map->offsetExists($prescribed)) {
                    if (!$logged->isSkipped()) {
                        $this->lineageSeed->applyLogged($map[$prescribed], $logged);
                        $done[$map[$prescribed]] = true;
                    }
                } elseif (!$logged->isSkipped()) {
                    $offProgram[] = $logged;
                }
            }

            $this->dropUndone($copy, $done);
        } else {
            $copy = (new Workout())
                ->setOwner($recipient)
                ->setTitle($title)
                ->setSlug($this->slugGenerator->generate($title, Workout::class));
            $this->entityManager->persist($copy);

            foreach ($source->getLoggedExercises() as $logged) {
                if (!$logged->isSkipped()) {
                    $offProgram[] = $logged;
                }
            }
        }

        $this->appendOffProgram($copy, $offProgram);

        $copy->setCopiedFromSession($source);
        $copy->setEstimatedDurationMinutes($this->estimator->estimateMinutes($copy));

        return new SharedWorkoutCopy($copy, $this->sharedCopier->rebindExercises($copy, $recipient));
    }

    /**
     * Retire de la copie les exercices de force que l'ami n'a pas faits, puis
     * les blocs vidés. Les supersets se rétablissent d'eux-mêmes : un groupe
     * réduit à un membre se dissout (`SupersetGrouper::normalize()`).
     *
     * @param \SplObjectStorage<PrescribedExercise, true> $done
     */
    private function dropUndone(Workout $copy, \SplObjectStorage $done): void
    {
        foreach ($copy->getBlocks()->toArray() as $block) {
            foreach ($block->getPrescribedExercises()->toArray() as $prescribed) {
                if ($done->offsetExists($prescribed) || !\in_array($prescribed->getPrescriptionType(), self::LOGGABLE, true)) {
                    continue;
                }
                $block->removePrescribedExercise($prescribed);
                $this->entityManager->remove($prescribed);
            }

            if ($block->getPrescribedExercises()->isEmpty()) {
                $copy->removeBlock($block);
                $this->entityManager->remove($block);

                continue;
            }

            $this->supersetGrouper->normalize($block);
        }
    }

    /**
     * Les exercices faits hors programme, à la suite du dernier bloc principal
     * (un bloc principal neuf s'il n'y en a pas), dans l'ordre où ils ont été
     * faits. Un exercice supprimé de la bibliothèque depuis ne peut plus se
     * prescrire : il ne reste que son nom, il ne part pas.
     *
     * @param list<LoggedExercise> $logged
     */
    private function appendOffProgram(Workout $copy, array $logged): void
    {
        $prescriptions = array_values(array_filter(array_map(
            fn (LoggedExercise $entry): ?PrescribedExercise => $this->lineageSeed->prescribeFromLog($entry),
            $logged,
        )));
        if ([] === $prescriptions) {
            return;
        }

        $target = null;
        $lastPosition = -1;
        foreach ($copy->getBlocks() as $block) {
            $lastPosition = max($lastPosition, (int) $block->getPosition());
            if (BlockRole::MAIN === $block->getRole()) {
                $target = $block;
            }
        }

        if (null === $target) {
            $target = (new Block())
                ->setRole(BlockRole::MAIN)
                ->setRounds(1)
                ->setPosition($lastPosition + 1);
            $copy->addBlock($target);
            $this->entityManager->persist($target);
        }

        $position = -1;
        foreach ($target->getPrescribedExercises() as $existing) {
            $position = max($position, (int) $existing->getPosition());
        }

        foreach ($prescriptions as $prescribed) {
            $prescribed->setPosition(++$position);
            $target->addPrescribedExercise($prescribed);
            $this->entityManager->persist($prescribed);
        }
    }
}
