<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le bandeau de synthèse de la vue semaine suit un changement de statut fait
 * sans rechargement (#120, B-03 athlète). Avant, le flux ne remplaçait que la
 * pastille : une séance repassée « Prévue » laissait « 1 faite · 100 % » au-dessus
 * d'elle jusqu'au rechargement.
 */
final class CalendarWeekSummaryStreamTest extends WebTestCase
{
    use PurgesDatabase;

    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'];

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

    /** La page porte les deux cibles, ligne du pli et barre. */
    public function testTheWeekPageExposesBothTargets(): void
    {
        $owner = $this->createUser();
        $this->createScheduled($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);

        $this->client->request('GET', '/calendar/week/2026-03-11');

        self::assertSelectorTextContains('#week-summary-line', '1 séance');
        self::assertSelectorTextContains('#week-summary-line', 'assiduité 100%');
        self::assertSelectorTextContains('#week-summary', '1 faite');
    }

    /** Le cas de l'audit : repasser une séance faite en prévue depuis la modale. */
    public function testTheModalStreamRerendersTheWeekSummary(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/calendar/week/2026-03-11');
        $form = $crawler->filter('#cal-save-'.$id)->form(['status' => 'planned']);
        $this->client->submit($form, [], self::TURBO);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="cal-event-'.$id.'"', $html);
        self::assertStringContainsString('target="week-summary-line"', $html);
        self::assertStringContainsString('target="week-summary"', $html);
        self::assertStringContainsString('assiduité —', $html);
        self::assertStringContainsString('0 faite', $html);
        self::assertStringContainsString('1 prévue', $html);
        // Le pli n'est jamais remplacé : il se rouvrirait sous les doigts.
        self::assertStringNotContainsString('kd-calfold', $html);
    }

    /** La vue mois n'a pas de bandeau : rien à remplacer, rien d'envoyé. */
    public function testTheMonthStreamCarriesNoWeekSummary(): void
    {
        $owner = $this->createUser();
        $scheduled = $this->createScheduled($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $crawler = $this->client->request('GET', '/calendar/2026/3');
        $form = $crawler->filter('#cal-save-'.$id)->form(['status' => 'planned']);
        $this->client->submit($form, [], self::TURBO);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="cal-event-'.$id.'"', $html);
        self::assertStringNotContainsString('week-summary', $html);
    }

    private function createUser(): User
    {
        $user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createScheduled(User $owner, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-10'))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
