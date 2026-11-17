<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use App\Service\PlanFlattener;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #95 : la note de case d'un plan (« + note », « + Consigne pour l'athlète »)
 * se lit sur la séance datée qui en est issue, page et modale du calendrier,
 * sous « Consigne du plan ». Avant, elle n'existait que dans la grille du plan.
 */
final class PlanInstructionTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private const INSTRUCTION = "Descente lente en 3 s\nsur squat et fentes";

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $athlete;
    private User $coach;
    private PlanItem $item;
    private ScheduledWorkout $fromPlan;
    private ScheduledWorkout $offPlan;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->athlete = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->coach = (new User())->setEmail('coach@example.com')->setPassword('x')->setRoles(['ROLE_COACH']);
        $this->em->persist($this->athlete);
        $this->em->persist($this->coach);
        $this->em->persist((new Coaching())->setCoach($this->coach)->setAthlete($this->athlete)->setStatus(CoachingStatus::ACCEPTED));

        $cell = (new Workout())
            ->setOwner($this->athlete)
            ->setTitle('Bas du corps')
            ->setSlug('bas-'.bin2hex(random_bytes(3)))
            ->setPlanLocal(true);
        $this->em->persist($cell);

        $plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle('Force')
            ->setDurationWeeks(2)
            ->setSlug('force-'.bin2hex(random_bytes(3)));
        $this->em->persist($plan);

        // Espaces autour : la consigne se lit telle qu'écrite, sans eux.
        $this->item = (new PlanItem())->setWorkout($cell)->setWeekNumber(1)->setDayOfWeek(2)->setNotes('  '.self::INSTRUCTION."\n");
        $plan->addPlanItem($this->item);
        $this->em->persist($this->item);

        // Mardi 17 mars 2026, la case S1 · mardi.
        $this->fromPlan = (new ScheduledWorkout())
            ->setOwner($this->athlete)
            ->setWorkout($cell)
            ->setTitle('Bas du corps')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-17'))
            ->setStatus(ScheduledStatus::PLANNED)
            ->setSourcePlanTemplate($plan)
            ->setSourcePlanItem($this->item);
        $this->offPlan = (new ScheduledWorkout())
            ->setOwner($this->athlete)
            ->setTitle('Footing')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-17'))
            ->setStatus(ScheduledStatus::PLANNED);
        $this->em->persist($this->fromPlan);
        $this->em->persist($this->offPlan);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    public function testTheDatedSessionPageShowsTheCellInstruction(): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/schedule/'.$this->fromPlan->getId());
        self::assertResponseIsSuccessful();

        $box = $crawler->filter('.kd-instruction');
        self::assertCount(1, $box);
        self::assertStringContainsString('Consigne du plan', $box->filter('.kd-instruction__label')->text());
        // Retours à la ligne gardés, espaces de bord retirés.
        self::assertSame("Descente lente en 3 s<br>\nsur squat et fentes", trim($box->filter('.kd-instruction__text')->html()));
        // Avant le bilan : on la lit avant de faire la séance.
        self::assertCount(1, $crawler->filter('.kd-instruction + #schedule-summary'));
    }

    public function testASessionPlacedOffPlanHasNoInstruction(): void
    {
        $this->client->loginUser($this->athlete);
        $this->client->request('GET', '/schedule/'.$this->offPlan->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-instruction');
    }

    public function testAnEmptyNoteShowsNothing(): void
    {
        $this->mustFind(PlanItem::class, $this->item->getId())->setNotes("  \n ");
        $this->em->flush();

        $this->client->loginUser($this->athlete);
        $this->client->request('GET', '/schedule/'.$this->fromPlan->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-instruction');
    }

    public function testTheMonthModalShowsItOnlyOnTheSessionFromThePlan(): void
    {
        $this->client->loginUser($this->athlete);
        $this->client->request('GET', '/calendar/2026/3');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#cal-event-'.$this->fromPlan->getId().' dialog .kd-instruction--compact', 'sur squat et fentes');
        self::assertSelectorNotExists('#cal-event-'.$this->offPlan->getId().' .kd-instruction');
    }

    public function testTheWeekModalShowsIt(): void
    {
        $this->client->loginUser($this->athlete);
        $this->client->request('GET', '/calendar/week/2026-03-17');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#cal-event-'.$this->fromPlan->getId().' dialog .kd-instruction', 'Descente lente en 3 s');
    }

    /** Le coach la lit aussi, dans l'agenda de son athlète : c'est lui qui l'a écrite. */
    public function testTheCoachSeesItInTheAthleteCalendar(): void
    {
        $this->client->loginUser($this->coach);
        $this->client->request('GET', '/coach/athlete/'.$this->athlete->getId().'/calendar/2026-03-17');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#cal-event-'.$this->fromPlan->getId().' dialog .kd-instruction', 'Descente lente en 3 s');
    }

    /** Changer le statut re-rend la pastille : la consigne ne doit pas partir avec. */
    public function testTheReRenderedEventKeepsIt(): void
    {
        $id = $this->fromPlan->getId();
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/calendar/2026/3');
        $token = (string) $crawler->filter('#cal-status-'.$id.' input[name="_token"]')->attr('value');

        $this->client->request('POST', '/schedule/'.$id.'/quick-status', [
            '_token' => $token,
            'status' => 'done',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('kd-instruction', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('sur squat et fentes', (string) $this->client->getResponse()->getContent());
    }

    /** Retirer la case du plan (SET NULL) : la séance datée n'a plus de consigne. */
    public function testFlattenerReturnsNullOnceTheCellIsGone(): void
    {
        $flattener = static::getContainer()->get(PlanFlattener::class);
        $scheduled = $this->mustFind(ScheduledWorkout::class, $this->fromPlan->getId());

        self::assertSame(self::INSTRUCTION, $flattener->planInstruction($scheduled));
        self::assertSame([(int) $scheduled->getId() => self::INSTRUCTION], $flattener->planInstructions([$scheduled, $this->mustFind(ScheduledWorkout::class, $this->offPlan->getId())]));

        $scheduled->setSourcePlanItem(null);
        self::assertNull($flattener->planInstruction($scheduled));
    }
}
