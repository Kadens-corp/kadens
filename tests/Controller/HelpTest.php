<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * L'aide contextuelle (#72). Il n'y avait ni page d'aide ni « ? » (A-54,
 * C-37) : chaque blocage se réglait par essais. Ce qui se joue ici :
 *
 * 1. Le compositeur, l'éditeur de plan, le calendrier (mois et semaine) et la
 *    fiche athlète côté coach portent un bouton « Aide ».
 * 2. Le texte est dans le HTML de la page : aucun chargement à la demande, la
 *    page reste lisible hors ligne.
 * 3. `/aide` regroupe ces textes, avec le mini-guide coach pour un coach
 *    seulement. Elle se trouve dans le menu du compte.
 * 4. Sur /coaching, le guide coach est ouvert tant que le coach n'a personne.
 */
final class HelpTest extends WebTestCase
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

    public function testTheComposerExplainsBlocksAndPlanCopies(): void
    {
        $user = $this->createUser('athlete@example.com');
        $workout = (new Workout())->setOwner($user)->setTitle('Upper A')->setSlug('upper-a');
        $this->em->persist($workout);
        $this->em->flush();

        $this->client->loginUser($user);
        $help = $this->helpOn('/workout/'.$workout->getId().'/edit', 'composer');

        self::assertStringContainsString('blocs', $help->text());
        self::assertStringContainsString('Les cases d\'un plan sont des copies', $help->text());
    }

    public function testThePlanEditorSaysAPlanHasNoDates(): void
    {
        $user = $this->createUser('athlete@example.com');
        $plan = (new PlanTemplate())->setOwner($user)->setTitle('Prépa')->setDurationWeeks(4)->setSlug('prepa');
        $this->em->persist($plan);
        $this->em->flush();

        $this->client->loginUser($user);
        $help = $this->helpOn('/plan-template/'.$plan->getId().'/edit', 'plan');

        self::assertStringContainsString('sans dates', $help->text());
    }

    public function testBothCalendarViewsCarryTheSameHelp(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        foreach (['/calendar/2026/11', '/calendar/week/2026-11-16'] as $url) {
            $help = $this->helpOn($url, 'calendar');
            self::assertStringContainsString('prévue, faite ou manquée', $help->text());
        }
    }

    public function testTheAthleteSheetHelpNamesTheAthlete(): void
    {
        [$coach, $athlete] = $this->createRelation();

        $this->client->loginUser($coach);
        $help = $this->helpOn('/coach/athlete/'.$athlete->getId(), 'athlete');

        self::assertStringContainsString('ton poste de travail pour Trailer38', $help->text());
    }

    public function testTheHelpPageIsBehindLogin(): void
    {
        $this->client->request('GET', '/aide');

        self::assertResponseRedirects('/login');
    }

    public function testAnAthleteGetsTheHelpPageWithoutTheCoachGuide(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $crawler = $this->client->request('GET', '/aide');

        self::assertResponseIsSuccessful();
        foreach (['composer', 'plan', 'calendar'] as $topic) {
            self::assertCount(1, $crawler->filter('section#'.$topic), 'Section '.$topic);
            self::assertGreaterThan(0, $crawler->filter('section#'.$topic.' .kd-help__body p')->count());
        }
        self::assertCount(0, $crawler->filter('#coach'));
        self::assertCount(0, $crawler->filter('#athlete'));
        // La porte d'entrée : le menu du compte, allumé sur la page.
        $link = $crawler->filter('.kd-usermenu__list a[href="/aide"]');
        self::assertCount(1, $link);
        self::assertSame('page', $link->attr('aria-current'));
    }

    public function testACoachGetsTheFourStepGuide(): void
    {
        $this->client->loginUser($this->createUser('coach@example.com', coach: true));
        $crawler = $this->client->request('GET', '/aide');

        self::assertResponseIsSuccessful();
        $steps = $crawler->filter('#coach .kd-help__steps li');
        self::assertCount(4, $steps);
        self::assertStringStartsWith('Inviter.', $steps->eq(0)->text());
        self::assertStringStartsWith('Fin de relation.', $steps->eq(3)->text());
        self::assertCount(1, $crawler->filter('#athlete'));
    }

    public function testTheCoachingPageOpensTheGuideUntilTheCoachHasAnAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', coach: true);
        $this->client->loginUser($coach);

        $crawler = $this->client->request('GET', '/coaching');
        self::assertNotNull($crawler->filter('details.kd-coachguide')->attr('open'));

        $this->createRelation($coach);
        $crawler = $this->client->request('GET', '/coaching');
        self::assertCount(1, $crawler->filter('details.kd-coachguide'));
        self::assertNull($crawler->filter('details.kd-coachguide')->attr('open'));
    }

    public function testAnAthleteDoesNotGetTheCoachGuideOnCoaching(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $crawler = $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.kd-coachguide'));
    }

    /**
     * Ouvre la page et rend le corps du panneau d'aide, après avoir vérifié
     * qu'il y en a un seul, qu'il est fermé au chargement et qu'il renvoie à
     * la bonne section de `/aide`.
     */
    private function helpOn(string $url, string $topic): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $help = $crawler->filter('details.kd-help');
        self::assertCount(1, $help, 'Un seul bouton « Aide » sur '.$url);
        self::assertNull($help->attr('open'), 'Fermé au chargement.');
        self::assertStringStartsWith('Aide : ', (string) $help->filter('summary')->attr('aria-label'));
        self::assertSame('/aide#'.$topic, $help->filter('.kd-help__more')->attr('href'));

        $body = $help->filter('.kd-help__body');
        $lines = $body->filter('p')->count();
        self::assertGreaterThanOrEqual(3, $lines);
        self::assertLessThanOrEqual(5, $lines);

        return $body;
    }

    /**
     * @return array{User, User}
     */
    private function createRelation(?User $coach = null): array
    {
        $coach ??= $this->createUser('coach@example.com', coach: true);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setUsername('Trailer38');
        $this->em->persist((new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus(CoachingStatus::ACCEPTED)
            ->setRequestedBy($coach)
            ->setRespondedAt(new \DateTimeImmutable()));
        $this->em->flush();

        return [$coach, $athlete];
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
