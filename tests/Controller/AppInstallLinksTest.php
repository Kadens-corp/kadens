<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #74 : la page `/app` n'était trouvable que depuis un petit lien des
 * paramètres. Partout où le site cite l'app ou le téléphone, il y mène.
 *
 * Les phrases « les charges se saisissent dans l'app » (modale, journal,
 * statistiques, fiche coach) sont couvertes par `LogInAppNoticeTest` (#63).
 * Ici, le reste : l'aide, l'historique vide, les statistiques et les
 * paramètres.
 */
final class AppInstallLinksTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    public function testTheHelpPageHasASectionOnTheApp(): void
    {
        $crawler = $this->client->request('GET', '/aide');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-helpindex a[href="#app"]'));
        self::assertSelectorTextContains('#app', 'seulement sur Android');
        self::assertCount(1, $crawler->filter('#app a[href="/app"]'));
        // Le texte du calendrier dit où se saisissent les séries.
        self::assertCount(1, $crawler->filter('#calendar a[href="/app"]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pagesThatMentionTheApp(): iterable
    {
        yield 'historique vide' => ['/profile/history', '.kd-empty__hint'];
        yield 'statistiques' => ['/profile/stats', '.kd-pagehead__scope'];
        yield 'appareils connectés' => ['/profile/settings', '#devices-panel'];
        yield 'connecter un téléphone' => ['/profile/settings', '#pairing-panel'];
    }

    #[DataProvider('pagesThatMentionTheApp')]
    public function testAPageThatMentionsTheAppLinksToIt(string $url, string $scope): void
    {
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter($scope.' a[href="/app"]')->count());
    }
}
