<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Enum\TargetArea;
use App\Service\SharedWorkoutCopier;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La copie d'une séance chez quelqu'un d'autre (#58).
 *
 * Ce que ces tests protègent : qu'une copie ne référence jamais un exercice que
 * son nouveau propriétaire ne peut pas lire — sans quoi la clé étrangère RESTRICT
 * lierait deux inconnus, l'auteur ne pouvant plus supprimer son exercice — et,
 * à l'inverse, qu'elle ne recopie rien d'inutile : la globale, ses propres
 * exercices et ceux d'une relation de coaching acceptée restent référencés.
 */
final class SharedWorkoutCopierTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private SharedWorkoutCopier $copier;
    private User $author;
    private User $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->copier = static::getContainer()->get(SharedWorkoutCopier::class);

        $this->purgeDatabase($this->em);

        $this->author = $this->user('author@example.com');
        $this->reader = $this->user('reader@example.com');
    }

    public function testTheCopyBelongsToTheRecipientAndLeavesTheSourceUntouched(): void
    {
        $squat = $this->exercise(null, 'Squat');
        $source = $this->workout($this->author, 'Upper A', [$squat]);
        $source->setNotes('Brouillon privé');
        $this->em->flush();

        $copy = $this->copier->copyFor($source, $this->reader)->workout;
        $this->em->flush();

        self::assertNotSame($source->getId(), $copy->getId());
        self::assertSame($this->reader, $copy->getOwner());
        // Titre gardé : elle change de bibliothèque, pas de nom.
        self::assertSame('Upper A', $copy->getTitle());
        // Séance de bibliothèque, sans lignée : ce n'est pas une progression.
        self::assertFalse($copy->isPlanLocal());
        self::assertNull($copy->getOrigin());
        // Le bloc-notes privé ne voyage pas.
        self::assertNull($copy->getNotes());
        self::assertSame($this->author, $source->getOwner());
        self::assertSame($squat, $this->prescribedExercises($source)[0]);
    }

    public function testGlobalAndOwnExercisesStayReferenced(): void
    {
        $global = $this->exercise(null, 'Squat');
        $own = $this->exercise($this->reader, 'Pompes lestées');
        $source = $this->workout($this->author, 'Mixte', [$global, $own]);

        $result = $this->copier->copyFor($source, $this->reader);

        self::assertSame([$global, $own], $this->prescribedExercises($result->workout));
        self::assertSame(0, $result->copiedExercises);
    }

    /**
     * Le coach qui donne sa séance à son athlète : la relation acceptée ouvre déjà
     * ses exercices à l'athlète, les recopier remplirait sa bibliothèque de doublons.
     */
    public function testACoachExerciseStaysReferencedForHisAthlete(): void
    {
        $coachExercise = $this->exercise($this->author, 'Tirage serré');
        $source = $this->workout($this->author, 'Dos', [$coachExercise]);
        $this->coaching($this->author, $this->reader);

        $result = $this->copier->copyFor($source, $this->reader);

        self::assertSame([$coachExercise], $this->prescribedExercises($result->workout));
        self::assertSame(0, $result->copiedExercises);
    }

    public function testAStrangerExerciseIsCopiedIntoTheRecipientLibrary(): void
    {
        $foreign = $this->exercise($this->author, 'Tirage serré')
            ->setNameEn('Close-grip row')
            ->setDescription('Coudes serrés.')
            ->setTargetAreas([TargetArea::BACK])
            ->setMediaUrl('https://example.com/row');
        $source = $this->workout($this->author, 'Dos', [$foreign]);

        $result = $this->copier->copyFor($source, $this->reader);
        $this->em->flush();

        $copied = $this->prescribedExercises($result->workout)[0];
        self::assertNotSame($foreign, $copied);
        self::assertSame($this->reader, $copied->getOwner());
        self::assertSame('Tirage serré', $copied->getName());
        self::assertSame('Close-grip row', $copied->getNameEn());
        self::assertSame('Coudes serrés.', $copied->getDescription());
        self::assertSame(ActivityType::GYM, $copied->getActivity());
        self::assertSame([TargetArea::BACK], $copied->getTargetAreas());
        self::assertSame('https://example.com/row', $copied->getMediaUrl());
        self::assertNull($copied->getRefKey());
        self::assertSame(1, $result->copiedExercises);
    }

    public function testAnExerciseUsedTwiceIsCopiedOnce(): void
    {
        $foreign = $this->exercise($this->author, 'Tirage serré');
        $source = $this->workout($this->author, 'Dos', [$foreign, $foreign]);

        $result = $this->copier->copyFor($source, $this->reader);

        [$first, $second] = $this->prescribedExercises($result->workout);
        self::assertSame($first, $second);
        self::assertSame(1, $result->copiedExercises);
    }

    /** Copier trois séances du même auteur ne crée pas trois « Tirage serré ». */
    public function testAnExistingHomonymInTheRecipientLibraryIsReused(): void
    {
        $foreign = $this->exercise($this->author, 'Tirage serré');
        $homonym = $this->exercise($this->reader, 'Tirage serré');
        $source = $this->workout($this->author, 'Dos', [$foreign]);

        $result = $this->copier->copyFor($source, $this->reader);

        self::assertSame([$homonym], $this->prescribedExercises($result->workout));
        self::assertSame(0, $result->copiedExercises);
    }

    /** Un homonyme d'une autre activité n'est pas le même exercice. */
    public function testAHomonymOfAnotherActivityIsNotReused(): void
    {
        $foreign = $this->exercise($this->author, 'Fractionné');
        $this->exercise($this->reader, 'Fractionné', ActivityType::RUNNING);
        $source = $this->workout($this->author, 'Mix', [$foreign]);

        $result = $this->copier->copyFor($source, $this->reader);

        $copied = $this->prescribedExercises($result->workout)[0];
        self::assertSame(ActivityType::GYM, $copied->getActivity());
        self::assertSame(1, $result->copiedExercises);
    }

    public function testAnExplicitCopyRemembersItsSource(): void
    {
        $source = $this->workout($this->author, 'Upper A', [$this->exercise(null, 'Squat')]);

        $copy = $this->copier->copyFor($source, $this->reader)->workout;

        self::assertSame($source, $copy->getCopiedFrom());
    }

    /**
     * La pose implicite (séance du coach dans le plan de l'athlète) reprend la
     * même copie : avant le flush, par la mémoire de la requête, et après, par
     * `copiedFrom` en base.
     */
    public function testLibraryCopyIsCreatedOnceThenReused(): void
    {
        $source = $this->workout($this->author, 'Upper A', [$this->exercise(null, 'Squat')]);

        $first = $this->copier->libraryCopyFor($source, $this->reader);
        self::assertSame($first, $this->copier->libraryCopyFor($source, $this->reader), 'Même requête, avant le flush.');
        $this->em->flush();

        $this->copier->reset();
        self::assertSame($first, $this->copier->libraryCopyFor($source, $this->reader), 'Requête suivante, retrouvée en base.');
        self::assertCount(1, $this->em->getRepository(Workout::class)->findBy(['owner' => $this->reader]));
    }

    public function testOwnWorkoutNeedsNoLibraryCopy(): void
    {
        $own = $this->workout($this->reader, 'Upper A', [$this->exercise(null, 'Squat')]);

        self::assertSame($own, $this->copier->libraryCopyFor($own, $this->reader));
    }

    /** Un plan de douze cases avec le même exercice perso n'en recopie qu'un. */
    public function testAForeignExerciseIsCopiedOnceAcrossSeveralWorkoutsOfTheSameRequest(): void
    {
        $foreign = $this->exercise($this->author, 'Tirage serré');
        $first = $this->copier->copyFor($this->workout($this->author, 'Dos A', [$foreign]), $this->reader);
        $second = $this->copier->copyFor($this->workout($this->author, 'Dos B', [$foreign]), $this->reader);

        self::assertSame(1, $first->copiedExercises);
        self::assertSame(0, $second->copiedExercises);
        self::assertSame($this->prescribedExercises($first->workout)[0], $this->prescribedExercises($second->workout)[0]);
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function exercise(?User $owner, string $name, ActivityType $activity = ActivityType::GYM): Exercise
    {
        $exercise = (new Exercise())->setOwner($owner)->setName($name)->setActivity($activity);
        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    /** @param list<Exercise> $exercises */
    private function workout(User $owner, string $title, array $exercises): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)).'-'.bin2hex(random_bytes(3)));

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        foreach ($exercises as $position => $exercise) {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition($position)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)->setReps(10)
            );
        }
        $workout->addBlock($block);

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function coaching(User $coach, User $athlete): void
    {
        $this->em->persist(
            (new Coaching())
                ->setCoach($coach)
                ->setAthlete($athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($coach)
                ->setRespondedAt(new \DateTimeImmutable())
        );
        $this->em->flush();
    }

    /** @return list<Exercise> */
    private function prescribedExercises(Workout $workout): array
    {
        $exercises = [];
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $exercise = $prescribed->getExercise();
                self::assertNotNull($exercise);
                $exercises[] = $exercise;
            }
        }

        return $exercises;
    }
}
