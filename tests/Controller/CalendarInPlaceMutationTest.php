<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Poser, retirer, déplacer une séance ou placer un plan depuis le calendrier ne
 * recharge plus la vue : la réponse est un flux qui relit la page en
 * arrière-plan et la morphe, scroll gardé. Le marqueur `return=calendar` en
 * décide, parce que retirer et déplacer servent aussi la page de la séance
 * datée. Déplacer et placer un plan peuvent sortir de la vue : on n'y reste que
 * si le jour visé est dans les jours qu'elle montre (jeton `from`), sinon on va
 * le voir, comme avant.
 */
final class CalendarInPlaceMutationTest extends WebTestCase
{
    use PurgesDatabase;

    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    public function testPlacingFromTheCalendarMorphsTheView(): void
    {
        $owner = $this->createUser();
        $workout = $this->createWorkout($owner);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/calendar/week/2026-03-11');
        $form = $crawler->filter('form[action$="/schedule/place"]')->form();
        self::assertSame('calendar', $form->getValues()['return'] ?? null);

        $this->client->request('POST', '/schedule/place', [
            '_token' => $form->getValues()['_token'] ?? '',
            'return' => 'calendar',
            'date' => '2026-03-12',
            'workoutId' => (string) $workout->getId(),
        ], [], self::TURBO);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('action="refresh" method="morph" scroll="preserve"', $html);
        self::assertStringNotContainsString('action="remove"', $html);
        self::assertCount(1, $this->em->getRepository(ScheduledWorkout::class)->findBy(['owner' => $owner]));
    }

    public function testRemovingFromTheCalendarDropsThePillThenMorphs(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/calendar/week/2026-03-11');
        $form = $crawler->filter('form[action$="/schedule/'.$id.'/delete"]')->form();
        self::assertSame('calendar', $form->getValues()['return'] ?? null);
        $this->client->submit($form, [], self::TURBO);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="cal-event-'.$id.'">', $html);
        self::assertStringContainsString('action="refresh" method="morph"', $html);
        $this->em->clear();
        self::assertNull($this->em->find(ScheduledWorkout::class, $id));
    }

    public function testMovingWithinTheWeekMorphsTheView(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $this->move($id, '/calendar/week/2026-03-11', '2026-03-14');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="cal-event-'.$id.'">', $html);
        self::assertStringContainsString('action="refresh" method="morph"', $html);
        $this->em->clear();
        self::assertSame('2026-03-14', $this->em->find(ScheduledWorkout::class, $id)?->getScheduledDate()->format('Y-m-d'));
    }

    /** Hors de la vue, rester la ferait disparaître : on va voir sa nouvelle semaine. */
    public function testMovingOutOfTheWeekOpensTheTargetWeek(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner);
        $this->client->loginUser($owner);

        $this->move((int) $scheduled->getId(), '/calendar/week/2026-03-11', '2026-03-20');

        self::assertResponseRedirects('/calendar/week/2026-03-20');
    }

    public function testMovingWithinTheMonthMorphsTheView(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner);
        $this->client->loginUser($owner);

        $this->move((int) $scheduled->getId(), '/calendar/2026/3', '2026-03-31');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="refresh"', (string) $this->client->getResponse()->getContent());
    }

    public function testPlacingAPlanThatStartsInViewMorphsTheView(): void
    {
        $owner = $this->createUser();
        $template = $this->createPlan($owner, 1);
        $this->client->loginUser($owner);

        // Semaine du 9 mars ; départ le 11, séance le mercredi de la semaine 1.
        $this->placePlan($template, '/calendar/week/2026-03-11', '2026-03-11');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="refresh" method="morph"', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->em->getRepository(ScheduledWorkout::class)->findBy(['owner' => $owner]));
    }

    /** Première séance hors de la vue : l'agenda s'ouvre sur elle (#68). */
    public function testPlacingAPlanThatStartsElsewhereOpensItsFirstDay(): void
    {
        $owner = $this->createUser();
        $template = $this->createPlan($owner, 3);
        $this->client->loginUser($owner);

        $this->placePlan($template, '/calendar/week/2026-03-11', '2026-03-11');

        self::assertResponseRedirects('/calendar/week/2026-03-25?focus=2026-03-25');
    }

    /** Depuis la page de la séance, qui disparaît avec elle : redirection, comme avant. */
    public function testRemovingFromTheSchedulePageStillRedirects(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/schedule/'.$id);
        $form = $crawler->filter('form[action$="/schedule/'.$id.'/delete"]')->form();
        self::assertFalse($form->has('return'));
        $this->client->submit($form, [], self::TURBO);

        self::assertResponseRedirects();
    }

    private function move(int $id, string $view, string $date): void
    {
        $crawler = $this->client->request('GET', $view);
        $form = $crawler->filter('form[action$="/schedule/'.$id.'/move"]')->form(['scheduledDate' => $date]);
        self::assertSame('calendar', $form->getValues()['return'] ?? null);
        $this->client->submit($form, [], self::TURBO);
    }

    private function placePlan(PlanTemplate $template, string $view, string $start): void
    {
        $crawler = $this->client->request('GET', $view);
        $form = $crawler->filter('form[action$="/schedule/plan"]')->form([
            'plan_instantiation[planTemplate]' => (string) $template->getId(),
            'plan_instantiation[startDate]' => $start,
        ]);
        self::assertSame('calendar', $form->getValues()['return'] ?? null);
        $this->client->submit($form, [], self::TURBO);
    }

    /** Un plan d'une séance, le mercredi de la semaine `$week`. */
    private function createPlan(User $owner, int $week): PlanTemplate
    {
        $template = (new PlanTemplate())->setOwner($owner)->setTitle('Plan 5k')->setDurationWeeks($week)->setSlug('plan-5k');
        $item = (new PlanItem())->setWeekNumber($week)->setDayOfWeek(3)->setWorkout($this->createWorkout($owner));
        $template->addPlanItem($item);
        $this->em->persist($template);
        $this->em->persist($item);
        $this->em->flush();

        return $template;
    }

    private function createUser(): User
    {
        $user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createWorkout(User $owner): Workout
    {
        $workout = (new Workout())->setOwner($owner)->setTitle('Haut du corps')->setSlug('haut-du-corps');
        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function createScheduled(User $owner): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-10'))
            ->setStatus(ScheduledStatus::PLANNED);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
