<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le statut d'une séance datée, choisi depuis la pastille du calendrier (#89).
 * Ce que ces tests tiennent : le statut posté est **nommé**, jamais déduit du
 * précédent. L'ancien cycle prévue → faite → manquée faisait d'un clic de trop
 * un changement invisible ; poster deux fois le même choix doit donc laisser le
 * même statut.
 */
final class ScheduledWorkoutQuickStatusTest extends WebTestCase
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

    /** Le statut posté est celui qu'on obtient, même posté deux fois. */
    public function testThePostedStatusIsTheOneSet(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::PLANNED);
        $scheduled->setCompletionNotes('Dos raide');
        $this->em->flush();
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $this->post($id, 'missed');
        $this->post($id, 'missed');

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $id);
        self::assertSame(ScheduledStatus::MISSED, $stored->getStatus());
        self::assertSame('Dos raide', $stored->getCompletionNotes(), 'Geste express : la note d\'écart ne bouge pas.');
    }

    /** Un statut inconnu ne change rien : pas de repli sur un « suivant ». */
    public function testAnUnknownStatusChangesNothing(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $this->post($id, 'cancelled');
        $this->post($id, '');

        $this->em->clear();
        self::assertSame(ScheduledStatus::DONE, $this->mustFind(ScheduledWorkout::class, $id)->getStatus());
    }

    /** En Turbo, la pastille revient en place avec le nouveau choix marqué. */
    public function testTheStreamRerendersThePillWithTheNewChoice(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $this->post($id, 'done', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="cal-event-'.$id.'"', $html);
        self::assertMatchesRegularExpression('/value="done"[^>]*aria-pressed="true"/', $html);
        self::assertStringContainsString('kd-calevent__check', $html);
    }

    /**
     * @param array<string, string> $server
     */
    private function post(int $id, string $status, array $server = []): void
    {
        // Le jeton se lit sur la page, comme le ferait le navigateur : le
        // gestionnaire CSRF de test est lié à la session du client.
        $crawler = $this->client->request('GET', '/calendar/2026/3');
        $token = (string) $crawler->filter('#cal-status-'.$id.' input[name="_token"]')->attr('value');

        $this->client->request('POST', '/schedule/'.$id.'/quick-status', [
            '_token' => $token,
            'status' => $status,
        ], [], $server);
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createScheduled(User $owner, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-15'))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
