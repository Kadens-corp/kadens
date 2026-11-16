<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\ImportedActivity;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Tests\FindsEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Espace coach : accès à l'athlète, création « pour lui » (owner = athlète) et
 * pose sur son calendrier. Couvre aussi l'extension des voters : un coach accepté
 * édite le contenu de son athlète, un coach non accepté est refusé.
 */
final class CoachControllerTest extends WebTestCase
{
    use FindsEntities;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : coaching référence user, il passe donc avant les users.
        foreach ($this->em->getRepository(Coaching::class)->findAll() as $coaching) {
            $this->em->remove($coaching);
        }
        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
        }
        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    /**
     * Il n'y a plus de tableau de bord coach : la liste des athlètes vit sur
     * /coaching. Seule la fiche athlète reste sous /coach, donc sous ROLE_COACH.
     */
    public function testAthletePageForbiddenWithoutCoachRole(): void
    {
        $coach = $this->createUser('nocoach@example.com');
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /** ROLE_COACH ouvre /coach, pas l'accès à un athlète donné. */
    public function testAthletePageForbiddenWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAthletePageShowsAthleteContent(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné 30/30');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fractionné 30/30', $crawler->html());
    }

    /**
     * Le coach a besoin des mesures et records de l'athlète pour programmer : la
     * page athlète rend les mêmes fragments que la page profil, en lecture seule.
     */
    public function testAthletePageShowsAthleteProfileAndStats(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setSquat1rmKg(140.0);
        $this->em->flush();
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Fiche athlète', $html);
        self::assertStringContainsString('Statistiques', $html);
        self::assertStringContainsString('140', $html);
        // La fiche appartient à l'athlète : aucun lien d'édition côté coach.
        self::assertStringNotContainsString('/profile/edit', $html);
    }

    /**
     * #78 (C-12) : la fiche ne compte que les exercices de l'athlète. La
     * bibliothèque globale, commune à tous, se lisait comme la sienne
     * (« 323 exercices »).
     */
    public function testAthleteSheetCountsOnlyTheAthletesOwnExercises(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createExercise(null, 'Squat');
        $this->createExercise(null, 'Développé couché');
        $this->createExercise($athlete, 'Squat pause');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        $metrics = $crawler->filter('.kd-metric')->each(fn ($m) => preg_replace('/\s+/', ' ', trim($m->text())));
        self::assertContains('1 Exercices perso', $metrics);
    }

    /**
     * #78 (C-12) : « Depuis le début » s'arrête à aujourd'hui. Ses séances
     * encore « prévues » sont passées sans statut : elles se disent « à
     * cocher », et les séances à venir n'y entrent pas (elles sont dans « Ce
     * mois »). On lisait « 0 prévue » à côté d'un mois qui en annonçait six.
     */
    public function testAllTimeBlockSaysPastPlannedSessionsAreToBeChecked(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createScheduled($athlete, 'Oubliée', $this->daysAgo(3), ScheduledStatus::PLANNED);
        $this->createScheduled($athlete, 'Faite', $this->daysAgo(2), ScheduledStatus::DONE);
        $this->createScheduled($athlete, 'À venir', (new \DateTimeImmutable('+40 days'))->format('Y-m-d'), ScheduledStatus::PLANNED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        $allTime = $crawler->filter('.kd-card')->reduce(fn ($c) => str_contains($c->filter('h3')->count() ? $c->filter('h3')->text() : '', 'Depuis le début'));
        self::assertCount(1, $allTime);
        $text = $allTime->text();
        self::assertStringContainsString("Jusqu'à aujourd'hui", $text);
        self::assertStringContainsString('1 à cocher', $text);
        self::assertStringContainsString('1 faite', $text);
        self::assertStringNotContainsString('prévue', $text);
    }

    /**
     * GoalVoter accorde l'accès au coach accepté, comme les autres voters : sans
     * cette branche, cliquer un objectif depuis la page athlète donnait un 403.
     */
    public function testAcceptedCoachCanViewAthleteGoal(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $goal = (new Goal())
            ->setOwner($athlete)
            ->setTitle('Trail 42k')
            ->setTargetDate(new \DateTimeImmutable('+10 weeks'));
        $this->em->persist($goal);
        $this->em->flush();

        $this->client->loginUser($coach);
        $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertResponseIsSuccessful();

        // Un tiers sans relation reste refusé.
        $this->client->loginUser($this->createUser('stranger@example.com'));
        $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testCoachCreatesWorkoutOwnedByAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/workout/new"]')->form();
        $form['title'] = 'Sortie longue Z2';
        $this->client->submit($form);

        self::assertResponseRedirects();

        $workout = $this->em->getRepository(Workout::class)->findOneBy(['title' => 'Sortie longue Z2']);
        self::assertNotNull($workout);
        self::assertSame($athlete->getId(), $workout->getOwner()?->getId());
        // Redirection vers le compositeur normal : pas d'éditeur dédié au coach.
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit');
    }

    public function testCoachCreatesPlanOwnedByAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/plan/new"]')->form();
        $form['title'] = 'Prépa semi';
        $form['durationWeeks'] = '6';
        $this->client->submit($form);

        $plan = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Prépa semi']);
        self::assertNotNull($plan);
        self::assertSame($athlete->getId(), $plan->getOwner()?->getId());
        self::assertSame(6, $plan->getDurationWeeks());
    }

    /**
     * #58 : le coach donne une de SES séances à son athlète. La copie appartient
     * à l'athlète, l'original reste au coach, et le compositeur s'ouvre avec la
     * fiche athlète comme retour.
     */
    public function testCoachCopiesOwnWorkoutIntoAthleteLibrary(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $source = $this->createWorkout($coach, 'Upper A');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/workout/copy"]')->form();
        $form['workout'] = (string) $source->getId();
        $this->client->submit($form);

        $copies = $this->em->getRepository(Workout::class)->findBy(['owner' => $athlete]);
        self::assertCount(1, $copies);
        self::assertSame('Upper A', $copies[0]->getTitle());
        self::assertResponseRedirects('/workout/'.$copies[0]->getId().'/edit?from=athlete-'.$athlete->getId());
        // L'original reste au coach, intact.
        self::assertSame($coach->getId(), $this->mustFind(Workout::class, $source->getId())->getOwner()?->getId());
    }

    /**
     * Seule une séance de SA bibliothèque se donne : ni celle de l'athlète (qui
     * l'a déjà), ni celle d'un tiers, que le coach ne peut même pas lire.
     */
    public function testCoachCannotCopyAWorkoutHeDoesNotOwn(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($coach, 'Upper A');
        $foreign = $this->createWorkout($stranger, 'Séance d\'un tiers');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $token = $crawler->filter('form[action$="/workout/copy"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/workout/copy', [
            '_token' => $token,
            'workout' => (string) $foreign->getId(),
        ]);

        self::assertResponseRedirects('/coach/athlete/'.$athlete->getId());
        self::assertCount(0, $this->em->getRepository(Workout::class)->findBy(['owner' => $athlete]));
    }

    public function testCopyToAthleteIsDeniedWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);
        $source = $this->createWorkout($coach, 'Upper A');

        $this->client->loginUser($coach);
        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/workout/copy', [
            'workout' => (string) $source->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(Workout::class)->findBy(['owner' => $athlete]));
    }

    public function testCoachSchedulesWorkoutOnAthleteCalendar(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Seuil 3x8');

        $date = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/schedule"]')->form();
        $form['workoutId'] = (string) $workout->getId();
        $form['date'] = $date;
        $this->client->submit($form);

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        // La séance datée appartient à l'athlète : elle apparaît sur SON calendrier.
        self::assertSame($athlete->getId(), $scheduled[0]->getOwner()?->getId());
        self::assertSame($date, $scheduled[0]->getScheduledDate()->format('Y-m-d'));
    }

    /** Un coach ne pose pas sa propre séance sur le calendrier d'un athlète. */
    public function testCoachCannotScheduleWorkoutHeOwns(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Séance de l\'athlète');
        $coachWorkout = $this->createWorkout($coach, 'Ma séance perso');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $token = $crawler->filter('form[action$="/schedule"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/schedule', [
            '_token' => $token,
            'workoutId' => (string) $coachWorkout->getId(),
            'date' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
        ]);

        self::assertCount(0, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    public function testCoachInstantiatesPlanOnAthleteCalendar(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $workout = $this->createWorkout($athlete, 'Séance du plan');
        $plan = $this->createPlanTemplate($athlete, 'Bloc 2 semaines', 2);
        $this->createPlanItem($plan, $workout, 1, 1);

        // Lundi ISO : la trame projette semaine 1 / jour 1 sur cette date.
        $monday = new \DateTimeImmutable('monday next week');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/instantiate"]')->form();
        $form['planId'] = (string) $plan->getId();
        $form['startDate'] = $monday->format('Y-m-d');
        $this->client->submit($form);

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        self::assertSame($athlete->getId(), $scheduled[0]->getOwner()?->getId());
        self::assertSame($monday->format('Y-m-d'), $scheduled[0]->getScheduledDate()->format('Y-m-d'));

        // #68 : le verbe simple, et l'aperçu sait désormais que le plan est placé.
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Plan « Bloc 2 semaines » placé dans l\'agenda de');
        $plans = json_decode((string) $crawler->filter('form[action$="/instantiate"]')->attr('data-plan-preview-plans-value'), true);
        self::assertTrue($plans[$plan->getId()]['placed']);
    }

    /**
     * #68 : la page du plan mène à l'agenda de son propriétaire. L'athlète part
     * vers son calendrier, le plan présélectionné ; son coach vers la fiche de
     * l'athlète, où le formulaire de pose a déjà ce plan choisi.
     */
    public function testPlanPageLeadsToTheOwnersAgenda(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createPlanTemplate($athlete, 'A Bloc 1', 2);
        $plan = $this->createPlanTemplate($athlete, 'B Bloc 2', 2);
        $id = (int) $plan->getId();

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/plan-template/'.$id);
        self::assertSame('/calendar?place='.$id, $crawler->selectLink('Placer dans mon agenda')->attr('href'));
        self::assertCount(0, $crawler->selectLink('Placer dans son agenda'));

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$id);
        $href = (string) $crawler->selectLink('Placer dans son agenda')->attr('href');
        self::assertSame(sprintf('/coach/athlete/%d?plan=%d#placer-un-plan', $athlete->getId(), $id), $href);

        $crawler = $this->client->request('GET', strtok($href, '#'));
        self::assertSame((string) $id, $crawler->filter('#coach_instantiate_plan option[selected]')->attr('value'));
    }

    // ------------------------------------------------- extension des voters

    public function testAcceptedCoachCanViewAndEditAthleteWorkout(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Séance suivie');

        $this->client->loginUser($coach);

        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        self::assertResponseIsSuccessful();
    }

    public function testNonAcceptedCoachIsForbiddenOnAthleteWorkout(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);
        $workout = $this->createWorkout($athlete, 'Séance privée');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/workout/'.$workout->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /** Fin de relation = fin des droits, sans toucher au contenu de l'athlète. */
    public function testEndedRelationRevokesAccess(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ENDED);
        $workout = $this->createWorkout($athlete, 'Séance conservée');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertResponseStatusCodeSame(403);

        // Le contenu existe toujours et reste à l'athlète.
        self::assertNotNull($this->em->getRepository(Workout::class)->find($workout->getId()));
    }

    public function testAcceptedCoachCanEditAthletePlan(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $plan = $this->createPlanTemplate($athlete, 'Plan suivi', 4);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');

        self::assertResponseIsSuccessful();
    }

    /**
     * L'éditeur de trame se scope sur le PROPRIÉTAIRE DU PLAN, pas sur
     * l'utilisateur courant : sans ça, le coach composant le plan de son athlète
     * n'avait dans la palette que sa propre bibliothèque — donc ni les séances de
     * l'athlète, ni celles qu'il avait lui-même créées pour lui (elles appartiennent
     * à l'athlète).
     *
     * #58 : le coach y voit AUSSI ses propres séances, marquées « à moi » (la pose
     * les copiera chez l'athlète). La palette reste celle du propriétaire du plan,
     * élargie pour ce seul coach.
     */
    public function testPlanEditorPaletteOffersTheCoachHisOwnWorkoutsToo(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné athlète');
        $mine = $this->createWorkout($coach, 'Ma séance perso');
        $plan = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');

        self::assertResponseIsSuccessful();
        $palette = $crawler->filter('.kd-palette')->html();
        self::assertStringContainsString('Fractionné athlète', $palette);
        self::assertStringContainsString('Ma séance perso', $palette);
        self::assertSame('mine', $crawler->filter('.kd-palettecard[data-workout-id="'.$mine->getId().'"]')->attr('data-owner'));
        self::assertSelectorExists('[data-owner-pill][data-owner="mine"]');
    }

    /** La relation est dirigée : l'athlète, dans son propre plan, ne voit que ses séances. */
    public function testAthletePaletteNeverShowsCoachWorkouts(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné athlète');
        $this->createWorkout($coach, 'Séance du coach');
        $plan = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');

        $palette = $crawler->filter('.kd-palette')->html();
        self::assertStringContainsString('Fractionné athlète', $palette);
        self::assertStringNotContainsString('Séance du coach', $palette);
        self::assertSelectorNotExists('[data-owner-pill]');
    }

    /**
     * Corollaire de la palette : la pose accepte une séance de l'athlète, et la
     * copie locale forkée lui appartient (sinon la séance datée du calendrier
     * pointerait un contenu du coach, invisible chez l'athlète).
     */
    public function testCoachPlacesAthleteWorkoutAndForkStaysWithAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Sortie longue');
        $plan = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        $token = $crawler->filter('.kd-palette')->attr('data-place-token');

        $this->client->request('POST', '/plan-template/'.$plan->getId().'/place', [
            '_token' => $token,
            'workoutId' => (string) $workout->getId(),
            'week' => '1',
            'day' => '3',
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(PlanTemplate::class)->find($plan->getId());
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getPlanItems());

        $item = $reloaded->getPlanItems()->first();
        self::assertNotFalse($item);
        $fork = $item->getWorkout();
        self::assertNotNull($fork);
        self::assertTrue($fork->isPlanLocal());
        self::assertSame($athlete->getId(), $fork->getOwner()?->getId());
    }

    /** La relation est dirigée : l'athlète n'hérite pas des droits sur son coach. */
    public function testAthleteHasNoAccessToCoachContent(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $coachWorkout = $this->createWorkout($coach, 'Séance du coach');

        $this->client->loginUser($athlete);
        $this->client->request('GET', '/workout/'.$coachWorkout->getId());

        self::assertResponseStatusCodeSame(403);
    }

    // ------------------------------------------- bibliothèque d'exercices

    /**
     * Le compositeur croise deux bibliothèques d'exercices quand un coach compose
     * la séance d'un athlète : la sienne et celle de l'athlète. Avant, il n'avait
     * que la sienne — donc pas les variantes que l'athlète s'était créées.
     */
    public function testComposerCrossesCoachAndAthleteExerciseLibraries(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createExercise($coach, 'Squat bulgare barre');
        $this->createExercise($athlete, 'Tirage horizontal poulie');
        $this->createExercise(null, 'Développé couché');
        $this->createExercise($this->createUser('stranger@example.com'), 'Exo dun tiers');
        $workout = $this->createWorkout($athlete, 'Séance suivie');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Squat bulgare barre', $html);
        self::assertStringContainsString('Tirage horizontal poulie', $html);
        self::assertStringContainsString('Développé couché', $html);
        self::assertStringNotContainsString('Exo dun tiers', $html);
    }

    /**
     * VIEW traverse la relation dans les deux sens : le coach pose son propre
     * exercice dans la séance de l'athlète, qui doit pouvoir en ouvrir la fiche.
     * L'édition, elle, reste au propriétaire, et l'exercice n'entre pas dans la
     * bibliothèque de l'athlète.
     */
    public function testAthleteReadsCoachExerciseButCannotEditIt(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $exercise = $this->createExercise($coach, 'Squat bulgare barre');

        $this->client->loginUser($athlete);

        $this->client->request('GET', '/exercise/'.$exercise->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/exercise/'.$exercise->getId().'/edit');
        self::assertResponseStatusCodeSame(403);

        // Lecture par lien, pas appropriation : sa bibliothèque reste la sienne.
        $crawler = $this->client->request('GET', '/exercise');
        self::assertStringNotContainsString('Squat bulgare barre', $crawler->html());
    }

    /** Sans relation acceptée, l'exercice perso d'un tiers reste fermé. */
    public function testExerciseStaysPrivateWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);
        $exercise = $this->createExercise($athlete, 'Gainage latéral');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/exercise/'.$exercise->getId());

        self::assertResponseStatusCodeSame(403);
    }

    // ------------------------------------------- portée des index bibliothèque

    /**
     * Le contenu composé pour un athlète lui appartient : sans élargissement de
     * portée, il n'apparaissait nulle part dans les index du coach. Il y figure
     * désormais, avec le badge de propriétaire et une facette dédiée.
     */
    public function testCoachIndexListsAthleteWorkouts(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné athlète');
        $this->createWorkout($coach, 'Ma séance perso');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Fractionné athlète', $html);
        self::assertStringContainsString('Ma séance perso', $html);
        // Facette de propriétaire, « Moi » active au chargement.
        self::assertSame(
            (string) $coach->getId(),
            $crawler->filter('.kd-libfilter--on[data-facet-group="owner"]')->attr('data-facet-value')
        );
        // Badge sur la carte de l'athlète, absent sur la sienne.
        self::assertCount(1, $crawler->filter('.kd-scope--link'));
    }

    public function testCoachIndexListsAthletePlans(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Prépa athlète', $crawler->html());
        self::assertSame(
            (string) $coach->getId(),
            $crawler->filter('.kd-libfilter--on[data-facet-group="owner"]')->attr('data-facet-value')
        );
    }

    /**
     * #75 : un coach sans séance à lui arrive sur « Moi ». La page prépare un
     * état vide qui le dit et renvoie vers son athlète, distinct de celui de la
     * recherche, et les effectifs des puces sont recalculables côté client.
     */
    public function testCoachIndexOffersOwnerEmptyStateWithJumpToAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné athlète');
        $this->createWorkout($athlete, 'Côtes athlète');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout');

        self::assertResponseIsSuccessful();
        $owner = $crawler->filter('[data-filter-target="empty"][data-empty-reason="owner"]');
        self::assertCount(1, $owner);
        self::assertSame((string) $coach->getId(), $owner->attr('data-empty-self'));
        self::assertStringContainsString('Tu n\'as pas encore de séance à toi.', $owner->text());

        // Un seul lien, vers l'athlète (jamais vers soi), avec son effectif.
        $jump = $owner->filter('[data-filter-target="jump"]');
        self::assertCount(1, $jump);
        self::assertSame((string) $athlete->getId(), $jump->attr('data-filter-value-param'));
        self::assertSame('2', $jump->filter('[data-facet-count]')->text());

        // La recherche garde son propre message, les puces le leur.
        self::assertStringContainsString(
            'ta recherche',
            $crawler->filter('[data-empty-reason="search"]')->text()
        );
        self::assertStringContainsString(
            'ces filtres',
            $crawler->filter('[data-empty-reason="filters"]')->text()
        );
        // Chaque puce expose un effectif que le contrôleur recalcule.
        self::assertCount(
            $crawler->filter('[data-filter-target="facet"]')->count(),
            $crawler->filter('[data-filter-target="facet"] [data-facet-count]')
        );
    }

    public function testCoachPlanIndexOffersOwnerEmptyState(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template');

        $owner = $crawler->filter('[data-empty-reason="owner"]');
        self::assertStringContainsString('Tu n\'as pas encore de plan à toi.', $owner->text());
        self::assertStringContainsString('Ceux de', $owner->filter('[data-filter-target="jump"]')->text());
    }

    /** Sans athlète, pas de portée « Moi », donc pas d'état vide de propriétaire. */
    public function testIndexWithoutAthleteHasNoOwnerEmptyState(): void
    {
        $user = $this->createUser('solo@example.com');
        $this->createWorkout($user, 'Footing');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/workout');

        self::assertCount(0, $crawler->filter('[data-empty-reason="owner"]'));
        self::assertCount(1, $crawler->filter('[data-empty-reason="search"]'));
    }

    /** Une demande non acceptée n'ouvre aucune portée. */
    public function testPendingRelationDoesNotWidenIndex(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);
        $this->createWorkout($athlete, 'Séance privée');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout');

        self::assertStringNotContainsString('Séance privée', $crawler->html());
        // Aucun athlète : le groupe de facette disparaît de la barre de filtres.
        self::assertCount(0, $crawler->filter('[data-facet-group="owner"]'));
    }

    /** La relation reste dirigée : l'athlète ne voit pas la bibliothèque du coach. */
    public function testAthleteIndexDoesNotListCoachWorkouts(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($coach, 'Séance du coach');

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/workout');

        self::assertStringNotContainsString('Séance du coach', $crawler->html());
        self::assertCount(0, $crawler->filter('[data-facet-group="owner"]'));
    }

    /**
     * Le bloc-notes est la seule chose que le coach ne voit pas du contenu de son
     * athlète : il édite la séance, mais le brouillon privé reste hors de sa vue.
     */
    public function testCoachSeesNoPrivateNotesOnAthleteWorkout(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Séance athlète');
        $workout->setNotes('Brouillon perso de l’athlète');
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');

        // Le compositeur s'ouvre (le coach est co-éditeur) mais sans le bloc.
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.kd-notes--owner'));
        self::assertStringNotContainsString('Brouillon perso', $crawler->html());
    }

    /** Le propriétaire, lui, voit son bloc — et sa page n'a pas changé de nature. */
    public function testOwnerSeesPrivateNotesOnOwnWorkout(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $workout = $this->createWorkout($athlete, 'Séance athlète');
        $workout->setNotes('Brouillon perso de l’athlète');
        $this->em->flush();

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');

        self::assertCount(1, $crawler->filter('.kd-notes--owner'));
        self::assertStringContainsString('Brouillon perso', $crawler->html());
    }

    /**
     * Garde serveur : EDIT ne suffit pas pour le champ `notes`. Sans elle, un coach
     * pourrait écrire (donc lire en réponse) le brouillon de son athlète en postant
     * directement l'endpoint, malgré l'absence du bloc à l'écran.
     */
    public function testCoachCannotWriteAthletePrivateNotes(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Séance athlète');
        $template = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($coach);

        // Le jeton CSRF est bien celui de la page : c'est la propriété, pas le
        // jeton, qui doit refuser.
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $this->client->request('POST', '/workout/'.$workout->getId().'/meta', [
            '_token' => $crawler->filter('h1.kd-inlineedit')->attr('data-inline-edit-token-value'),
            'field' => 'notes',
            'value' => 'Note du coach',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $this->client->request('POST', '/plan-template/'.$template->getId().'/meta', [
            '_token' => $crawler->filter('h1.kd-inlineedit')->attr('data-inline-edit-token-value'),
            'field' => 'notes',
            'value' => 'Note du coach',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNull($this->mustFind(Workout::class, $workout->getId())->getNotes());
        self::assertNull($this->mustFind(PlanTemplate::class, $template->getId())->getNotes());
    }

    /** Le propriétaire écrit ses notes par le même endpoint, sur les deux entités. */
    public function testOwnerWritesPrivateNotes(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $workout = $this->createWorkout($athlete, 'Séance athlète');
        $template = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($athlete);

        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $this->client->request('POST', '/workout/'.$workout->getId().'/meta', [
            '_token' => $crawler->filter('.kd-notes--owner .kd-notes__text')->attr('data-inline-edit-token-value'),
            'field' => 'notes',
            'value' => "Semaine 1 : en vrac\nSemaine 2 : à voir",
        ]);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $this->client->request('POST', '/plan-template/'.$template->getId().'/meta', [
            '_token' => $crawler->filter('.kd-notes--owner .kd-notes__text')->attr('data-inline-edit-token-value'),
            'field' => 'notes',
            'value' => 'Déroulé provisoire',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(
            "Semaine 1 : en vrac\nSemaine 2 : à voir",
            $this->mustFind(Workout::class, $workout->getId())->getNotes()
        );
        self::assertSame(
            'Déroulé provisoire',
            $this->mustFind(PlanTemplate::class, $template->getId())->getNotes()
        );
    }

    /**
     * #65 : le coach écrit ses notes sur l'athlète depuis sa fiche. Elles vivent
     * sur la relation, pas sur un contenu de l'athlète.
     */
    public function testCoachWritesPrivateNotesOnAthleteSheet(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        self::assertCount(1, $crawler->filter('.kd-notes--coach'));

        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/notes', [
            '_token' => $crawler->filter('.kd-notes--coach .kd-notes__text')->attr('data-inline-edit-token-value'),
            'field' => 'notes',
            'value' => '  Surveiller le genou gauche  ',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Surveiller le genou gauche', $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame('Surveiller le genou gauche', $this->mustFind(Coaching::class, $relation->getId())->getCoachNotes());
    }

    /**
     * Les notes du coach se rappellent dans les éditeurs du contenu de l'athlète,
     * avec la mention « Visible par » sur les champs que l'athlète lit.
     */
    public function testCoachSeesOwnNotesAndVisibilityHintsInAthleteEditors(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED)->setCoachNotes('Surveiller le genou gauche');
        $workout = $this->createWorkout($athlete, 'Séance athlète');
        $template = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);
        $this->em->flush();

        $this->client->loginUser($coach);

        foreach (['/workout/'.$workout->getId().'/edit', '/plan-template/'.$template->getId().'/edit'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('.kd-notes--coach'), $url);
            self::assertStringContainsString('Surveiller le genou gauche', $crawler->filter('.kd-notes--coach')->text());
            self::assertStringContainsString('Visible par athlete@example.com', $crawler->filter('.kd-visibleto')->text());
        }
    }

    /**
     * L'athlète ne voit jamais les notes de son coach : ni dans ses éditeurs, ni
     * sur sa page de relations, ni par l'endpoint (il n'est pas son propre coach).
     */
    public function testAthleteNeverSeesCoachNotes(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com', ['ROLE_COACH']);
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED)->setCoachNotes('Surveiller le genou gauche');
        $workout = $this->createWorkout($athlete, 'Séance athlète');
        $template = $this->createPlanTemplate($athlete, 'Prépa athlète', 4);
        $this->em->flush();

        $this->client->loginUser($athlete);

        foreach ([
            '/workout/'.$workout->getId().'/edit',
            '/workout/'.$workout->getId(),
            '/plan-template/'.$template->getId().'/edit',
            '/plan-template/'.$template->getId(),
            '/coaching',
        ] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.kd-notes--coach'), $url);
            self::assertCount(0, $crawler->filter('.kd-visibleto'), $url);
            self::assertStringNotContainsString('genou', $crawler->html(), $url);
        }

        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/notes', [
            '_token' => 'peu-importe',
            'field' => 'notes',
            'value' => 'Lu en réponse ?',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /** Un coach dont la relation n'est plus active n'écrit plus rien. */
    public function testEndedCoachCannotWriteNotes(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ENDED);

        $this->client->loginUser($coach);
        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/notes', [
            '_token' => 'peu-importe',
            'field' => 'notes',
            'value' => 'Note',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $roles */
    // --- KL-45 : la lecture du réalisé par le coach --------------------------

    /**
     * La fiche athlète montre ses séances consignées avec leurs chiffres, et le
     * lien mène à la séance datée — que le coach a le droit d'ouvrir (VIEW),
     * pas d'effacer (LOG).
     */
    public function testAthletePageShowsLoggedSessions(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $exercise = $this->createExercise($athlete, 'Squat');
        $logged = $this->logSession($athlete, $exercise, $this->daysAgo(5), [[SetType::WARMUP, 10, 40.0], [SetType::NORMAL, 5, 100.0], [SetType::NORMAL, 5, 100.0]]);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        // Le lien porte le contexte de retour : d'ici, la séance datée revient à
        // la fiche de l'athlète, pas au calendrier du coach.
        self::assertSelectorExists('#dernieres-seances a[href="/schedule/'.$logged->getId().'?from=athlete-'.$athlete->getId().'"]');
        // Deux séries de travail, 1000 kg : l'échauffement ne compte pas.
        self::assertSelectorTextContains('#dernieres-seances', '2 séries');
        self::assertSelectorTextContains('#dernieres-seances', '1 000 kg');
    }

    /**
     * #76 (C-10, C-11) : la séance manquée et la séance faite sans détail
     * apparaissent sur la fiche, chacune avec son statut, et s'ouvrent. Seul le
     * réalisé consigné y figurait : « 1 manquée » et « 1 faite » se lisaient dans
     * les compteurs sans qu'on puisse les retrouver.
     */
    public function testAthletePageListsPastSessionsDoneOrMissed(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $missed = $this->createScheduled($athlete, 'Fractionné 6 x 400 m', $this->daysAgo(2), ScheduledStatus::MISSED);
        $bare = $this->createScheduled($athlete, 'Haut du corps', $this->daysAgo(4), ScheduledStatus::DONE);
        $unchecked = $this->createScheduled($athlete, 'Mobilité', $this->daysAgo(6), ScheduledStatus::PLANNED);
        // Faite avec une sortie de la montre : il y a quelque chose à lire.
        $imported = $this->createScheduled($athlete, 'Footing', $this->daysAgo(8), ScheduledStatus::DONE);
        $day = new \DateTimeImmutable($this->daysAgo(8));
        $this->em->persist((new ImportedActivity($athlete, ActivitySource::INTERVALS, 'r1', 'Run', $day->setTime(7, 0), $day))
            ->setActivity(ActivityType::RUNNING)
            ->setScheduledWorkout($imported));
        // Hors de la fenêtre de quatre semaines : c'est le journal qui la garde.
        $old = $this->createScheduled($athlete, 'Vieille séance', $this->daysAgo(40), ScheduledStatus::MISSED);
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        $from = '?from=athlete-'.$athlete->getId();
        $line = static fn (ScheduledWorkout $s): string => '#dernieres-seances a[href="/schedule/'.$s->getId().$from.'"]';

        self::assertSelectorTextContains($line($missed), 'Fractionné 6 x 400 m');
        self::assertSelectorTextContains($line($missed), 'Manquée');
        self::assertSelectorTextContains($line($bare), 'Faite');
        self::assertSelectorTextContains($line($bare), 'sans détail');
        self::assertSelectorTextContains($line($unchecked), 'pas cochée');
        self::assertStringNotContainsString('sans détail', $crawler->filter($line($imported))->text());
        self::assertSelectorNotExists('#dernieres-seances a[href^="/schedule/'.$old->getId().'"]');

        // La plus récente d'abord, comme le journal.
        $titles = $crawler->filter('#dernieres-seances .kd-logline__title')->each(static fn ($n): string => $n->text());
        self::assertSame(['Fractionné 6 x 400 m', 'Haut du corps', 'Mobilité', 'Footing'], $titles);
    }

    /**
     * Une séance d'aujourd'hui passe de l'agenda aux dernières séances dès
     * qu'elle est cochée : jamais dans les deux listes à la fois.
     */
    public function testTodaysSessionMovesToRecentOnceChecked(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $done = $this->createScheduled($athlete, 'Faite ce matin', $this->daysAgo(0), ScheduledStatus::DONE);
        $this->createScheduled($athlete, 'Prévue ce soir', $this->daysAgo(0), ScheduledStatus::PLANNED);
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dernieres-seances a[href^="/schedule/'.$done->getId().'"]');
        $recent = $crawler->filter('#dernieres-seances')->text();
        self::assertStringContainsString('Faite ce matin', $recent);
        self::assertStringNotContainsString('Prévue ce soir', $recent);
        $agenda = $crawler->filter('.kd-coachitem__name')->each(static fn ($n): string => $n->text());
        self::assertContains('Prévue ce soir', $agenda);
        self::assertNotContains('Faite ce matin', $agenda);
    }

    /** Rien d'échu sur la fenêtre : la section le dit au lieu de disparaître. */
    public function testAthletePageSaysWhenNothingIsRecent(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dernieres-seances', 'Aucune séance datée sur ses 4 dernières semaines.');
    }

    /**
     * Le journal complet : la fiche ne montre que quatre semaines, il fallait un
     * endroit où lire la suite. La fenêtre s'y choisit comme sur les statistiques.
     */
    public function testAthleteLogPageListsTheWholeWindow(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $exercise = $this->createExercise($athlete, 'Squat');
        $recent = $this->logSession($athlete, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 100.0]]);
        // Hors des quatre dernières semaines : visible seulement en « tout ».
        $old = $this->logSession($athlete, $exercise, '2024-01-15', [[SetType::NORMAL, 5, 80.0]]);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/log?range=all');

        self::assertResponseIsSuccessful();
        // Chaque ligne revient au journal, pas au calendrier du coach.
        self::assertSelectorExists('a[href="/schedule/'.$recent->getId().'?from=athlete-log-'.$athlete->getId().'"]');
        self::assertSelectorExists('a[href="/schedule/'.$old->getId().'?from=athlete-log-'.$athlete->getId().'"]');
        // Le sélecteur de fenêtre reste sur le journal : il change de fenêtre,
        // pas de page.
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/log?range=6m"]');

        // Une fenêtre courte ne montre que ce qui s'y trouve, sans rien perdre :
        // le total dit ce qu'il y a derrière.
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/log?range=4w');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="/schedule/'.$old->getId().'"]');
    }

    /**
     * Le retour posé sur les lignes du réalisé se résout — et seulement pour un
     * coach accepté. L'identifiant de l'athlète est une donnée privée : un jeton
     * `athlete-<id>` deviné dans la query ne doit rien nommer, et se lire comme
     * une absence (la page retombe sur son retour d'origine, le calendrier).
     */
    public function testTheAthleteBacklinkIsResolvedOnlyForAnAcceptedCoach(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $exercise = $this->createExercise($athlete, 'Squat');
        $logged = $this->logSession($athlete, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 100.0]]);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/schedule/'.$logged->getId().'?from=athlete-'.$athlete->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/coach/athlete/'.$athlete->getId().'"]')->count());

        // Un tiers curieux, sur SA propre séance, avec le jeton d'un autre : il
        // n'apprend rien et son retour retombe sur le calendrier.
        $stranger = $this->createUser('curieux@example.com');
        $own = $this->logSession($stranger, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 60.0]]);

        $this->client->loginUser($stranger);
        $crawler = $this->client->request('GET', '/schedule/'.$own->getId().'?from=athlete-'.$athlete->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('athlete@example.com', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('a[href="/coach/athlete/'.$athlete->getId().'"]'));
    }

    // ------------------------------------------- bandeau propriétaire (#64)

    /**
     * Le contenu de l'athlète s'ouvre chez son coach avec la mise en page du
     * sien : sans bandeau, rien ne disait à qui il était, et le retour par
     * défaut (« Mes séances », « Mes plans », « Calendrier ») ramenait le coach
     * sur ses propres pages, vides. Sur les cinq pages, le bandeau nomme
     * l'athlète par son pseudo et le retour vise sa fiche, sans `?from=`.
     */
    public function testAthleteContentNamesItsOwnerAndLeadsBackToTheSheet(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setUsername('Trailer38');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $workout = $this->createWorkout($athlete, 'Séance suivie');
        $plan = $this->createPlanTemplate($athlete, 'Plan suivi', 4);
        $scheduled = $this->logSession($athlete, $this->createExercise(null, 'Squat'), '2026-03-08', [
            [SetType::NORMAL, 5, 100.0],
        ]);

        $sheet = '/coach/athlete/'.$athlete->getId();
        $pages = [
            '/workout/'.$workout->getId() => ['Séance', 'a.kd-wk__back'],
            '/workout/'.$workout->getId().'/edit' => ['Séance', 'a.kd-backlink'],
            '/plan-template/'.$plan->getId() => ['Plan', 'a.kd-backlink'],
            '/plan-template/'.$plan->getId().'/edit' => ['Plan', 'a.kd-backlink'],
            '/schedule/'.$scheduled->getId() => ['Séance', 'a.kd-wk__back'],
        ];

        $this->client->loginUser($coach);

        foreach ($pages as $url => [$kind, $back]) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
            $banner = $crawler->filter('.kd-ownerbar');
            self::assertCount(1, $banner, $url);
            self::assertStringContainsString($kind.' de Trailer38', $banner->text(), $url);
            self::assertStringNotContainsString('athlete@example.com', $banner->text(), $url);
            self::assertCount(1, $banner->filter('a[href="'.$sheet.'"]'), $url);
            self::assertCount(1, $crawler->filter($back.'[href="'.$sheet.'"]'), $url);
        }
    }

    /** Chez soi, rien ne change : ni bandeau, ni retour détourné. */
    public function testOwnContentHasNoOwnerBannerAndKeepsItsDefaultBack(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $workout = $this->createWorkout($athlete, 'Ma séance');
        $plan = $this->createPlanTemplate($athlete, 'Mon plan', 4);

        $this->client->loginUser($athlete);

        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        self::assertSelectorNotExists('.kd-ownerbar');
        self::assertSelectorExists('a.kd-backlink[href="/workout"]');

        $this->client->request('GET', '/plan-template/'.$plan->getId());
        self::assertSelectorNotExists('.kd-ownerbar');
        self::assertSelectorExists('a.kd-backlink[href="/plan-template"]');
    }

    /**
     * Le retour vers la fiche n'est qu'un défaut : un contexte porté garde la
     * priorité. Une case ouverte depuis le plan de l'athlète repart au plan.
     */
    public function testACarriedContextStillWinsOverTheSheet(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Séance suivie');
        $plan = $this->createPlanTemplate($athlete, 'Plan suivi', 4);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/workout/'.$workout->getId().'/edit?from=plan-edit-'.$plan->getId());

        self::assertSelectorExists('a.kd-backlink[href="/plan-template/'.$plan->getId().'/edit"]');
        self::assertSelectorExists('.kd-ownerbar');
    }

    /**
     * Tous les liens qui sortent de la fiche vers un contenu de l'athlète posent
     * leur ancre, y compris par le détour de l'historique (C-13).
     */
    public function testLinksLeavingTheSheetCarryTheAthleteAnchor(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Séance suivie');
        $plan = $this->createPlanTemplate($athlete, 'Plan suivi', 4);
        $scheduled = $this->logSession($athlete, $this->createExercise(null, 'Squat'), (new \DateTimeImmutable('-3 days'))->format('Y-m-d'), [
            [SetType::NORMAL, 5, 100.0],
        ]);
        $from = '?from=athlete-'.$athlete->getId();

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertSelectorExists('a[href="/workout/'.$workout->getId().$from.'"]');
        self::assertSelectorExists('a[href="/workout/'.$workout->getId().'/edit'.$from.'"]');
        self::assertSelectorExists('a[href="/plan-template/'.$plan->getId().$from.'"]');
        self::assertSelectorExists('a[href="/plan-template/'.$plan->getId().'/edit'.$from.'"]');

        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/history');
        self::assertSelectorExists('a[href="/schedule/'.$scheduled->getId().$from.'"]');

        // Pour soi, l'historique ne pose aucun jeton : le retour d'origine de la
        // séance datée (le calendrier) est le bon.
        $this->client->loginUser($athlete);
        $this->client->request('GET', '/profile/history');
        self::assertSelectorExists('a[href="/schedule/'.$scheduled->getId().'"]');
    }

    // ------------------------------------------------ agenda de l'athlète (#77)

    /**
     * « Ses 8 prochaines semaines » n'était que du texte (C-14). Le titre ouvre
     * la séance datée avec le retour vers la fiche, la date ouvre sa semaine
     * dans l'agenda de l'athlète, et un bouton mène à l'agenda.
     */
    public function testUpcomingRowsOpenTheSessionAndItsWeek(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $date = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
        $scheduled = $this->createScheduled($athlete, 'Fractionné 6 x 400 m', $date, ScheduledStatus::PLANNED);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertSelectorExists('a[href="/schedule/'.$scheduled->getId().'?from=athlete-'.$athlete->getId().'"]');
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/calendar/'.$date.'"]');
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/calendar"]');
    }

    /**
     * L'agenda de l'athlète est la vue semaine du calendrier, au nom de
     * l'athlète : ses séances et pas celles du coach, les gestes du calendrier
     * (statut, déplacer, retirer), et rien de ce qui parle du calendrier du
     * lecteur (ajout depuis sa bibliothèque, export, abonnement). Chaque lien
     * sortant porte le retour vers cette semaine de cet agenda.
     */
    public function testAthleteCalendarShowsHisWeekWithTheCalendarGestures(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setUsername('Trailer38');
        $this->em->flush();
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $scheduled = $this->createScheduled($athlete, 'Sortie longue', '2026-12-03', ScheduledStatus::PLANNED);
        $this->createScheduled($coach, 'Séance du coach', '2026-12-03', ScheduledStatus::PLANNED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/calendar/2026-12-03');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Sortie longue', $html);
        self::assertStringContainsString('Trailer38', $html);
        self::assertStringNotContainsString('Séance du coach', $html);

        // Le lundi de la semaine, comme le jeton du calendrier de soi.
        $from = '?from=athlete-cal-'.$athlete->getId().'-2026-11-30';
        self::assertSelectorExists('a[href="/schedule/'.$scheduled->getId().$from.'"]');
        self::assertSelectorExists('form[action="/schedule/'.$scheduled->getId().'/move"]');
        self::assertSelectorExists('form[action="/schedule/'.$scheduled->getId().'/delete"]');
        self::assertSelectorExists('form[action="/schedule/'.$scheduled->getId().'/cycle-status"] input[name="from"][value="athlete-cal-'.$athlete->getId().'-2026-11-30"]');

        // La navigation reste dans son agenda.
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/calendar/2026-11-23"]');
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/calendar/2026-12-07"]');
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/history"]');

        // Ce qui parle du calendrier du lecteur n'y est pas (le menu principal
        // garde son entrée « Calendrier », hors de la page).
        self::assertSelectorNotExists('[data-action="caladd#openWorkout"]');
        self::assertSelectorNotExists('.kd-pagehead a[href^="/calendar"]');
        self::assertSelectorNotExists('.kd-week a[href^="/calendar"]');
        self::assertSelectorNotExists('a[href^="/export"]');
        // Le ressenti se consigne : propriétaire seul.
        self::assertSelectorNotExists('.kd-modal__rating');
    }

    /** Comme la fiche : ROLE_COACH ne suffit pas, il faut la relation acceptée. */
    public function testAthleteCalendarIsDeniedWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/calendar');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Déplacer ou retirer une séance de l'athlète ramène le coach dans l'agenda
     * de l'athlète, sur la semaine de la séance — et non sur SON calendrier.
     */
    public function testCoachGesturesReturnToTheAthleteCalendar(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $scheduled = $this->createScheduled($athlete, 'Sortie longue', '2026-12-03', ScheduledStatus::PLANNED);
        $id = (int) $scheduled->getId();
        $calendar = '/coach/athlete/'.$athlete->getId().'/calendar/';

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', $calendar.'2026-12-03');
        $form = $crawler->filter('form[action="/schedule/'.$id.'/move"]')->form();
        $form['scheduledDate'] = '2026-12-10';
        $this->client->submit($form);

        self::assertResponseRedirects($calendar.'2026-12-10');
        $this->em->clear();
        self::assertSame('2026-12-10', $this->mustFind(ScheduledWorkout::class, $id)->getScheduledDate()->format('Y-m-d'));

        $crawler = $this->client->request('GET', $calendar.'2026-12-10');
        $this->client->submit($crawler->filter('form[action="/schedule/'.$id.'/delete"]')->form());

        self::assertResponseRedirects($calendar.'2026-12-10');
        $this->em->clear();
        self::assertNull($this->em->getRepository(ScheduledWorkout::class)->find($id));
    }

    /**
     * Le jeton `athlete-cal` ramène à la semaine quittée, nommée par le pseudo de
     * l'athlète. Même garde que `athlete-<id>` : un tiers qui le recopie sur sa
     * propre séance n'apprend rien et retombe sur son calendrier.
     */
    public function testTheAthleteCalendarBacklinkIsResolvedOnlyForAnAcceptedCoach(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $scheduled = $this->createScheduled($athlete, 'Sortie longue', '2026-12-03', ScheduledStatus::PLANNED);
        $token = '?from=athlete-cal-'.$athlete->getId().'-2026-11-30';
        $target = 'a[href="/coach/athlete/'.$athlete->getId().'/calendar/2026-11-30"]';

        $this->client->loginUser($coach);
        $this->client->request('GET', '/schedule/'.$scheduled->getId().$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists($target);

        $stranger = $this->createUser('curieux@example.com');
        $own = $this->createScheduled($stranger, 'Ma séance', '2026-12-03', ScheduledStatus::PLANNED);

        $this->client->loginUser($stranger);
        $crawler = $this->client->request('GET', '/schedule/'.$own->getId().$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists($target);
        self::assertStringNotContainsString('athlete@example.com', $crawler->filter('body')->text());
    }

    /** Sans relation acceptée, le journal d'un autre reste fermé. */
    public function testAthleteLogPageIsDeniedWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/log');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Lecture stricte : sur la séance datée de son athlète, le coach n'a aucun
     * moyen d'effacer le réalisé. L'attribut est LOG, pas EDIT — et EDIT, lui, il
     * l'a bien (il programme).
     */
    public function testCoachCannotDeleteAthleteLog(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $exercise = $this->createExercise($athlete, 'Squat');
        $logged = $this->logSession($athlete, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 100.0]]);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/schedule/'.$logged->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-logdel');

        // Le jeton CSRF n'a pas à être valide : la garde LOG passe avant lui, et
        // c'est bien elle qu'on veut voir refuser.
        $this->client->request('POST', '/schedule/'.$logged->getId().'/log/delete', ['_token' => 'peu-importe']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($logged->hasLog());
    }

    /**
     * La trajectoire d'un exercice au nom de l'athlète : c'est SON historique qui
     * s'affiche, sur un exercice de la bibliothèque globale que les deux
     * pratiquent. Sans cette page, le lien du compositeur ramenait le coach sur
     * ses propres chiffres.
     */
    public function testAthleteExercisePageShowsTheAthleteTrajectory(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $exercise = $this->createExercise(null, 'Squat');
        $this->logSession($athlete, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 120.0]]);
        $this->logSession($coach, $exercise, '2026-03-09', [[SetType::NORMAL, 5, 200.0]]);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/exercise/'.$exercise->getId());

        self::assertResponseIsSuccessful();
        $trajectory = $crawler->filter('.kd-extraj')->text();
        self::assertStringContainsString('120 kg', $trajectory);
        self::assertStringNotContainsString('200 kg', $trajectory);
        // La page dit de qui elle parle : un chiffre de performance ne doit pas
        // laisser douter de qui l'a produit.
        self::assertStringContainsString('athlete@example.com', $trajectory);
        // Changer de fenêtre garde la page de l'athlète : un lien vers
        // `/exercise/{id}` ramènerait le coach sur ses propres courbes.
        $athleteUrl = '/coach/athlete/'.$athlete->getId().'/exercise/'.$exercise->getId();
        self::assertSelectorExists('a.kd-rangetab[href="'.$athleteUrl.'?range=all#progression"]');
        self::assertSelectorNotExists('a.kd-rangetab[href^="/exercise/"]');

        // La page « moi » n'a pas bougé pour autant : elle répond toujours de soi.
        $crawler = $this->client->request('GET', '/exercise/'.$exercise->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('200 kg', $crawler->filter('.kd-extraj')->text());
    }

    /** Sans relation acceptée, l'historique d'un autre reste fermé. */
    public function testAthleteExercisePageIsDeniedWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);

        $exercise = $this->createExercise(null, 'Squat');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/exercise/'.$exercise->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Statistiques et historique de l'athlète : mêmes pages que les siennes,
     * servies au nom de quelqu'un d'autre. On vérifie que les chiffres sont ceux
     * de l'athlète et que la navigation interne (fenêtre de temps) ne renvoie pas
     * le coach sur ses propres statistiques.
     */
    public function testAthleteStatsAndHistoryReadTheAthleteData(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $exercise = $this->createExercise(null, 'Squat');
        $this->logSession($athlete, $exercise, (new \DateTimeImmutable('-3 days'))->format('Y-m-d'), [
            [SetType::NORMAL, 10, 100.0],
        ]);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/stats');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'athlete@example.com');
        // 10 × 100 kg de tonnage, chez l'athlète : le coach n'a rien logué.
        $headline = implode(' ', $crawler->filter('.kd-metric')->each(static fn ($node): string => $node->text()));
        self::assertStringContainsString('1 000', $headline);
        // Le sélecteur de fenêtre reste sur l'athlète.
        self::assertSelectorExists('a[href^="/coach/athlete/'.$athlete->getId().'/stats?range="]');
        // Dans le contenu : la barre, elle, porte « Progrès » (#71), les stats du coach.
        self::assertSelectorNotExists('main a[href^="/profile/stats"]');
        // Les rubriques aussi : un onglet ne change pas de sujet en silence.
        self::assertSelectorExists('a.kd-stattab[href="/coach/athlete/'.$athlete->getId().'/stats?range=4w&tab=gym"]');

        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/stats?tab=gym');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.kd-rangetab[href="/coach/athlete/'.$athlete->getId().'/stats?tab=gym&range=6m"]');

        $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/history');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'athlete@example.com');
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/stats"]');

        // Et la fiche athlète mène aux deux.
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/stats"]');
        self::assertSelectorExists('a[href="/coach/athlete/'.$athlete->getId().'/history"]');
    }

    /**
     * L'onglet Endurance vu par le coach : il apprend qu'une sortie de son
     * athlète attend une séance, mais n'est pas invité à la rattacher —
     * rattacher, c'est consigner (`LOG`), et ce droit reste au propriétaire.
     */
    public function testAthleteEnduranceTabTellsTheCoachWithoutAnInvitationToAttach(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $day = new \DateTimeImmutable('-2 days');
        $this->em->persist((new ImportedActivity($athlete, ActivitySource::INTERVALS, 'c1', 'Run', $day->setTime(7, 0), $day))
            ->setActivity(ActivityType::RUNNING)
            ->setDistanceMeters(8000)
            ->setMovingSeconds(2400));
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/stats?tab=endurance');

        self::assertResponseIsSuccessful();
        $coverage = $crawler->filter('.kd-endu__coverage')->text();
        self::assertStringContainsString('1 activité importée sans séance', $coverage);
        self::assertStringNotContainsString('rattache', $coverage);
    }

    /**
     * Une séance datée qui porte le réalisé d'un seul exercice.
     *
     * @param list<array{0: SetType, 1: int|null, 2: float|null}> $sets
     */
    private function logSession(User $owner, Exercise $exercise, string $date, array $sets): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);

        $loggedExercise = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition(1);

        $position = 0;
        foreach ($sets as $set) {
            $loggedExercise->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2])
            );
        }

        $scheduled->addLoggedExercise($loggedExercise);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /** Une séance datée sans réalisé, titrée en propre. */
    private function createScheduled(User $owner, string $title, string $date, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createCoaching(User $coach, User $athlete, CoachingStatus $status): Coaching
    {
        $coaching = (new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus($status)
            ->setRequestedBy($coach);

        if (CoachingStatus::PENDING !== $status) {
            $coaching->setRespondedAt(new \DateTimeImmutable());
        }

        $this->em->persist($coaching);
        $this->em->flush();

        return $coaching;
    }

    private function createWorkout(User $owner, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace([' ', '\''], '-', $title)));

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    /** @param User|null $owner null = bibliothèque globale de l'app */
    private function createExercise(?User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    private function createPlanTemplate(User $owner, string $title, int $weeks): PlanTemplate
    {
        $template = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDurationWeeks($weeks)
            ->setSlug(strtolower(str_replace(' ', '-', $title)));

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    private function createPlanItem(PlanTemplate $template, Workout $workout, int $week, int $day): PlanItem
    {
        $item = (new PlanItem())
            ->setPlanTemplate($template)
            ->setWorkout($workout)
            ->setWeekNumber($week)
            ->setDayOfWeek($day);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
