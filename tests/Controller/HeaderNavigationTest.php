<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\User;
use App\Enum\CoachingStatus;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La navigation principale et le menu de compte (#71).
 *
 * Statistiques, journal et historique ne s'atteignaient que par les tuiles de
 * l'accueil (A-04), l'espace coach que par le menu de l'avatar (C-01), et le
 * rôle coach ne se lisait que dans les paramètres (C-02). Ce qui se joue ici :
 *
 * 1. « Progrès » est dans la barre et s'allume sur ses trois pages.
 * 2. « Athlètes » n'existe que pour un coach, et s'allume aussi sur la fiche
 *    d'un athlète. Le menu de compte ne double pas cette porte.
 * 3. Le rôle se lit sous le pseudo.
 */
final class HeaderNavigationTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testAnAthleteFindsProgressButNoAthletesEntry(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-nav a.kd-nav__link[href="/profile/stats"]'));
        self::assertSelectorTextContains('.kd-nav a[href="/profile/stats"]', 'Progrès');
        self::assertCount(0, $crawler->filter('.kd-nav a[href="/coaching"]'), 'Pas d\'entrée « Athlètes » sans ROLE_COACH.');
        self::assertCount(5, $crawler->filter('.kd-nav__link'));
        // L'athlète garde « Coaching » dans le menu : c'est là qu'il trouve ses coachs.
        self::assertCount(1, $crawler->filter('.kd-usermenu__list a[href="/coaching"]'));
        self::assertSelectorTextSame('.kd-usermenu__role', 'Athlète');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function progressPages(): iterable
    {
        yield 'statistiques' => ['/profile/stats'];
        yield 'journal' => ['/profile/log'];
        yield 'historique' => ['/profile/history'];
    }

    #[DataProvider('progressPages')]
    public function testProgressLightsUpOnEachOfItsPages(string $url): void
    {
        $this->client->loginUser($this->createUser('progres@example.com'));
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $active = $crawler->filter('.kd-nav__link.is-active');
        self::assertCount(1, $active, 'Une seule entrée allumée.');
        self::assertStringContainsString('Progrès', $active->text());
        self::assertSame('page', $active->attr('aria-current'));
        // Ces pages ne sont plus dans le menu : son déclencheur reste éteint.
        self::assertCount(0, $crawler->filter('.kd-usermenu__trigger.is-active'));
    }

    public function testACoachGetsAthletesInTheBarAndSeesTheirRole(): void
    {
        $this->client->loginUser($this->createUser('coach@example.com', coach: true));
        $crawler = $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('.kd-nav__link'));
        $active = $crawler->filter('.kd-nav__link.is-active');
        self::assertCount(1, $active);
        self::assertSame('/coaching', $active->attr('href'));
        self::assertStringContainsString('Athlètes', $active->text());
        // Une seule porte vers /coaching : le menu ne la double pas.
        self::assertCount(0, $crawler->filter('.kd-usermenu__list a[href="/coaching"]'));
        self::assertCount(0, $crawler->filter('.kd-usermenu__trigger.is-active'));
        self::assertSelectorTextSame('.kd-usermenu__role', 'Athlète et coach');
    }

    public function testAthletesStaysLitOnTheAthleteSheet(): void
    {
        $coach = $this->createUser('coach@example.com', coach: true);
        $athlete = $this->createUser('athlete@example.com');
        $this->em->persist((new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus(CoachingStatus::ACCEPTED)
            ->setRequestedBy($coach)
            ->setRespondedAt(new \DateTimeImmutable()));
        $this->em->flush();

        $this->client->loginUser($coach);
        // Les stats d'un athlète ne sont pas les « Progrès » du coach.
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/stats');

        self::assertResponseIsSuccessful();
        $active = $crawler->filter('.kd-nav__link.is-active');
        self::assertCount(1, $active);
        self::assertSame('/coaching', $active->attr('href'));
    }

    private function createUser(string $email, bool $coach = false): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        if ($coach) {
            $user->setRoles(['ROLE_COACH']);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
