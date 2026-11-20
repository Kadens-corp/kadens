<?php

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Repository\ExerciseRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\ExerciseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Copie une séance dans la bibliothèque d'un AUTRE utilisateur (#58) : le coach
 * qui donne une de ses séances à son athlète, le visiteur qui copie une séance
 * partagée par son lien public, la séance du coach posée dans le plan de son
 * athlète, les racines d'un plan copié ou transféré.
 *
 * La copie elle-même est celle de `WorkoutCloner` (séance de bibliothèque, sans
 * lignée, titre gardé : elle change de bibliothèque, pas de nom), marquée
 * `copiedFrom`. Ce service y ajoute une règle, sur les exercices :
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
final class SharedWorkoutCopier implements ResetInterface
{
    /*
     * Mémoire de la requête, par destinataire puis par id source. Indispensable,
     * pas une optimisation : rien n'est flushé ici, et une requête ne voit pas ce
     * qui n'est pas encore en base. Sans elle, un plan de douze semaines qui pose
     * douze fois « Upper A » créerait douze copies de la séance, et douze fois
     * chacun de ses exercices perso.
     */

    /** @var array<int, array<int, Workout>> */
    private array $libraryCopies = [];

    /** @var array<int, array<int, Exercise>> */
    private array $exerciseCopies = [];

    public function __construct(
        private readonly WorkoutCloner $cloner,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly WorkoutRepository $workoutRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly ExerciseImageStorage $images,
    ) {
    }

    /**
     * Une copie neuve, à chaque appel : c'est le geste explicite (« Ajouter à sa
     * bibliothèque », « Copier dans mes séances »). Deux clics, deux copies.
     */
    public function copyFor(Workout $source, User $recipient): SharedWorkoutCopy
    {
        $copy = $this->cloner->cloneWorkout($source, $recipient, $source->getTitle(), false)
            ->setCopiedFrom($source);

        return new SharedWorkoutCopy($copy, $this->rebindExercises($copy, $recipient));
    }

    /**
     * La copie de `$source` dans la bibliothèque de `$recipient`, créée au premier
     * besoin puis réutilisée : c'est le geste implicite, la séance du coach posée
     * dans le plan de son athlète, ou la racine d'un plan copié. Une seule copie
     * par séance, donc une seule lignée chez le destinataire.
     *
     * Sa propre séance se rend telle quelle : il n'y a rien à copier.
     */
    public function libraryCopyFor(Workout $source, User $recipient): Workout
    {
        if ($source->getOwner() === $recipient) {
            return $source;
        }

        $recipientId = (int) $recipient->getId();
        $sourceId = (int) $source->getId();

        return $this->libraryCopies[$recipientId][$sourceId]
            ??= $this->workoutRepository->findLibraryCopy($source, $recipient)
            ?? $this->copyFor($source, $recipient)->workout;
    }

    /**
     * Applique la règle d'exercices à une séance déjà chez `$recipient` (copie
     * fraîche, ou copie locale d'un plan qui change de propriétaire).
     *
     * @return int le nombre d'exercices recopiés dans sa bibliothèque
     */
    public function rebindExercises(Workout $workout, User $recipient): int
    {
        $recipientId = (int) $recipient->getId();
        $copied = 0;

        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $exercise = $prescribed->getExercise();
                if (null === $exercise || $this->security->isGrantedForUser($recipient, ExerciseVoter::VIEW, $exercise)) {
                    continue;
                }

                $exerciseId = (int) $exercise->getId();
                if (!isset($this->exerciseCopies[$recipientId][$exerciseId])) {
                    [$own, $isNew] = $this->ownExerciseFor($exercise, $recipient);
                    $this->exerciseCopies[$recipientId][$exerciseId] = $own;
                    $copied += $isNew ? 1 : 0;
                }

                $prescribed->setExercise($this->exerciseCopies[$recipientId][$exerciseId]);
            }
        }

        return $copied;
    }

    public function reset(): void
    {
        $this->libraryCopies = [];
        $this->exerciseCopies = [];
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
            ->setMediaUrl($foreign->getMediaUrl())
            // Les images suivent, mais en fichiers neufs (#38) : partagé, un
            // fichier partirait du disque avec le premier des deux exercices
            // supprimé.
            ->setImages(array_values(array_filter(array_map($this->images->duplicate(...), $foreign->getImages()))));

        $this->entityManager->persist($own);

        return [$own, true];
    }
}
