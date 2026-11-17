<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ImportedActivity;
use App\Entity\IntervalsConnection;
use App\Entity\User;
use App\Repository\IntervalsConnectionRepository;
use App\Service\IntervalsAutoSync;
use App\Service\IntervalsImporter;
use App\Service\SecretBox;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `/cron/intervals` : la synchro automatique appelée par le planificateur de
 * tâches Infomaniak. Seul le transport HTTP vers Intervals.icu est simulé ; la
 * clé « revoquee » y répond 401, comme une clé supprimée côté Intervals.
 */
final class CronControllerTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        static::getContainer()->set('intervals.client', new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $auth = $options['normalized_headers']['authorization'][0] ?? '';

            if (str_contains($auth, base64_encode('API_KEY:revoquee'))) {
                return new MockResponse('{}', ['http_code' => 401]);
            }

            if (str_contains($url, '/streams.json')) {
                return new MockResponse(json_encode([['type' => 'time', 'data' => [0, 1, 2]]], \JSON_THROW_ON_ERROR));
            }

            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

            return new MockResponse(json_encode([
                ['id' => 'i1', 'source' => 'GARMIN_CONNECT', 'type' => 'Run', 'start_date_local' => $today.'T07:00:00', 'distance' => 10000, 'moving_time' => 3000],
            ], \JSON_THROW_ON_ERROR));
        }, 'https://intervals.icu/api/v1/'));
    }

    public function testRejectsMissingOrWrongToken(): void
    {
        $this->connect($this->createUser('athlete@example.com'), 'cle-valide');

        $this->client->request('GET', '/cron/intervals');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/cron/intervals?token=mauvais');
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->em->getRepository(ImportedActivity::class)->findAll());
    }

    /** Une clé révoquée est comptée en échec et n'empêche pas les autres comptes. */
    public function testSyncsEveryConnectedAccountAndSurvivesARevokedKey(): void
    {
        $revoked = $this->connect($this->createUser('revoque@example.com'), 'revoquee');
        $valid = $this->connect($this->createUser('athlete@example.com'), 'cle-valide');

        $this->client->request('GET', '/cron/intervals?token=test-cron-secret');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok', 'synced' => 1, 'failed' => 1, 'notReached' => 0, 'imported' => 1, 'attached' => 0, 'remaining' => 0],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );

        $this->em->clear();
        self::assertNotNull($this->em->find(IntervalsConnection::class, $valid->getId())?->getLastSyncedAt());
        self::assertCount(1, $this->em->getRepository(ImportedActivity::class)->findAll());
        self::assertNotNull($this->em->find(IntervalsConnection::class, $revoked->getId()));
    }

    /** Budget épuisé : les comptes non atteints sont comptés, et rien n'est touché. */
    public function testStopsBetweenAccountsOnceTheTimeBudgetIsSpent(): void
    {
        $this->connect($this->createUser('a@example.com'), 'cle-valide');
        $this->connect($this->createUser('b@example.com'), 'cle-valide');

        $container = static::getContainer();
        $result = (new IntervalsAutoSync(
            $container->get(IntervalsConnectionRepository::class),
            $container->get(IntervalsImporter::class),
            new NullLogger(),
            timeLimitSeconds: 0,
        ))->run();

        self::assertSame(2, $result['notReached']);
        self::assertSame(0, $result['synced']);
        self::assertSame([], $this->em->getRepository(ImportedActivity::class)->findAll());
    }

    /** Jamais synchronisé d'abord, puis du plus ancien au plus récent. */
    public function testQueuesTheLeastRecentlySyncedAccountsFirst(): void
    {
        $recent = $this->connect($this->createUser('recent@example.com'), 'cle-valide');
        $recent->setLastSyncedAt(new \DateTimeImmutable('-1 hour'));
        $old = $this->connect($this->createUser('old@example.com'), 'cle-valide');
        $old->setLastSyncedAt(new \DateTimeImmutable('-2 days'));
        $never = $this->connect($this->createUser('never@example.com'), 'cle-valide');
        $this->em->flush();

        $queue = static::getContainer()->get(IntervalsConnectionRepository::class)->findForAutoSync();

        self::assertSame([$never->getId(), $old->getId(), $recent->getId()], array_map(static fn (IntervalsConnection $c): ?int => $c->getId(), $queue));
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function connect(User $user, string $apiKey): IntervalsConnection
    {
        $connection = new IntervalsConnection($user, static::getContainer()->get(SecretBox::class)->seal($apiKey), null);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }
}
