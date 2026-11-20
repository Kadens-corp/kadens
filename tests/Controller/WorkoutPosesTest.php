<?php

namespace App\Tests\Controller;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Service\WorkoutCloner;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #113 : poser une séance dans un plan la copie, la poser à l'agenda la
 * référence. Le compositeur d'une séance de bibliothèque dit lesquelles de ses
 * poses suivront une modification et lesquelles restent figées.
 */
final class WorkoutPosesTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $athlete;
    private Workout $upperA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->athlete = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->athlete);

        $this->upperA = $this->workout('Upper A');
        $this->em->flush();
    }

    public function testThePlansKeepTheirCopyAndTheAgendaFollows(): void
    {
        $force = $this->plan('Force');
        $prepa = $this->plan('Prépa trail');
        // Deux cases dans le même plan : il n'est nommé qu'une fois.
        $this->cell($prepa, $this->upperA, 1);
        $this->cell($prepa, $this->upperA, 2);
        $this->cell($force, $this->upperA, 1);
        // Une séance issue du plan pointe la copie, pas la séance : elle ne
        // compte pas parmi les poses directes.
        $cell = $this->cell($force, $this->upperA, 3);
        $this->scheduled($cell, ScheduledStatus::PLANNED);
        $this->scheduled($this->upperA, ScheduledStatus::PLANNED);
        $this->scheduled($this->upperA, ScheduledStatus::DONE);

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upperA->getId().'/edit');
        self::assertResponseIsSuccessful();

        $lines = $crawler->filter('.kd-poses__line')->each(static fn ($line): string => trim((string) preg_replace('/\s+/', ' ', $line->text())));
        self::assertSame([
            'Utilisée dans 2 plans, Force et Prépa trail : les cases déjà posées gardent leur version.',
            'Posée 2 fois directement à l\'agenda : ces séances suivent chaque modification, y compris celle déjà faite.',
        ], $lines);
        self::assertSame(
            ['/plan-template/'.$force->getId().'/edit', '/plan-template/'.$prepa->getId().'/edit'],
            $crawler->filter('.kd-poses a')->each(static fn ($a): string => (string) $a->attr('href')),
        );
    }

    public function testASinglePlanIsNamedAndTheAgendaIsSilentWhenUnused(): void
    {
        $this->cell($this->plan('Prépa trail'), $this->upperA, 1);

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upperA->getId().'/edit');

        self::assertSame(
            'Utilisée dans le plan Prépa trail : les cases déjà posées gardent leur version.',
            trim((string) preg_replace('/\s+/', ' ', $crawler->filter('.kd-poses')->text())),
        );
    }

    /**
     * Une séance faite relit le prescrit vivant : la modifier change ce que sa
     * comparaison prévu/réalisé affiche. La ligne le dit, au singulier comme au
     * pluriel, et se tait quand rien n'est fait.
     */
    public function testTheAgendaLineNamesTheSessionsAlreadyDone(): void
    {
        $lower = $this->workout('Lower B');
        $this->scheduled($this->upperA, ScheduledStatus::DONE);
        $this->scheduled($lower, ScheduledStatus::PLANNED);
        $this->scheduled($lower, ScheduledStatus::DONE);
        $this->scheduled($lower, ScheduledStatus::DONE);
        $footing = $this->workout('Footing');
        $this->scheduled($footing, ScheduledStatus::PLANNED);
        $this->scheduled($footing, ScheduledStatus::MISSED);

        $this->client->loginUser($this->athlete);
        foreach ([
            'Posée 1 fois directement à l\'agenda : elle suit chaque modification, même déjà faite.' => $this->upperA,
            'Posée 3 fois directement à l\'agenda : ces séances suivent chaque modification, y compris les 2 déjà faites.' => $lower,
            'Posée 2 fois directement à l\'agenda : ces séances suivent chaque modification.' => $footing,
        ] as $expected => $workout) {
            $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
            self::assertSame($expected, trim((string) preg_replace('/\s+/', ' ', $crawler->filter('.kd-poses')->text())));
        }
    }

    public function testNothingIsSaidForAnUnusedWorkoutNorForAPlanCell(): void
    {
        $cell = $this->cell($this->plan('Prépa trail'), $this->workout('Lower B'), 1);
        $this->em->flush();

        $this->client->loginUser($this->athlete);
        foreach ([$this->upperA, $cell] as $workout) {
            $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.kd-poses'), (string) $workout->getTitle());
        }
    }

    private function workout(string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($this->athlete)
            ->setTitle($title)
            ->setSlug('w-'.bin2hex(random_bytes(3)));
        $this->em->persist($workout);

        return $workout;
    }

    private function plan(string $title): PlanTemplate
    {
        $plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle($title)
            ->setDurationWeeks(4)
            ->setSlug('p-'.bin2hex(random_bytes(3)));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /** Pose comme le fait l'éditeur de plan : une copie locale par case. */
    private function cell(PlanTemplate $plan, Workout $source, int $week): Workout
    {
        $copy = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($source, $this->athlete, null, true);
        $item = (new PlanItem())->setWorkout($copy)->setWeekNumber($week)->setDayOfWeek(1);
        $plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $copy;
    }

    private function scheduled(Workout $workout, ScheduledStatus $status): void
    {
        $this->em->persist(
            (new ScheduledWorkout())
                ->setOwner($this->athlete)
                ->setWorkout($workout)
                ->setScheduledDate(new \DateTimeImmutable('2026-12-04'))
                ->setStatus($status)
        );
        $this->em->flush();
    }
}
