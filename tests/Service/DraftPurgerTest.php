<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Goal;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\BlockRole;
use App\Enum\GoalPriority;
use App\Enum\ScheduledStatus;
use App\Repository\PlanTemplateRepository;
use App\Repository\WorkoutRepository;
use App\Service\DraftPurger;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Brouillons abandonnés (#115) : ce qu'on masque des listes et ce que la purge
 * supprime. Le test tient les deux bornes de la règle : **le contenu** (une seule
 * trace d'intention suffit à garder un brouillon) et **le temps** (un brouillon
 * récent est masqué mais jamais supprimé, il peut être ouvert dans un onglet).
 */
final class DraftPurgerTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->owner = (new User())->setEmail('athlete@example.com');
        $this->owner->setPassword('peu-importe');
        $this->em->persist($this->owner);
        $this->em->flush();
    }

    public function testItPurgesOldUntouchedDraftsOnly(): void
    {
        $abandoned = $this->workout(Workout::DRAFT_TITLE);
        $renamed = $this->workout('Upper A');
        $described = $this->workout(Workout::DRAFT_TITLE)->setDescription('Haut du corps');
        $noted = $this->workout(Workout::DRAFT_TITLE)->setNotes('à composer');
        $withBlock = $this->workout(Workout::DRAFT_TITLE);
        $withBlock->addBlock((new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0));
        $scheduled = $this->workout(Workout::DRAFT_TITLE);
        $this->em->persist((new ScheduledWorkout())
            ->setWorkout($scheduled)
            ->setOwner($this->owner)
            ->setScheduledDate(new \DateTimeImmutable('tomorrow'))
            ->setStatus(ScheduledStatus::PLANNED));
        $recent = $this->workout(Workout::DRAFT_TITLE);

        $abandonedPlan = $this->plan(PlanTemplate::DRAFT_TITLE);
        $renamedPlan = $this->plan('Prépa trail');
        $goalPlan = $this->plan(PlanTemplate::DRAFT_TITLE);
        $goal = (new Goal())
            ->setOwner($this->owner)
            ->setTitle('Trail des Allobroges')
            ->setTargetDate(new \DateTimeImmutable('+3 months'))
            ->setPriority(GoalPriority::A);
        $this->em->persist($goal);
        $goalPlan->addGoal($goal);
        $recentPlan = $this->plan(PlanTemplate::DRAFT_TITLE);
        $this->em->flush();

        $this->age([$abandoned, $renamed, $described, $noted, $withBlock, $scheduled], [$abandonedPlan, $renamedPlan, $goalPlan]);

        $ids = static fn (array $entities): array => array_map(static fn (Workout|PlanTemplate $e): ?int => $e->getId(), $entities);
        $abandonedId = $abandoned->getId();
        $abandonedPlanId = $abandonedPlan->getId();
        $keptWorkouts = $ids([$renamed, $described, $noted, $withBlock, $scheduled, $recent]);
        $keptPlans = $ids([$renamedPlan, $goalPlan, $recentPlan]);

        $result = static::getContainer()->get(DraftPurger::class)->purge();

        self::assertSame(['workouts' => 1, 'plans' => 1], $result);
        $this->em->clear();
        self::assertNull($this->em->find(Workout::class, $abandonedId));
        self::assertNull($this->em->find(PlanTemplate::class, $abandonedPlanId));
        foreach ($keptWorkouts as $id) {
            self::assertNotNull($this->em->find(Workout::class, $id), "La séance #$id portait une intention.");
        }
        foreach ($keptPlans as $id) {
            self::assertNotNull($this->em->find(PlanTemplate::class, $id), "Le plan #$id portait une intention.");
        }
    }

    /**
     * Les listes masquent un brouillon abandonné dès sa création, sans attendre
     * le délai de grâce : c'est le symptôme d'A-51, le retour arrière qui laisse
     * « Nouvelle séance · 0 bloc » dans la liste.
     */
    public function testLibraryListsHideAbandonedDraftsAtOnce(): void
    {
        $this->workout(Workout::DRAFT_TITLE);
        $kept = $this->workout('Upper A');
        $this->plan(PlanTemplate::DRAFT_TITLE);
        $keptPlan = $this->plan('Prépa trail');
        $this->em->flush();

        $workouts = static::getContainer()->get(WorkoutRepository::class)->findLibraryForOwnersWithContent([$this->owner]);
        $plans = static::getContainer()->get(PlanTemplateRepository::class)->findForOwnersWithContent([$this->owner]);

        self::assertSame([$kept->getId()], array_map(static fn (Workout $w): ?int => $w->getId(), $workouts));
        self::assertSame([$keptPlan->getId()], array_map(static fn (PlanTemplate $p): ?int => $p->getId(), $plans));
    }

    public function testCommandReportsWhatItPurged(): void
    {
        $this->age([$this->workout(Workout::DRAFT_TITLE)], [$this->plan(PlanTemplate::DRAFT_TITLE)]);
        self::assertNotNull(self::$kernel);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:drafts:purge'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 séance(s) et 1 plan(s) supprimé(s).', $tester->getDisplay());
        self::assertSame(0, $this->em->getRepository(Workout::class)->count([]));

        $tester->execute(['--hours' => '-1']);
        self::assertSame(2, $tester->getStatusCode());
    }

    private function workout(string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($this->owner)
            ->setTitle($title)
            ->setSlug(uniqid('w-', true));
        $this->em->persist($workout);

        return $workout;
    }

    private function plan(string $title): PlanTemplate
    {
        $plan = (new PlanTemplate())
            ->setOwner($this->owner)
            ->setTitle($title)
            ->setDurationWeeks(4)
            ->setSlug(uniqid('p-', true));
        $this->em->persist($plan);

        return $plan;
    }

    /**
     * Vieillit des entités au-delà du délai de grâce (`createdAt` est posé par
     * `PrePersist`, il se recule en base).
     *
     * @param list<Workout>      $workouts
     * @param list<PlanTemplate> $plans
     */
    private function age(array $workouts, array $plans): void
    {
        $this->em->flush();
        $connection = $this->em->getConnection();
        $old = (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s');

        foreach ($workouts as $workout) {
            $connection->executeStatement('UPDATE workout SET created_at = ? WHERE id = ?', [$old, $workout->getId()]);
        }
        foreach ($plans as $plan) {
            $connection->executeStatement('UPDATE plan_template SET created_at = ? WHERE id = ?', [$old, $plan->getId()]);
        }
    }
}
