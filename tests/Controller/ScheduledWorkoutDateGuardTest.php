<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les garde-fous du calendrier sur le passé et le futur (#109). Un jour passé,
 * on consigne ce qui a eu lieu : la pose propose le statut et ne parle plus de
 * « planifier ». Un jour à venir, une séance ne peut pas être faite : la marquer
 * faite demande confirmation et la ramène à aujourd'hui, pour que l'observance
 * d'une semaine qui n'a pas eu lieu ne passe pas à 100 %.
 *
 * Les dates sont relatives au jour du test : c'est la comparaison à
 * aujourd'hui qui est testée.
 */
final class ScheduledWorkoutDateGuardTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

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

    /** La modale d'ajout porte le choix du statut, désactivé tant qu'aucun jour passé n'est ouvert. */
    public function testThePlaceModalCarriesADisabledPastStatusChoice(): void
    {
        $owner = $this->createUser();
        $this->createWorkout($owner);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/calendar/week/'.(new \DateTimeImmutable('today'))->format('Y-m-d'));

        $fieldset = $crawler->filter('form[action$="/schedule/place"] fieldset.kd-picker__past');
        self::assertCount(1, $fieldset);
        self::assertNotNull($fieldset->attr('disabled'), 'Sans jour passé ouvert, aucun statut ne part.');
        self::assertNotNull($fieldset->attr('hidden'));
        self::assertSame('done', $fieldset->filter('input[checked]')->attr('value'), 'Un jour passé, Faite par défaut.');
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $crawler->filter('[data-controller="caladd"]')->attr('data-caladd-today-value'),
        );
    }

    /** Posée au passé avec « Faite » : la séance est faite, et le message le dit. */
    public function testPlacingOnAPastDayRecordsTheChosenStatus(): void
    {
        $owner = $this->createUser();
        $workout = $this->createWorkout($owner);
        $this->client->loginUser($owner);
        $past = new \DateTimeImmutable('-6 days');

        $this->place($workout, $past, 'done');

        $stored = $this->onlyScheduled();
        self::assertSame(ScheduledStatus::DONE, $stored->getStatus());
        self::assertSame($past->format('Y-m-d'), $stored->getScheduledDate()->format('Y-m-d'));
        $this->assertFlash('Séance ajoutée au '.$past->format('d/m').', marquée faite.');
    }

    /** Posée au passé sans statut (sans JS) : prévue, et le message demande de trancher. */
    public function testPlacingOnAPastDayWithoutStatusAsksForIt(): void
    {
        $owner = $this->createUser();
        $workout = $this->createWorkout($owner);
        $this->client->loginUser($owner);
        $past = new \DateTimeImmutable('-6 days');

        $this->place($workout, $past, null);

        self::assertSame(ScheduledStatus::PLANNED, $this->onlyScheduled()->getStatus());
        $this->assertFlash('Séance ajoutée au '.$past->format('d/m').'. Dis si elle est faite ou manquée');
    }

    /** À venir, un statut posté est ignoré : la séance est prévue, et « planifiée ». */
    public function testPlacingOnAFutureDayIgnoresAPostedStatus(): void
    {
        $owner = $this->createUser();
        $workout = $this->createWorkout($owner);
        $this->client->loginUser($owner);
        $future = new \DateTimeImmutable('+5 days');

        $this->place($workout, $future, 'done');

        self::assertSame(ScheduledStatus::PLANNED, $this->onlyScheduled()->getStatus());
        $this->assertFlash('Séance planifiée le '.$future->format('d/m').'.');
    }

    /** Aujourd'hui n'est pas du passé : la séance reste à faire. */
    public function testPlacingTodayIsPlanning(): void
    {
        $owner = $this->createUser();
        $workout = $this->createWorkout($owner);
        $this->client->loginUser($owner);

        $this->place($workout, new \DateTimeImmutable('today'), 'done');

        self::assertSame(ScheduledStatus::PLANNED, $this->onlyScheduled()->getStatus());
    }

    /** Le menu et la modale d'une séance à venir demandent confirmation avant « Faite ». */
    public function testAFutureSessionAsksBeforeBeingMarkedDone(): void
    {
        $owner = $this->createUser();
        $future = $this->createScheduled($owner, new \DateTimeImmutable('+3 days'), ScheduledStatus::PLANNED);
        $past = $this->createScheduled($owner, new \DateTimeImmutable('-1 day'), ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);

        $futureCrawler = $this->client->request('GET', '/calendar/week/'.(new \DateTimeImmutable('+3 days'))->format('Y-m-d'));

        $done = $futureCrawler->filter('#cal-status-'.$future->getId().' button[value="done"]');
        self::assertStringContainsString('confirm(', (string) $done->attr('onclick'));
        self::assertStringContainsString('ramène à aujourd', (string) $done->attr('onclick'));
        self::assertNull($futureCrawler->filter('#cal-status-'.$future->getId().' button[value="missed"]')->attr('onclick'));
        self::assertStringContainsString('confirm(', (string) $futureCrawler->filter('#cal-save-'.$future->getId())->attr('onsubmit'));

        $pastCrawler = $this->client->request('GET', '/calendar/week/'.(new \DateTimeImmutable('-1 day'))->format('Y-m-d'));
        self::assertNull($pastCrawler->filter('#cal-status-'.$past->getId().' button[value="done"]')->attr('onclick'));
        self::assertNull($pastCrawler->filter('#cal-save-'.$past->getId())->attr('onsubmit'));
    }

    /** La page de la séance datée pose la même question. */
    public function testTheSchedulePageAsksToo(): void
    {
        $owner = $this->createUser();
        $future = $this->createScheduled($owner, new \DateTimeImmutable('+3 days'), ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/schedule/'.$future->getId());

        self::assertStringContainsString('confirm(', (string) $crawler->filter('#schedule-done form.kd-done__form')->attr('onsubmit'));
    }

    /**
     * Faite en avance par le menu : la séance revient à aujourd'hui. En Turbo, la
     * pastille est remplacée (ce qui ferme le menu) puis la page se morphe, sans
     * rechargement ; le message attend en session le refresh qui l'affiche.
     */
    public function testMarkingAFutureSessionDoneBringsItToToday(): void
    {
        $owner = $this->createUser();
        $planned = new \DateTimeImmutable('+3 days');
        $scheduled = $this->createScheduled($owner, $planned, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/calendar/week/'.$planned->format('Y-m-d'));
        $token = (string) $crawler->filter('#cal-status-'.$id.' input[name="_token"]')->attr('value');
        $this->client->request('POST', '/schedule/'.$id.'/quick-status', [
            '_token' => $token,
            'status' => 'done',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="cal-event-'.$id.'"', $html);
        self::assertStringContainsString('<turbo-stream action="refresh" method="morph" scroll="preserve">', $html);

        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $id);
        self::assertSame(ScheduledStatus::DONE, $stored->getStatus());
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $stored->getScheduledDate()->format('Y-m-d'));

        // Le refresh relit la page : le message s'y affiche.
        $this->client->request('GET', '/calendar/week/'.(new \DateTimeImmutable('today'))->format('Y-m-d'));
        self::assertSelectorTextContains('.kd-flash', 'elle était prévue le '.$planned->format('d/m'));
    }

    /** Un changement de statut ordinaire ne demande aucun refresh : la pastille suffit. */
    public function testAnOrdinaryStatusChangeDoesNotRefreshThePage(): void
    {
        $owner = $this->createUser();
        $past = new \DateTimeImmutable('-2 days');
        $scheduled = $this->createScheduled($owner, $past, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/calendar/week/'.$past->format('Y-m-d'));
        $token = (string) $crawler->filter('#cal-status-'.$id.' input[name="_token"]')->attr('value');
        $this->client->request('POST', '/schedule/'.$id.'/quick-status', [
            '_token' => $token,
            'status' => 'done',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('action="refresh"', (string) $this->client->getResponse()->getContent());
    }

    /** Sans JS, même retour à aujourd'hui, par une redirection qui porte le message. */
    public function testWithoutTurboTheMoveRedirectsWithTheMessage(): void
    {
        $owner = $this->createUser();
        $planned = new \DateTimeImmutable('+3 days');
        $scheduled = $this->createScheduled($owner, $planned, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $this->quickStatus($id, $planned, 'done');

        self::assertResponseRedirects();
        $this->assertFlash('elle était prévue le '.$planned->format('d/m'));
    }

    /** Par le formulaire de la page : même retour à aujourd'hui, fragments remplacés puis page morphée. */
    public function testTheFullFormBringsAFutureDoneSessionToToday(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner, new \DateTimeImmutable('+3 days'), ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/schedule/'.$id);
        $token = (string) $crawler->filter('#schedule-done form.kd-done__form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/schedule/'.$id.'/status', [
            '_token' => $token,
            'status' => 'done',
            'completionNotes' => 'Faite avant',
            'return' => 'schedule',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="schedule-summary"', $html);
        self::assertStringContainsString('action="refresh" method="morph"', $html);
        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $id);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $stored->getScheduledDate()->format('Y-m-d'));
        self::assertSame('Faite avant', $stored->getCompletionNotes());
    }

    /** Manquée ou prévue, une séance à venir garde sa date ; faite au passé aussi. */
    public function testOnlyAFutureDoneSessionMoves(): void
    {
        $owner = $this->createUser();
        $future = new \DateTimeImmutable('+3 days');
        $past = new \DateTimeImmutable('-2 days');
        $missed = $this->createScheduled($owner, $future, ScheduledStatus::PLANNED);
        $done = $this->createScheduled($owner, $past, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);

        $this->quickStatus((int) $missed->getId(), $future, 'missed');
        $this->quickStatus((int) $done->getId(), $past, 'done');

        $this->em->clear();
        self::assertSame($future->format('Y-m-d'), $this->mustFind(ScheduledWorkout::class, (int) $missed->getId())->getScheduledDate()->format('Y-m-d'));
        self::assertSame($past->format('Y-m-d'), $this->mustFind(ScheduledWorkout::class, (int) $done->getId())->getScheduledDate()->format('Y-m-d'));
    }

    private function quickStatus(int $id, \DateTimeImmutable $week, string $status): void
    {
        $crawler = $this->client->request('GET', '/calendar/week/'.$week->format('Y-m-d'));
        $token = (string) $crawler->filter('#cal-status-'.$id.' input[name="_token"]')->attr('value');
        $this->client->request('POST', '/schedule/'.$id.'/quick-status', ['_token' => $token, 'status' => $status]);
    }

    private function place(Workout $workout, \DateTimeImmutable $date, ?string $status): void
    {
        $crawler = $this->client->request('GET', '/calendar/week/'.$date->format('Y-m-d'));
        $token = (string) $crawler->filter('form[action$="/schedule/place"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/schedule/place', [
            '_token' => $token,
            'workoutId' => $workout->getId(),
            'date' => $date->format('Y-m-d'),
        ] + (null === $status ? [] : ['status' => $status]));
    }

    private function assertFlash(string $expected): void
    {
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash', $expected);
    }

    private function onlyScheduled(): ScheduledWorkout
    {
        $this->em->clear();
        $all = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
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
        $workout = (new Workout())->setTitle('Haut du corps')->setSlug('haut-du-corps')->setOwner($owner);
        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function createScheduled(User $owner, \DateTimeImmutable $date, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setScheduledDate($date->setTime(0, 0))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
