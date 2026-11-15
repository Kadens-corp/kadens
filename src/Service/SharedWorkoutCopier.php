<?php

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Repository\ExerciseRepository;
use App\Security\Voter\ExerciseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Copie une séance dans la bibliothèque d'un AUTRE utilisateur (#58) : le coach
 * qui donne une de ses séances à son athlète, le visiteur qui copie une séance
 * partagée par son lien public.
 *
 * La copie elle-même est celle de `WorkoutCloner` (séance de bibliothèque, sans
 * lignée, titre gardé : elle change de bibliothèque, pas de nom). Ce service n'y
 * ajoute qu'une règle, sur les exercices :
 *
 * **Une copie ne référence jamais un exercice que son nouveau propriétaire ne
 * peut pas lire.** Un exercice perso de l'auteur, sans relation de coaching entre
 * les deux, est recopié dans la bibliothèque du destinataire. Ce n'est pas du
 * confort, c'est la clé étrangère `prescribed_exercise.exercise_id`, en RESTRICT :
 * référencer l'exercice d'un inconnu l'empêcherait de supprimer le sien, et le
 * destinataire ne pourrait pas en ouvrir la fiche (`ExerciseVoter::VIEW`).
 *
 * « Peut lire » est décidé par `ExerciseVoter` lui-même, interrogé au nom du
 * destinataire : la globale, ses propres exercices et ceux d'un coach ou d'un
 * athlète en relation acceptée restent référencés. Le coach qui donne une séance
 * à son athlète ne remplit donc pas sa bibliothèque de doublons, exactement comme
 * quand il compose chez lui avec ses propres exercices.
 *
 * Un exercice recopié réutilise d'abord un exercice perso du destinataire de même
 * nom et même activité : copier trois séances d'un même auteur ne crée pas trois
 * « Tirage serré ». Jamais de `refKey`, réservée à la globale (unique en base).
 *
 * Comme `WorkoutCloner` : persiste, ne flushe pas.
 */
final class SharedWorkoutCopier
{
    public function __construct(
        private readonly WorkoutCloner $cloner,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function copyFor(Workout $source, User $recipient): SharedWorkoutCopy
    {
        $copy = $this->cloner->cloneWorkout($source, $recipient, $source->getTitle(), false);

        /** @var \SplObjectStorage<Exercise, Exercise> $rebound exercice source → celui du destinataire */
        $rebound = new \SplObjectStorage();
        $copied = 0;

        foreach ($copy->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $exercise = $prescribed->getExercise();
                if (null === $exercise || $this->security->isGrantedForUser($recipient, ExerciseVoter::VIEW, $exercise)) {
                    continue;
                }

                if (!$rebound->offsetExists($exercise)) {
                    [$own, $isNew] = $this->ownExerciseFor($exercise, $recipient);
                    $rebound->offsetSet($exercise, $own);
                    $copied += $isNew ? 1 : 0;
                }

                $prescribed->setExercise($rebound->offsetGet($exercise));
            }
        }

        return new SharedWorkoutCopy($copy, $copied);
    }

    /**
     * L'exercice du destinataire qui tient lieu de `$foreign` : un homonyme de même
     * activité s'il en a un, sinon une copie neuve.
     *
     * @return array{Exercise, bool} l'exercice, et s'il vient d'être créé
     */
    private function ownExerciseFor(Exercise $foreign, User $recipient): array
    {
        $activity = $foreign->getActivity() ?? throw new \LogicException('Exercice sans activité.');

        $existing = $this->exerciseRepository->findOneBy([
            'owner' => $recipient,
            'name' => $foreign->getName(),
            'activity' => $activity,
        ]);
        if (null !== $existing) {
            return [$existing, false];
        }

        $own = (new Exercise())
            ->setOwner($recipient)
            ->setName((string) $foreign->getName())
            ->setNameEn($foreign->getNameEn())
            ->setDescription($foreign->getDescription())
            ->setActivity($activity)
            ->setTargetAreas($foreign->getTargetAreas())
            ->setMediaUrl($foreign->getMediaUrl());

        $this->entityManager->persist($own);

        return [$own, true];
    }
}
