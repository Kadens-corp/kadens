<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\LineageSeed;
use App\Service\OffProgramRecap;
use App\Service\WorkoutCloner;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ce que l'athlète a fait hors programme la dernière fois, au moment de
 * programmer la suite.
 *
 * Ce que ces tests protègent : que « hors programme » soit exactement ce que
 * l'analyse appelle ainsi (apparié par la source OU par l'exercice = pas hors
 * programme), que « la dernière fois » soit la dernière séance faite de la
 * lignée (celle dont la pose repartirait), et qu'un exercice déjà repris dans la
 * séance ne soit plus proposé.
 */
final class OffProgramRecapTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private OffProgramRecap $recap;
    private User $user;
    private Exercise $squat;
    private Exercise $curl;
    private Exercise $plank;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->recap = static::getContainer()->get(OffProgramRecap::class);
        $this->purgeDatabase($this->em);

        $this->user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->user);
        $this->squat = $this->exercise('Squat');
        $this->curl = $this->exercise('Curl biceps');
        $this->plank = $this->exercise('Gainage');
        $this->em->flush();
    }

    public function testAnExerciseAddedDuringTheSessionIsReported(): void
    {
        $library = $this->workout([$this->squat]);
        $done = $this->done($library, '-3 days');
        $this->log($done, $this->squat, $this->prescribedOf($library), [[8, 80.0], [8, 80.0]]);
        $this->log($done, $this->curl, null, [[12, 15.0], [12, 15.0], [12, 15.0]]);

        $recap = $this->recap->forWorkout($library);

        self::assertNotNull($recap);
        self::assertSame($done, $recap['scheduled']);
        self::assertCount(1, $recap['extras']);
        self::assertSame('Curl biceps', $recap['extras'][0]['logged']->getExerciseName());
        self::assertSame('3 × 12 · 15 kg', $recap['extras'][0]['summary']);
        self::assertTrue($recap['extras'][0]['addable']);
    }

    /**
     * Le mobile n'écrit pas toujours la source : un exercice prescrit retrouvé par
     * son exercice de bibliothèque est apparié, comme dans l'analyse.
     */
    public function testAnExerciseMatchedByItsLibraryEntryIsNotOffProgram(): void
    {
        $library = $this->workout([$this->squat]);
        $done = $this->done($library, '-3 days');
        $this->log($done, $this->squat, null, [[8, 80.0]]);

        self::assertNull($this->recap->forWorkout($library));
    }

    public function testSkippedOrEmptyAdditionsAreNotReported(): void
    {
        $library = $this->workout([$this->squat]);
        $done = $this->done($library, '-3 days');
        $this->log($done, $this->curl, null, [[12, 15.0]])->setSkipped(true);
        $this->log($done, $this->plank, null, []);
        $this->em->flush();

        self::assertNull($this->recap->forWorkout($library));
    }

    /** Une case de plan lit sa lignée par sa racine, et la dernière séance faite l'emporte. */
    public function testAPlanCellReadsTheLatestDoneSessionOfItsLineage(): void
    {
        $library = $this->workout([$this->squat]);
        $older = $this->done($library, '-10 days');
        $this->log($older, $this->plank, null, [[null, null, 45]]);
        $latest = $this->done($library, '-3 days');
        $this->log($latest, $this->curl, null, [[12, 15.0], [10, 17.5]]);

        $cell = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($library, $this->user, null, true);
        $this->em->flush();

        $recap = $this->recap->forWorkout($cell);

        self::assertNotNull($recap);
        self::assertSame($latest, $recap['scheduled']);
        self::assertSame(['Curl biceps'], array_map(static fn (array $e): string => (string) $e['logged']->getExerciseName(), $recap['extras']));
        self::assertSame('2 séries · 12/10 · jusqu\'à 17,5 kg', $recap['extras'][0]['summary']);
    }

    /** Repris dans la séance (ajouté entre-temps) : plus rien à proposer. */
    public function testAnExerciseAlreadyInTheWorkoutIsNoLongerProposed(): void
    {
        $library = $this->workout([$this->squat]);
        $done = $this->done($library, '-3 days');
        $this->log($done, $this->curl, null, [[12, 15.0]]);

        $next = $this->workout([$this->squat, $this->curl]);
        $next->setPlanLocal(true)->setOrigin($library);
        $this->em->flush();

        self::assertNull($this->recap->forWorkout($next));
    }

    public function testNeverDoneMeansNothingToSay(): void
    {
        self::assertNull($this->recap->forWorkout($this->workout([$this->squat])));
    }

    public function testCountsByLineageForThePalette(): void
    {
        $upper = $this->workout([$this->squat]);
        $done = $this->done($upper, '-3 days');
        $this->log($done, $this->curl, null, [[12, 15.0]]);
        $this->log($done, $this->plank, null, [[null, null, 45]]);
        $quiet = $this->workout([$this->squat]);
        $this->log($this->done($quiet, '-2 days'), $this->squat, $this->prescribedOf($quiet), [[8, 80.0]]);
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->getId());
        self::assertNotNull($user);
        self::assertSame([(int) $upper->getId() => 2], $this->recap->countsByLineage($user));
    }

    /** « Ajouter à la séance » reporte ce qui a été fait, par la règle de la pose. */
    public function testPrescribeFromLogReportsWhatWasDone(): void
    {
        $library = $this->workout([$this->squat]);
        $done = $this->done($library, '-3 days');
        $curl = $this->log($done, $this->curl, null, [[12, 15.0], [12, 15.0], [12, 15.0]]);
        $plank = $this->log($done, $this->plank, null, [[null, null, 45], [null, null, 45]]);

        $seed = static::getContainer()->get(LineageSeed::class);
        $fromCurl = $seed->prescribeFromLog($curl);
        $fromPlank = $seed->prescribeFromLog($plank);

        self::assertNotNull($fromCurl);
        self::assertSame(PrescriptionType::SETS_REPS, $fromCurl->getPrescriptionType());
        self::assertSame([3, 12, 15.0], [$fromCurl->getSets(), $fromCurl->getReps(), $fromCurl->getWeightKg()]);
        self::assertNotNull($fromPlank);
        self::assertSame(PrescriptionType::SETS_TIME, $fromPlank->getPrescriptionType());
        self::assertSame([2, 45], [$fromPlank->getSets(), $fromPlank->getDurationSeconds()]);
    }

    private function exercise(string $name): Exercise
    {
        $exercise = (new Exercise())->setName($name)->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        return $exercise;
    }

    /** @param list<Exercise> $exercises */
    private function workout(array $exercises): Workout
    {
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        foreach ($exercises as $position => $exercise) {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition($position)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)->setReps(8)->setWeightKg(80.0)
            );
        }

        $workout = (new Workout())
            ->setOwner($this->user)
            ->setTitle('Upper A')
            ->setSlug('upper-a-'.bin2hex(random_bytes(4)))
            ->addBlock($block);
        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function prescribedOf(Workout $workout): PrescribedExercise
    {
        $block = $workout->getBlocks()->first();
        self::assertInstanceOf(Block::class, $block);
        $prescribed = $block->getPrescribedExercises()->first();
        self::assertInstanceOf(PrescribedExercise::class, $prescribed);

        return $prescribed;
    }

    private function done(Workout $workout, string $date): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /**
     * @param list<array{0: ?int, 1: ?float, 2?: ?int}> $sets reps, charge, durée
     */
    private function log(ScheduledWorkout $scheduled, Exercise $exercise, ?PrescribedExercise $source, array $sets): LoggedExercise
    {
        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setSourcePrescribedExercise($source)
            ->setPosition($scheduled->getLoggedExercises()->count() + 1);

        foreach ($sets as $position => $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition($position + 1)
                    ->setSetType(SetType::NORMAL)
                    ->setReps($set[0])
                    ->setWeightKg($set[1])
                    ->setDurationSeconds($set[2] ?? null)
            );
        }

        $scheduled->addLoggedExercise($logged);
        $this->em->flush();

        return $logged;
    }
}
