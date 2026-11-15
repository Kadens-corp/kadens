<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Service\SharedPlanCopier;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ce qu'un plan copié chez quelqu'un d'autre emporte, et ce qu'il laisse (#58).
 * La re-racine des cases et la règle d'exercices sont couvertes de bout en bout
 * par `PlanSharingTest` ; ici, les frontières : ce qui est à l'auteur reste à
 * l'auteur.
 */
final class SharedPlanCopierTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private SharedPlanCopier $copier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->copier = static::getContainer()->get(SharedPlanCopier::class);
        $this->purgeDatabase($this->em);
    }

    public function testPrivateNotesAndGoalsStayWithTheAuthor(): void
    {
        $author = $this->user('author@example.com');
        $reader = $this->user('reader@example.com');

        $plan = (new PlanTemplate())
            ->setOwner($author)
            ->setTitle('Prépa semi')
            ->setDescription('Huit semaines.')
            ->setNotes('Brouillon de l\'auteur')
            ->setDurationWeeks(8)
            ->setSlug('prepa-semi');
        $goal = (new Goal())->setOwner($author)->setTitle('Semi')->setTargetDate(new \DateTimeImmutable('+2 months'));
        $this->em->persist($goal);
        $plan->addGoal($goal);
        $plan->addPlanItem((new PlanItem())->setWorkout($this->cell($author, null))->setWeekNumber(1)->setDayOfWeek(2)->setNotes('Facile'));
        $this->em->persist($plan);
        $this->em->flush();

        $copy = $this->copier->copyFor($plan, $reader);
        $this->em->flush();

        self::assertSame($reader, $copy->getOwner());
        self::assertSame('Prépa semi', $copy->getTitle());
        self::assertSame('Huit semaines.', $copy->getDescription());
        self::assertSame(8, $copy->getDurationWeeks());
        self::assertNotSame('prepa-semi', $copy->getSlug());
        self::assertNull($copy->getNotes());
        self::assertCount(0, $copy->getGoals());

        $item = $copy->getPlanItems()->first();
        self::assertInstanceOf(PlanItem::class, $item);
        self::assertSame('Facile', $item->getNotes(), 'La note de case décrit la trame, elle suit.');
        $cell = $item->getWorkout();
        self::assertNotNull($cell);
        self::assertSame($reader, $cell->getOwner());
        self::assertTrue($cell->isPlanLocal());
        // Case sans racine (séance d'origine supprimée chez l'auteur) : elle le reste,
        // on n'invente pas de séance de bibliothèque.
        self::assertNull($cell->getOrigin());
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function cell(User $owner, ?Workout $origin): Workout
    {
        $exercise = (new Exercise())->setName('Squat')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(8)
        );

        $cell = (new Workout())
            ->setOwner($owner)
            ->setTitle('Upper A')
            ->setSlug('cell-'.bin2hex(random_bytes(4)))
            ->setPlanLocal(true)
            ->setOrigin($origin)
            ->addBlock($block);
        $this->em->persist($cell);

        return $cell;
    }
}
