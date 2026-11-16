<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'accueil (`/`) : « où j'en suis ». Il est séparé du profil (`/profile`),
 * qu'il ne remplace pas : il n'en reprend ni les records ni la fiche.
 */
final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : tout ce qui référence user passe avant les users.
        foreach ([Coaching::class, Goal::class, ScheduledWorkout::class, PlanTemplate::class, Workout::class, Exercise::class, User::class] as $class) {
            foreach ($this->em->getRepository($class)->findAll() as $entity) {
                $this->em->remove($entity);
            }
            $this->em->flush();
        }
    }

    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testTheNextGoalLeadsAndTheOneAfterFollows(): void
    {
        $user = $this->createUser('home@example.com');
        foreach (['Trail des Cimes' => '+38 days', 'Total SBD 470 kg' => '+71 days', 'Marathon' => '+120 days'] as $title => $when) {
            $this->em->persist((new Goal())->setOwner($user)->setTitle($title)->setTargetDate(new \DateTimeImmutable($when)));
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-home__goaltitle', 'Trail des Cimes');
        self::assertSelectorTextContains('.kd-home__goalcount', 'J-38');
        self::assertSelectorTextContains('.kd-home__goalnext', 'Total SBD 470 kg');
        self::assertStringNotContainsString('Marathon', $crawler->filter('.kd-home__goal')->text(), 'Deux objectifs, pas trois.');
        self::assertCount(0, $crawler->filter('.kd-deflist--profile'), 'La fiche est sur /profile, pas sur l\'accueil.');
    }

    public function testTheMonthCardsReadTheCurrentMonth(): void
    {
        $user = $this->createUser('month@example.com');
        $today = new \DateTimeImmutable('today');
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Faite')
            ->setScheduledDate($today)->setStatus(ScheduledStatus::DONE));
        // Le mois dernier ne compte pas.
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Ancienne')
            ->setScheduledDate($today->modify('first day of last month'))->setStatus(ScheduledStatus::MISSED));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-observance__pct', '100%');
        // La séance faite est vide : elle compte quand même, sous « Sans
        // contenu », pour que la carte par activité dise le même total que
        // l'observance d'à côté (#62).
        self::assertSelectorTextContains('.kd-home__card:nth-child(2) .kd-eyebrow', '1 séance faite');
        self::assertSelectorTextContains('.kd-home__acts', 'Sans contenu');
        self::assertGreaterThan(
            0,
            $crawler->filter('a.kd-home__card[href="/profile/stats?range='.$today->format('Y-m').'"]')->count(),
            'Les cartes du mois ouvrent les statistiques du même mois.',
        );
    }

    public function testAPendingCoachingRequestAsksForAnAnswer(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->em->persist((new Coaching())->setCoach($coach)->setAthlete($athlete)
            ->setRequestedBy($coach)->setStatus(CoachingStatus::PENDING));
        $this->em->flush();

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__coach', 'coach@example.com propose de t\'entraîner');
        self::assertCount(2, $crawler->filter('.kd-home__coach form'));

        // Celui qui a demandé n'a rien à répondre.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.kd-home__coach'));
    }

    public function testTheShortcutsLeadToTheReadingPages(): void
    {
        $this->client->loginUser($this->createUser('links@example.com'));
        $crawler = $this->client->request('GET', '/');

        foreach (['/profile', '/profile/log', '/profile/history', '/profile/stats', '/exercise', '/goal'] as $href) {
            self::assertCount(1, $crawler->filter('.kd-home__index a[href="'.$href.'"]'), $href);
        }
    }

    public function testAnEmptyAccountIsGuidedThroughThreeSteps(): void
    {
        $user = $this->createUser('new@example.com');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-home__start', 'Kadens sert à préparer tes entraînements');
        self::assertCount(3, $crawler->filter('.kd-home__step'));
        self::assertCount(0, $crawler->filter('.kd-home__step--done'));
        self::assertSelectorTextContains('.kd-home__step--current', 'Crée une séance');
        self::assertCount(1, $crawler->filter('.kd-home__step a[href="/workout"]'));
        self::assertCount(1, $crawler->filter('.kd-home__step a[href="/plan-template"]'));
        // L'objectif n'est pas la première chose dont on a besoin : pas
        // d'invitation à s'en fixer un tant que le compte démarre (A-01).
        self::assertCount(0, $crawler->filter('a[href="/goal/new"]'));
        self::assertCount(0, $crawler->filter('.kd-home__next'));
    }

    public function testEachStepIsTickedOnTheRealData(): void
    {
        $user = $this->createUser('steps@example.com');
        $workout = (new Workout())->setOwner($user)->setTitle('Haut du corps')->setSlug('haut-du-corps');
        $this->em->persist($workout);
        // Un plan vide n'assemble rien : il ne coche pas l'étape.
        $this->em->persist((new PlanTemplate())->setOwner($user)->setTitle('Vide')->setSlug('vide')->setDurationWeeks(4));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.kd-home__step--done'));
        self::assertSelectorTextContains('.kd-home__step--current', 'Assemble-la dans un plan');

        $plan = (new PlanTemplate())->setOwner($user)->setTitle('Force 4 semaines')->setSlug('force-4-semaines')->setDurationWeeks(4);
        $plan->addPlanItem((new PlanItem())->setWorkout($workout)->setWeekNumber(1)->setDayOfWeek(1));
        $this->em->persist($plan);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(2, $crawler->filter('.kd-home__step--done'));
        self::assertSelectorTextContains('.kd-home__step--current', 'Place le plan dans ton agenda');
        // La dernière étape ouvre la pose de ce plan au calendrier (#68).
        self::assertCount(1, $crawler->filter('.kd-home__step--current a[href="/calendar?place='.$plan->getId().'"]'));
    }

    public function testTheStartDisappearsOnceTheAgendaHasASession(): void
    {
        $user = $this->createUser('started@example.com');
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Footing')
            ->setScheduledDate(new \DateTimeImmutable('-3 days'))->setStatus(ScheduledStatus::DONE));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('.kd-home__start'));
        // Plus rien de prévu : le bloc renvoie vers l'agenda.
        self::assertSelectorTextContains('.kd-home__next', 'Rien de prévu pour l\'instant');
        self::assertCount(1, $crawler->filter('.kd-home__next a[href="/calendar"]'));
    }

    public function testTheNextSessionLeadsToTheDatedSession(): void
    {
        $user = $this->createUser('next@example.com');
        $today = new \DateTimeImmutable('today');
        // Faite aujourd'hui : ce n'est plus celle qu'il reste à faire.
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Déjà faite')
            ->setScheduledDate($today)->setStatus(ScheduledStatus::DONE));
        $next = (new ScheduledWorkout())->setOwner($user)->setTitle('Fractionné 6 x 400 m')
            ->setScheduledDate($today->modify('+1 day'));
        $double = (new ScheduledWorkout())->setOwner($user)->setTitle('Gainage')
            ->setScheduledDate($today->modify('+1 day'));
        $later = (new ScheduledWorkout())->setOwner($user)->setTitle('Sortie longue')
            ->setScheduledDate($today->modify('+3 days'));
        foreach ([$next, $double, $later] as $session) {
            $this->em->persist($session);
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__nexteyebrow', 'Ta prochaine séance · demain');
        self::assertCount(2, $crawler->filter('.kd-home__nextlist li'), 'Les deux séances du jour, pas celle d\'après.');
        self::assertCount(1, $crawler->filter('.kd-home__nextlist a[href="/schedule/'.$next->getId().'"]'));
        self::assertStringNotContainsString('Sortie longue', $crawler->filter('.kd-home__next')->text());
        self::assertStringNotContainsString('Déjà faite', $crawler->filter('.kd-home__next')->text());

        // Une séance encore prévue aujourd'hui passe devant.
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Mobilité')->setScheduledDate($today));
        $this->em->flush();
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__nexteyebrow', 'Aujourd\'hui');
        self::assertSelectorTextContains('.kd-home__nextlist', 'Mobilité');
    }

    public function testACoachFindsTheirAthletesFromHome(): void
    {
        $coach = $this->createUser('coach-home@example.com')->setRoles(['ROLE_COACH']);
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__athletes', 'Mes athlètes');
        self::assertSelectorTextContains('.kd-home__athletes', 'Invite ton premier athlète');
        self::assertCount(1, $crawler->filter('a.kd-home__athletes[href="/coaching"]'));
        // Sans athlète, il a encore son propre démarrage.
        self::assertCount(1, $crawler->filter('.kd-home__start'));

        $athlete = $this->createUser('suivi@example.com');
        $this->em->persist((new Coaching())->setCoach($coach)->setAthlete($athlete)
            ->setRequestedBy($coach)->setStatus(CoachingStatus::ACCEPTED));
        $this->em->flush();
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__athletes', '1 athlète suivi');
        self::assertCount(0, $crawler->filter('.kd-home__start'), 'Son travail se fait chez ses athlètes.');

        // Un athlète n'a pas de tuile coach.
        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.kd-home__athletes'));
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
