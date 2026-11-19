<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Block;
use App\Entity\Goal;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
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
        // Des mots de sportif, pas de médecin ni de code (#84).
        self::assertSelectorTextContains('.kd-home__card .kd-eyebrow', 'assiduité');
        self::assertSelectorTextContains('.kd-observance__sub', '1 faite sur 1 passée');
        self::assertSelectorTextContains('.kd-eyebrow', 'semaine '.(int) $today->format('W'));
        self::assertStringNotContainsString('Observance', $crawler->filter('body')->text());
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

    /**
     * Les sept derniers jours (#131) : J-6 → J, une séance = une nature, et les
     * muscles lus sur le réalisé. La séance d'il y a sept jours sort de la
     * fenêtre, l'échauffement et la série sans valeur sortent du dessin.
     */
    public function testTheLastSevenDaysCountSessionsAndPaintTheMusclesWorked(): void
    {
        $user = $this->createUser('week@example.com');
        $today = new \DateTimeImmutable('today');

        $bench = (new Exercise())->setOwner($user)->setName('Développé couché')
            ->setActivity(ActivityType::GYM)->setTargetAreas([TargetArea::CHEST, TargetArea::TRICEPS]);
        $squat = (new Exercise())->setOwner($user)->setName('Squat')
            ->setActivity(ActivityType::GYM)->setTargetAreas([TargetArea::QUADRICEPS]);
        $run = (new Exercise())->setOwner($user)->setName('Footing')->setActivity(ActivityType::RUNNING);
        foreach ([$bench, $squat, $run] as $exercise) {
            $this->em->persist($exercise);
        }

        // Deux séances de salle dans la fenêtre (aujourd'hui, J-6), une sortie à
        // J-3, et une séance de salle à J-7, hors fenêtre.
        $this->doneSession($user, $bench, $today, [[SetType::WARMUP, 10], [SetType::NORMAL, 8], [SetType::NORMAL, 8], [SetType::NORMAL, 0]]);
        $this->doneSession($user, $squat, $today->modify('-6 days'), [[SetType::NORMAL, 5], [SetType::NORMAL, 5], [SetType::NORMAL, 5]]);
        $this->doneSession($user, $run, $today->modify('-3 days'), []);
        $this->doneSession($user, $squat, $today->modify('-7 days'), [[SetType::NORMAL, 5]]);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $week = $crawler->filter('.kd-home__week');
        self::assertCount(1, $week);
        self::assertStringContainsString('7 derniers jours · 3 séances faites', $week->filter('.kd-eyebrow')->text());

        $acts = $week->filter('.kd-home__act');
        self::assertCount(2, $acts, 'Une ligne par activité.');
        self::assertStringContainsString('Salle de sport', $acts->eq(0)->text());
        self::assertSame('2', $acts->eq(0)->filter('.kd-home__actcount')->text());
        self::assertStringContainsString('Course à pied', $acts->eq(1)->text());
        self::assertSame('1', $acts->eq(1)->filter('.kd-home__actcount')->text());

        self::assertCount(2, $week->filter('.kd-bodymap__plate'), 'Face et dos.');
        $areas = $week->filter('.kd-wkvol__area');
        self::assertCount(3, $areas);
        // 3 séries de squat (la séance de J-7 n'y est pas) ; 2 de développé,
        // attribuées aux pectoraux ET aux triceps, sans l'échauffement ni la
        // série à zéro répétition.
        self::assertStringContainsString('Quadriceps', $areas->eq(0)->text());
        self::assertStringContainsString('3 séries', $areas->eq(0)->text());
        self::assertStringContainsString('Pectoraux', $areas->eq(1)->text());
        self::assertStringContainsString('2 séries', $areas->eq(1)->text());
        self::assertStringContainsString('Triceps', $areas->eq(2)->text());
        self::assertGreaterThan(0, $week->filter('.kd-bodymap path[fill="var(--color-bodymap-3)"]')->count());
        self::assertCount(1, $week->filter('a[href="/profile/history"]'));
    }

    /**
     * Une séance cochée faite sans réalisé compte en séances et ne colore rien :
     * le corps ne se remplit pas avec le prescrit (CLAUDE.md §3), et la carte
     * dit pourquoi il reste nu.
     */
    public function testADoneSessionWithoutLogCountsButPaintsNothing(): void
    {
        $user = $this->createUser('unlogged@example.com');
        $bench = (new Exercise())->setOwner($user)->setName('Développé couché')
            ->setActivity(ActivityType::GYM)->setTargetAreas([TargetArea::CHEST]);
        $this->em->persist($bench);
        $this->doneSession($user, $bench, new \DateTimeImmutable('today'), []);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        $week = $crawler->filter('.kd-home__week');
        self::assertStringContainsString('1 séance faite', $week->filter('.kd-eyebrow')->text());
        self::assertCount(0, $week->filter('.kd-bodymap'));
        self::assertStringContainsString('Aucune série consignée', $week->text());
    }

    public function testAnIdleWeekSaysSo(): void
    {
        $user = $this->createUser('idle@example.com');
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Demain')
            ->setScheduledDate(new \DateTimeImmutable('+1 day')));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertStringContainsString('Aucune séance faite ces 7 derniers jours', $crawler->filter('.kd-home__week')->text());
    }

    public function testAStartingAccountHasNoWeekCard(): void
    {
        $this->client->loginUser($this->createUser('new@example.com'));
        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.kd-home__start'));
        self::assertCount(0, $crawler->filter('.kd-home__week'), 'Rien de fait : les trois étapes suffisent.');
    }

    /**
     * Une séance faite d'un exercice, et son réalisé.
     *
     * @param list<array{0: SetType, 1: int}> $sets type et répétitions de chaque série consignée
     */
    private function doneSession(User $user, Exercise $exercise, \DateTimeImmutable $date, array $sets): void
    {
        $workout = (new Workout())->setOwner($user)->setTitle($exercise->getName() ?? 'Séance')
            ->setSlug('seance-'.bin2hex(random_bytes(4)));
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())->setExercise($exercise)->setPosition(0)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)->setSets(3)->setReps(8),
        );
        $workout->addBlock($block);
        $this->em->persist($workout);
        $this->em->persist($block);

        $scheduled = (new ScheduledWorkout())->setOwner($user)->setWorkout($workout)
            ->setScheduledDate($date)->setStatus(ScheduledStatus::DONE);

        if ([] !== $sets) {
            $logged = (new LoggedExercise())->setExerciseName((string) $exercise->getName())
                ->setExercise($exercise)->setPosition(0);
            foreach ($sets as $position => [$type, $reps]) {
                $logged->addLoggedSet((new LoggedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg(60.0));
            }
            $scheduled->addLoggedExercise($logged);
        }

        $this->em->persist($scheduled);
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
