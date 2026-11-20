<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #80 : côté athlète, ce que le coach a ajouté se voit. `addedBy` est posé à la
 * création par `ContentAuthorListener` (et au don d'un plan par `PlanTransfer`),
 * badge « Coach » sur les cartes, le calendrier et les pages, encart sur
 * l'accueil pour les sept derniers jours.
 *
 * Le contenu de test est créé AVANT toute connexion : un `persist` fait après
 * `loginUser()` passe lui aussi par l'écouteur, au nom de l'utilisateur connecté.
 */
final class CoachOriginTest extends WebTestCase
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

    // ---- Qui pose `addedBy` ---------------------------------------------------

    public function testWhatTheCoachCreatesForHisAthleteIsMarkedAsHis(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $this->client->loginUser($coach);
        $sheet = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        $form = $sheet->filter('form[action$="/workout/new"]')->form();
        $form['title'] = 'Seuil 3x8';
        $this->client->submit($form);

        $form = $sheet->filter('form[action$="/plan/new"]')->form();
        $form['title'] = 'Prépa semi';
        $this->client->submit($form);

        $workout = $this->em->getRepository(Workout::class)->findOneBy(['title' => 'Seuil 3x8']);
        $plan = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Prépa semi']);
        self::assertSame($coach->getId(), $workout?->getAddedBy()?->getId());
        self::assertSame($coach->getId(), $plan?->getAddedBy()?->getId());

        $sheet = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $sheet->filter('form[action$="/schedule"]')->form();
        $form['workoutId'] = (string) $workout?->getId();
        $form['date'] = (new \DateTimeImmutable('+2 days'))->format('Y-m-d');
        $this->client->submit($form);

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findOneBy(['owner' => $athlete]);
        self::assertSame($coach->getId(), $scheduled?->getAddedBy()?->getId());
    }

    public function testWhatTheAthleteCreatesHimselfHasNoAuthor(): void
    {
        [, $athlete] = $this->coachAndAthlete();
        $this->client->loginUser($athlete);

        $crawler = $this->client->request('GET', '/workout');
        $this->client->submit($crawler->filter('form[action$="/workout/new"]')->form());

        $workout = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $athlete]);
        self::assertNotNull($workout);
        self::assertNull($workout->getAddedBy());
    }

    /**
     * L'athlète place lui-même le plan que son coach a écrit : la séance datée
     * est la sienne (`addedBy` null, rien dans l'encart), mais elle reste « du
     * coach » pour le badge, par la case qu'elle référence.
     */
    public function testASessionTheAthletePlacesFromHisCoachsPlanStillCreditsTheCoach(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $cell = $this->workout($athlete, 'Fractionné', $coach)->setPlanLocal(true);
        $plan = $this->plan($athlete, 'Bloc VMA', $coach);
        $this->em->persist((new PlanItem())->setPlanTemplate($plan)->setWorkout($cell)->setWeekNumber(1)->setDayOfWeek(1));
        $this->em->flush();

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/calendar/'.date('Y/n'));
        $form = $crawler->filter('form[action$="/schedule/plan"]')->form();
        $form['plan_instantiation[planTemplate]'] = (string) $plan->getId();
        $form['plan_instantiation[startDate]'] = (new \DateTimeImmutable('monday next week'))->format('Y-m-d');
        $this->client->submit($form);

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findOneBy(['owner' => $athlete]);
        self::assertNotNull($scheduled);
        self::assertNull($scheduled->getAddedBy());
        self::assertSame($coach->getId(), $scheduled->getContributor()?->getId());

        $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSelectorTextContains('.kd-ownerbar--coach', 'Préparée par');
        self::assertSelectorTextContains('.kd-ownerbar--coach', 'coach@example.com');
    }

    // ---- Badge « Coach » ------------------------------------------------------

    public function testTheAthleteSeesTheCoachBadgeOnHisLibrariesAndPages(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $fromCoach = $this->workout($athlete, 'Seuil 3x8', $coach);
        $this->workout($athlete, 'Footing perso');
        $plan = $this->plan($athlete, 'Prépa semi', $coach);

        $this->client->loginUser($athlete);

        $crawler = $this->client->request('GET', '/workout');
        self::assertCount(1, $crawler->filter('.kd-libcard .kd-scope--coach'), 'Une carte du coach, une à l\'athlète.');
        self::assertStringContainsString('coach@example.com', $crawler->filter('.kd-scope--coach')->text());

        $crawler = $this->client->request('GET', '/plan-template');
        self::assertCount(1, $crawler->filter('.kd-libcard .kd-scope--coach'));

        $this->client->request('GET', '/workout/'.$fromCoach->getId());
        self::assertSelectorTextContains('.kd-ownerbar--coach', 'Ajoutée par');
        $this->client->request('GET', '/workout/'.$fromCoach->getId().'/edit');
        self::assertSelectorExists('.kd-ownerbar--coach');
        $this->client->request('GET', '/plan-template/'.$plan->getId());
        self::assertSelectorTextContains('.kd-ownerbar--coach', 'Ajouté par');
        $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        self::assertSelectorExists('.kd-ownerbar--coach');
    }

    public function testTheCalendarFlagsTheSessionsTheCoachAdded(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $today = new \DateTimeImmutable('today');
        $this->scheduled($athlete, $this->workout($athlete, 'Seuil 3x8'), $today, $coach);
        $this->scheduled($athlete, $this->workout($athlete, 'Footing perso'), $today);

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/calendar/week/'.$today->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-calevent .kd-scope--coach'));
    }

    /** Le coach ne voit pas son propre nom partout chez son athlète. */
    public function testTheCoachDoesNotSeeHisOwnBadge(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $workout = $this->workout($athlete, 'Seuil 3x8', $coach);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout');
        self::assertStringContainsString('Seuil 3x8', $crawler->filter('.kd-libcard')->text(), 'La carte est bien là.');
        self::assertCount(0, $crawler->filter('.kd-scope--coach'));
        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertSelectorNotExists('.kd-ownerbar--coach');
    }

    // ---- Encart d'accueil -----------------------------------------------------

    public function testTheHomeSaysWhatTheCoachAddedThisWeek(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $workout = $this->workout($athlete, 'Seuil 3x8', $coach);
        $this->workout($athlete, 'Cellule de plan', $coach)->setPlanLocal(true);
        $this->plan($athlete, 'Prépa semi', $coach);
        $day = new \DateTimeImmutable('+1 day');
        $this->scheduled($athlete, $workout, $day, $coach);
        $this->scheduled($athlete, $workout, $day->modify('+2 days'), $coach);
        $this->scheduled($athlete, $workout, $day->modify('+4 days'), $coach);
        // Posée par l'athlète lui-même, et une autre du coach mais d'il y a dix jours.
        $this->scheduled($athlete, $workout, $day->modify('+5 days'));
        $old = $this->scheduled($athlete, $workout, $day->modify('+6 days'), $coach);
        $old->setCreatedAt(new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        $this->client->loginUser($athlete);
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__coachadded', 'coach@example.com a ajouté ces 7 derniers jours : 3 séances à ton agenda, 1 séance dans « Mes séances » et 1 plan.');
        self::assertSelectorExists('.kd-home__coachadded a[href="/calendar"]');
    }

    public function testTheHomeHasNoInsertWithoutRecentCoachContent(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $this->workout($athlete, 'Footing perso');
        $this->workout($athlete, 'Ancienne', $coach)->setCreatedAt(new \DateTimeImmutable('-8 days'));
        $this->em->flush();

        $this->client->loginUser($athlete);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.kd-home__coachadded');

        // Et le coach ne voit pas chez lui ce qu'il a ajouté chez l'autre.
        $this->workout($athlete, 'Récente', $coach);
        $this->client->loginUser($coach);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.kd-home__coachadded');
    }

    /** Un plan donné entre dans la bibliothèque de l'athlète le jour du don. */
    public function testAGivenPlanCountsFromTheDayItWasGiven(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $plan = $this->plan($coach, 'Bloc force');
        $plan->setCreatedAt(new \DateTimeImmutable('-30 days'));
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        $form = $crawler->filter('form[action$="/assign"]')->form();
        $form['athlete'] = (string) $athlete->getId();
        $this->client->submit($form);

        $this->client->loginUser($athlete);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.kd-home__coachadded', '1 plan.');
    }

    // ---- Outils ---------------------------------------------------------------

    /** @return array{User, User} */
    private function coachAndAthlete(): array
    {
        $coach = $this->user('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->user('athlete@example.com');
        $this->em->persist(
            (new Coaching())
                ->setCoach($coach)
                ->setAthlete($athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($coach)
                ->setRespondedAt(new \DateTimeImmutable())
        );
        $this->em->flush();

        return [$coach, $athlete];
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function workout(User $owner, string $title, ?User $addedBy = null): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug('w-'.bin2hex(random_bytes(4)))
            ->setAddedBy($addedBy);
        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function plan(User $owner, string $title, ?User $addedBy = null): PlanTemplate
    {
        $plan = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDurationWeeks(4)
            ->setSlug('p-'.bin2hex(random_bytes(4)))
            ->setAddedBy($addedBy);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    private function scheduled(User $owner, Workout $workout, \DateTimeImmutable $date, ?User $addedBy = null): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::PLANNED)
            ->setAddedBy($addedBy);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
