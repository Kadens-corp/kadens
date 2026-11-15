<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\WorkoutCloner;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le coach programme la semaine suivante : ce que l'athlète a fait en plus la
 * dernière fois doit se voir là où il programme (carte de palette, édition
 * rapide d'une case, compositeur), et s'ajouter d'un clic.
 */
final class OffProgramTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $coach;
    private User $athlete;
    private Exercise $squat;
    private Exercise $curl;
    private Workout $upper;
    private LoggedExercise $curlLog;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->coach = $this->user('coach@example.com', ['ROLE_COACH']);
        $this->athlete = $this->user('athlete@example.com');
        $this->em->persist(
            (new Coaching())
                ->setCoach($this->coach)
                ->setAthlete($this->athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($this->coach)
                ->setRespondedAt(new \DateTimeImmutable())
        );

        $this->squat = (new Exercise())->setName('Squat')->setActivity(ActivityType::GYM);
        $this->curl = (new Exercise())->setName('Curl biceps')->setActivity(ActivityType::GYM);
        $this->em->persist($this->squat);
        $this->em->persist($this->curl);

        // « Upper A » faite il y a trois jours, avec un curl ajouté en séance.
        $this->upper = $this->workout($this->athlete, 'Upper A');
        $done = (new ScheduledWorkout())
            ->setOwner($this->athlete)
            ->setWorkout($this->upper)
            ->setScheduledDate(new \DateTimeImmutable('-3 days'))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($done);
        $this->curlLog = $this->log($done, $this->curl, [[12, 15.0], [12, 15.0], [12, 15.0]]);
        $this->em->flush();
    }

    public function testThePaletteCardSignalsWhatWasDoneOffProgram(): void
    {
        $plan = $this->plan();

        $this->client->loginUser($this->coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');

        $card = $crawler->filter('.kd-palettecard[data-workout-id="'.$this->upper->getId().'"]');
        self::assertStringContainsString('+ 1 hors programme', $card->text());
    }

    public function testTheQuickPanelOfTheNextCellShowsItAndAddsItInOneClick(): void
    {
        $cell = $this->cell();

        $this->client->loginUser($this->coach);
        $crawler = $this->client->request('GET', '/workout/'.$cell->getId().'/quick-panel');

        self::assertSelectorTextContains('.kd-offprog', 'Curl biceps');
        self::assertSelectorTextContains('.kd-offprog', '3 × 12 · 15 kg');

        $this->client->submit($crawler->filter('.kd-offprog__add')->form(), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        // Le flux renvoie le panneau rapide, sans l'encart : le curl est prescrit.
        self::assertStringContainsString('target="quick-panel"', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('kd-offprog', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $added = null;
        foreach ($this->mustFind(Workout::class, $cell->getId())->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                if ($prescribed->getExercise()?->getId() === $this->curl->getId()) {
                    $added = $prescribed;
                }
            }
        }
        self::assertNotNull($added, 'Le curl est ajouté à la case.');
        self::assertSame([3, 12, 15.0], [$added->getSets(), $added->getReps(), $added->getWeightKg()]);
        // La séance de bibliothèque, elle, ne bouge pas.
        $libraryBlock = $this->mustFind(Workout::class, $this->upper->getId())->getBlocks()->first();
        self::assertInstanceOf(Block::class, $libraryBlock);
        self::assertCount(1, $libraryBlock->getPrescribedExercises());
    }

    public function testTheComposerShowsItToo(): void
    {
        $this->client->loginUser($this->athlete);
        $this->client->request('GET', '/workout/'.$this->upper->getId().'/edit');

        self::assertSelectorTextContains('#workout-blocks .kd-offprog', 'Curl biceps');
    }

    /**
     * La route lit un réalisé : elle n'accepte que ceux que la page propose. Le
     * témoin (le bon log, même jeton) prouve que le refus vient de cette garde,
     * pas d'un jeton invalide.
     */
    public function testALogOutsideTheLineageIsRefused(): void
    {
        $lower = $this->workout($this->athlete, 'Lower A');
        $lowerDone = (new ScheduledWorkout())
            ->setOwner($this->athlete)
            ->setWorkout($lower)
            ->setScheduledDate(new \DateTimeImmutable('-1 day'))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($lowerDone);
        $foreignLog = $this->log($lowerDone, $this->curl, [[20, 30.0]]);

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upper->getId().'/edit');
        $token = (string) $crawler->filter('.kd-offprog__add input[name="_token"]')->attr('value');
        $url = '/workout/'.$this->upper->getId().'/exercises/from-log/';

        $this->client->request('POST', $url.$foreignLog->getId(), ['_token' => $token]);
        self::assertSame([], $this->curlSetsIn($this->upper), 'Log d\'une autre lignée : refusé.');

        $this->client->request('POST', $url.$this->curlLog->getId(), ['_token' => $token]);
        self::assertSame([3], $this->curlSetsIn($this->upper), 'Témoin : le bon log, même jeton, passe.');
    }

    /** @return list<int|null> séries prescrites du curl dans la séance, relue en base */
    private function curlSetsIn(Workout $workout): array
    {
        $this->em->clear();
        $sets = [];
        foreach ($this->mustFind(Workout::class, $workout->getId())->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                if ($prescribed->getExercise()?->getId() === $this->curl->getId()) {
                    $sets[] = $prescribed->getSets();
                }
            }
        }

        return $sets;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($roles);
        $this->em->persist($user);

        return $user;
    }

    private function workout(User $owner, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)).'-'.bin2hex(random_bytes(3)))
            ->addBlock(
                (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0)
                    ->addPrescribedExercise(
                        (new PrescribedExercise())
                            ->setExercise($this->squat)
                            ->setPosition(0)
                            ->setPrescriptionType(PrescriptionType::SETS_REPS)
                            ->setSets(3)->setReps(8)->setWeightKg(80.0)
                    )
            );
        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    /** @param list<array{int, float}> $sets */
    private function log(ScheduledWorkout $scheduled, Exercise $exercise, array $sets): LoggedExercise
    {
        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition($scheduled->getLoggedExercises()->count() + 1);
        foreach ($sets as $position => [$reps, $weight]) {
            $logged->addLoggedSet((new LoggedSet())->setPosition($position + 1)->setSetType(SetType::NORMAL)->setReps($reps)->setWeightKg($weight));
        }
        $scheduled->addLoggedExercise($logged);
        $this->em->flush();

        return $logged;
    }

    private function plan(): PlanTemplate
    {
        $plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle('Prépa')
            ->setDurationWeeks(4)
            ->setSlug('prepa-'.bin2hex(random_bytes(3)));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /** La case de la semaine suivante, forkée de « Upper A » comme le fait la pose. */
    private function cell(): Workout
    {
        $plan = $this->plan();
        $cell = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($this->upper, $this->athlete, null, true);
        $item = (new PlanItem())->setWorkout($cell)->setWeekNumber(2)->setDayOfWeek(1);
        $plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $cell;
    }
}
