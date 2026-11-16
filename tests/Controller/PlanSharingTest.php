<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
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
use App\Service\ShareLink;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #58, côté plans : la palette croisée du coach, le plan donné à un athlète
 * depuis l'éditeur, et la copie d'un plan (lien public, fiche coach).
 *
 * Fil rouge de tous ces tests : **l'athlète ne voit jamais une séance qui n'est
 * pas à lui**. Ce qu'un coach pose, donne ou copie arrive dans SA bibliothèque,
 * et les cases de ses plans y prennent leur racine — c'est ce qui fait vivre la
 * lignée (« d'après le dernier réalisé », ressenti), scopée sur le propriétaire.
 */
final class PlanSharingTest extends WebTestCase
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

    // ---- Palette croisée : poser une séance du coach -------------------------

    public function testPlacingACoachWorkoutCopiesItIntoTheAthleteLibraryOnce(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $mine = $this->workout($coach, 'Upper A');
        $plan = $this->plan($athlete, 'Prépa');

        $this->client->loginUser($coach);
        $this->place($plan, $mine, 1, 1);
        $this->place($plan, $mine, 2, 1);

        $this->em->clear();
        $copies = $this->em->getRepository(Workout::class)->findBy(['owner' => $athlete, 'planLocal' => false]);
        self::assertCount(1, $copies, 'Une seule copie en bibliothèque, quelle que soit le nombre de poses.');
        self::assertSame($mine->getId(), $copies[0]->getCopiedFrom()?->getId());

        $cells = $this->mustFind(PlanTemplate::class, $plan->getId())->getPlanItems();
        self::assertCount(2, $cells);
        foreach ($cells as $item) {
            $cell = $item->getWorkout();
            self::assertNotNull($cell);
            self::assertTrue($cell->isPlanLocal());
            self::assertSame($athlete->getId(), $cell->getOwner()?->getId());
            // La lignée vit chez l'athlète, pas chez le coach.
            self::assertSame($copies[0]->getId(), $cell->getOrigin()?->getId());
        }

        // La séance du coach a sa copie chez l'athlète : elle quitte la palette,
        // la copie y est déjà.
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        self::assertCount(0, $crawler->filter('.kd-palettecard[data-workout-id="'.$mine->getId().'"]'));
        self::assertCount(1, $crawler->filter('.kd-palettecard[data-workout-id="'.$copies[0]->getId().'"]'));
    }

    public function testPlacingAStrangerWorkoutIsRefused(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $stranger = $this->user('stranger@example.com');
        $foreign = $this->workout($stranger, 'Séance d\'un tiers');
        $plan = $this->plan($athlete, 'Prépa');

        $this->client->loginUser($coach);
        $this->place($plan, $foreign, 1, 1);

        $this->em->clear();
        self::assertCount(0, $this->mustFind(PlanTemplate::class, $plan->getId())->getPlanItems());
        self::assertCount(0, $this->em->getRepository(Workout::class)->findBy(['owner' => $athlete]));
    }

    // ---- « Donner ce plan à » : transfert depuis l'éditeur -------------------

    public function testOwnerGivesHisPlanToHisAthlete(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $mine = $this->workout($coach, 'Upper A');
        $plan = $this->plan($coach, 'Bloc force');
        $plan->setNotes('Brouillon du coach');
        $cell = $this->placeDirectly($plan, $mine, 1, 1);
        $cell->setNotes('Note privée de case');
        $goal = (new Goal())->setOwner($coach)->setTitle('Semi')->setTargetDate(new \DateTimeImmutable('+2 months'));
        $this->em->persist($goal);
        $plan->addGoal($goal);
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        // Aucun athlète présélectionné : le bandeau propose une action, il ne
        // laisse pas croire que le plan est déjà à quelqu'un.
        self::assertSame('', $crawler->filter('#plan_assign_athlete option[selected]')->attr('value'));
        $form = $crawler->filter('form[action$="/assign"]')->form();
        $form['athlete'] = (string) $athlete->getId();
        $this->client->submit($form);

        self::assertResponseRedirects('/plan-template/'.$plan->getId().'/edit');

        $this->em->clear();
        $plan = $this->mustFind(PlanTemplate::class, $plan->getId());
        self::assertSame($athlete->getId(), $plan->getOwner()?->getId());
        self::assertNull($plan->getNotes(), 'Le bloc-notes privé du coach ne passe pas.');
        self::assertCount(0, $plan->getGoals(), 'Les objectifs sont ceux du coach.');

        $moved = $plan->getPlanItems()->first();
        self::assertInstanceOf(PlanItem::class, $moved);
        $movedCell = $moved->getWorkout();
        self::assertNotNull($movedCell);
        self::assertSame($athlete->getId(), $movedCell->getOwner()?->getId());
        self::assertNull($movedCell->getNotes());

        // Racine re-posée dans la bibliothèque de l'athlète ; l'original reste au coach.
        $root = $movedCell->getOrigin();
        self::assertNotNull($root);
        self::assertSame($athlete->getId(), $root->getOwner()?->getId());
        self::assertSame($mine->getId(), $root->getCopiedFrom()?->getId());
        self::assertSame($coach->getId(), $this->mustFind(Workout::class, $mine->getId())->getOwner()?->getId());
    }

    /** Le coach co-édite le plan de son athlète, il ne le réattribue pas. */
    public function testOnlyTheOwnerCanGiveThePlan(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $other = $this->user('other@example.com');
        $this->coaching($coach, $other);
        $plan = $this->plan($athlete, 'Prépa');

        $this->client->loginUser($coach);
        $this->client->request('POST', '/plan-template/'.$plan->getId().'/assign', [
            'athlete' => (string) $other->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame($athlete->getId(), $this->mustFind(PlanTemplate::class, $plan->getId())->getOwner()?->getId());
    }

    public function testThePlanGoesOnlyToAnAcceptedAthlete(): void
    {
        [$coach] = $this->coachAndAthlete();
        $stranger = $this->user('stranger@example.com');
        $plan = $this->plan($coach, 'Bloc force');

        $this->client->loginUser($coach);
        $this->client->request('POST', '/plan-template/'.$plan->getId().'/assign', [
            '_token' => $this->assignToken($plan),
            'athlete' => (string) $stranger->getId(),
        ]);

        // Refusé par la garde, pas par le jeton : le message le dit.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash--error', 'Choisis un de tes athlètes');
        $this->em->clear();
        self::assertSame($coach->getId(), $this->mustFind(PlanTemplate::class, $plan->getId())->getOwner()?->getId());
    }

    /**
     * Ses séances datées sont au calendrier du coach et pointent les copies locales
     * qui partiraient avec le plan : on refuse plutôt que de démêler en silence.
     */
    public function testAPlanAlreadyOnTheCalendarCannotBeGiven(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $plan = $this->plan($coach, 'Bloc force');
        $item = $this->placeDirectly($plan, $this->workout($coach, 'Upper A'), 1, 1);

        // Jeton pris tant que le formulaire est encore proposé.
        $this->client->loginUser($coach);
        $token = $this->assignToken($plan);

        $this->em->persist(
            (new ScheduledWorkout())
                ->setOwner($coach)
                ->setWorkout($item->getWorkout())
                ->setSourcePlanTemplate($plan)
                ->setSourcePlanItem($item)
                ->setPlanAnchorDate(new \DateTimeImmutable('monday this week'))
                ->setScheduledDate(new \DateTimeImmutable('monday this week'))
                ->setStatus(ScheduledStatus::PLANNED)
        );
        $this->em->flush();
        // Le plan en mémoire ne connaît pas cette séance datée (côté inverse) :
        // la page doit le relire, comme en vrai.
        $this->em->clear();

        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        // Le formulaire n'est pas proposé, la raison l'est.
        self::assertCount(0, $crawler->filter('form[action$="/assign"]'));
        self::assertStringContainsString('déjà sur ton calendrier', $crawler->filter('body')->text());

        $this->client->request('POST', '/plan-template/'.$plan->getId().'/assign', [
            '_token' => $token,
            'athlete' => (string) $athlete->getId(),
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash--error', 'déjà sur ton calendrier');
        $this->em->clear();
        self::assertSame($coach->getId(), $this->mustFind(PlanTemplate::class, $plan->getId())->getOwner()?->getId());
    }

    // ---- Copie de plan : lien public ------------------------------------------

    public function testAnonymousVisitorCanSendAPlanLinkAndIsInvitedToLogInToCopy(): void
    {
        $owner = $this->user('owner@example.com');
        $token = $this->share($this->plan($owner, 'Prepa semi'));

        $this->client->request('GET', '/s/plan/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-publicbanner', 'Page publique');
        self::assertSelectorTextContains('button[data-action="clipboard#copy"]', 'Copier le lien à envoyer');
        self::assertSelectorNotExists('[data-action="clipboard#share"]');
        self::assertSelectorExists('a[href="/plan-template/shared/'.$token.'/copy"]');
        self::assertSelectorNotExists('#plan-copy-shared');

        $this->client->request('GET', '/plan-template/shared/'.$token.'/copy');
        self::assertResponseRedirects('/login');
    }

    public function testLoggedInReaderCopiesASharedPlanWithItsWorkouts(): void
    {
        $owner = $this->user('owner@example.com');
        $reader = $this->user('reader@example.com');
        $exercise = (new Exercise())->setOwner($owner)->setName('Tirage serré')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);
        $upper = $this->workout($owner, 'Upper A', $exercise);
        $plan = $this->plan($owner, 'Prepa semi');
        $this->placeDirectly($plan, $upper, 1, 1);
        $this->placeDirectly($plan, $upper, 2, 1);

        $this->client->loginUser($reader);
        $crawler = $this->client->request('GET', '/s/plan/'.$this->share($plan));
        $this->client->submit($crawler->filter('#plan-copy-shared')->form());

        $this->em->clear();
        $copy = $this->em->getRepository(PlanTemplate::class)->findOneBy(['owner' => $reader]);
        self::assertNotNull($copy);
        self::assertResponseRedirects('/plan-template/'.$copy->getId());
        self::assertCount(2, $copy->getPlanItems());
        self::assertNull($copy->getShareToken(), 'La copie arrive privée.');

        // Une seule racine chez le lecteur, et un seul exercice recopié.
        $roots = $this->em->getRepository(Workout::class)->findBy(['owner' => $reader, 'planLocal' => false]);
        self::assertCount(1, $roots);
        self::assertCount(1, $this->em->getRepository(Exercise::class)->findBy(['owner' => $reader]));
        foreach ($copy->getPlanItems() as $item) {
            self::assertSame($roots[0]->getId(), $item->getWorkout()?->getOrigin()?->getId());
        }
    }

    public function testOwnerIsSentToHisOwnPlanInsteadOfCopying(): void
    {
        $owner = $this->user('owner@example.com');
        $plan = $this->plan($owner, 'Prepa semi');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/s/plan/'.$this->share($plan));

        self::assertSelectorExists('a[href="/plan-template/'.$plan->getId().'"]');
        self::assertSelectorNotExists('#plan-copy-shared');
    }

    // ---- Partage par lien d'un plan (#61) --------------------------------------

    /**
     * Privé par défaut : ni la page du plan, ni une plage de semaines, ni une de
     * ses cases n'existent avant que le propriétaire ouvre le partage, depuis la
     * fiche du plan.
     */
    public function testAPlanIsPrivateUntilItsOwnerSharesIt(): void
    {
        $owner = $this->user('owner@example.com');
        $plan = $this->plan($owner, 'Prepa semi');
        $item = $this->placeDirectly($plan, $this->workout($owner, 'Upper A'), 1, 1);

        $this->client->request('GET', '/s/plan/'.$plan->getSlug());
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId());
        self::assertSelectorTextContains('.kd-sharelink__state', 'Plan privé');
        self::assertSelectorNotExists('.kd-sharerange');
        self::assertSelectorNotExists('a[href*="/s/plan/"]');

        $this->client->submit($crawler->filter('.kd-sharelink form')->form());
        self::assertResponseRedirects('/plan-template/'.$plan->getId());
        $this->em->clear();
        $token = (string) $this->mustFind(PlanTemplate::class, $plan->getId())->getShareToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-sharelink__state', 'Plan partagé par lien');
        self::assertStringEndsWith('/s/plan/'.$token, (string) $crawler->filter('.kd-sharerange')->attr('data-share-range-base-value'));

        foreach (['/s/plan/'.$token, '/s/plan/'.$token.'/semaines/1-2', '/s/plan/'.$token.'/seance/'.$item->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    /**
     * Une case (copie `planLocal`) n'a jamais de lien à elle : la page publique
     * du plan l'ouvre sous SON jeton, et seulement pour ses propres cases.
     * Arrêter le partage du plan ferme aussi ses cases.
     */
    public function testAPlanCellOpensOnlyUnderItsPlanLink(): void
    {
        $owner = $this->user('owner@example.com');
        $plan = $this->plan($owner, 'Prepa semi');
        $item = $this->placeDirectly($plan, $this->workout($owner, 'Upper A'), 1, 1);
        $other = $this->placeDirectly($this->plan($owner, 'Autre plan'), $this->workout($owner, 'Lower B'), 1, 1);
        $token = $this->share($plan);

        $crawler = $this->client->request('GET', '/s/plan/'.$token);
        $cellUrl = '/s/plan/'.$token.'/seance/'.$item->getId();
        self::assertSelectorExists('a[href="'.$cellUrl.'"]');

        $this->client->request('GET', $cellUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-wk__title', 'Upper A');
        self::assertSelectorTextContains('.kd-publicbanner', 'Page publique');
        self::assertSelectorExists('a[href="/s/plan/'.$token.'"]');
        // Le plan se copie, pas la case.
        self::assertSelectorNotExists('#wk-copy-shared');

        // La case n'a pas de jeton à elle.
        self::assertNull($item->getWorkout()?->getShareToken());

        // Une case d'un autre plan ne s'ouvre pas sous ce jeton.
        $this->client->request('GET', '/s/plan/'.$token.'/seance/'.$other->getId());
        self::assertResponseStatusCodeSame(404);

        (new ShareLink())->close($this->mustFind(PlanTemplate::class, $plan->getId()));
        $this->em->flush();
        $this->client->request('GET', $cellUrl);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/s/plan/'.$token.'/semaines/1');
        self::assertResponseStatusCodeSame(404);
    }

    /** Le coach co-édite le plan de son athlète, il ne le publie pas (C-15). */
    public function testTheCoachCannotShareHisAthletePlan(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $plan = $this->plan($athlete, 'Prepa semi');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/plan-template/'.$plan->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-sharelink');
        self::assertSelectorNotExists('.kd-sharerange');

        $this->client->request('POST', '/plan-template/'.$plan->getId().'/share', ['_token' => 'x', 'action' => 'open']);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->mustFind(PlanTemplate::class, $plan->getId())->getShareToken());
    }

    /**
     * Publier est un choix du propriétaire : un plan donné à un athlète arrive
     * chez lui privé, quel que soit le choix du coach.
     */
    public function testAGivenPlanArrivesPrivate(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $plan = $this->plan($coach, 'Bloc force');
        $token = $this->share($plan);

        $this->client->loginUser($coach);
        $this->client->request('POST', '/plan-template/'.$plan->getId().'/assign', [
            '_token' => $this->assignToken($plan),
            'athlete' => (string) $athlete->getId(),
        ]);

        $this->em->clear();
        $plan = $this->mustFind(PlanTemplate::class, $plan->getId());
        self::assertSame($athlete->getId(), $plan->getOwner()?->getId());
        self::assertNull($plan->getShareToken());
        $this->client->request('GET', '/s/plan/'.$token);
        self::assertResponseStatusCodeSame(404);
    }

    // ---- Copie de plan : fiche coach ------------------------------------------

    public function testCoachGivesACopyOfHisPlanToHisAthlete(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $plan = $this->plan($coach, 'Bloc force');
        $this->placeDirectly($plan, $this->workout($coach, 'Upper A'), 1, 1);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/plan/copy"]')->form();
        $form['plan'] = (string) $plan->getId();
        $this->client->submit($form);

        $this->em->clear();
        $copy = $this->em->getRepository(PlanTemplate::class)->findOneBy(['owner' => $athlete]);
        self::assertNotNull($copy);
        self::assertResponseRedirects('/plan-template/'.$copy->getId().'/edit');
        self::assertSame('Bloc force', $copy->getTitle());
        // Le modèle reste au coach.
        self::assertSame($coach->getId(), $this->mustFind(PlanTemplate::class, $plan->getId())->getOwner()?->getId());
    }

    public function testCoachCannotGiveAPlanHeDoesNotOwn(): void
    {
        [$coach, $athlete] = $this->coachAndAthlete();
        $foreign = $this->plan($this->user('stranger@example.com'), 'Plan d\'un tiers');
        $this->plan($coach, 'Bloc force');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $token = $crawler->filter('form[action$="/plan/copy"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/plan/copy', [
            '_token' => $token,
            'plan' => (string) $foreign->getId(),
        ]);

        self::assertResponseRedirects('/coach/athlete/'.$athlete->getId());
        self::assertCount(0, $this->em->getRepository(PlanTemplate::class)->findBy(['owner' => $athlete]));
    }

    // ---- Helpers --------------------------------------------------------------

    /** Ouvre le partage comme le fait le service, et rend le jeton. */
    private function share(PlanTemplate $plan): string
    {
        (new ShareLink())->open($plan);
        $this->em->flush();

        return (string) $plan->getShareToken();
    }

    /** @return array{User, User} */
    private function coachAndAthlete(): array
    {
        $coach = $this->user('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->user('athlete@example.com');
        $this->coaching($coach, $athlete);

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

    private function coaching(User $coach, User $athlete): void
    {
        $this->em->persist(
            (new Coaching())
                ->setCoach($coach)
                ->setAthlete($athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($coach)
                ->setRespondedAt(new \DateTimeImmutable())
        );
        $this->em->flush();
    }

    private function workout(User $owner, string $title, ?Exercise $exercise = null): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower((string) preg_replace('/\W+/', '-', $title)).'-'.bin2hex(random_bytes(3)));

        $exercise ??= (new Exercise())->setName('Squat')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(8)
        );
        $workout->addBlock($block);

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function plan(User $owner, string $title): PlanTemplate
    {
        $plan = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDurationWeeks(4)
            ->setSlug(strtolower((string) preg_replace('/\W+/', '-', $title)).'-'.bin2hex(random_bytes(3)));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /** Une case posée comme le fait l'éditeur : copie locale racinée sur `$source`. */
    private function placeDirectly(PlanTemplate $plan, Workout $source, int $week, int $day): PlanItem
    {
        $cell = (new Workout())
            ->setOwner($source->getOwner())
            ->setTitle((string) $source->getTitle())
            ->setSlug('cell-'.bin2hex(random_bytes(4)))
            ->setPlanLocal(true)
            ->setOrigin($source);
        foreach ($source->getBlocks() as $block) {
            $copy = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $copy->addPrescribedExercise(
                    (new PrescribedExercise())
                        ->setExercise($prescribed->getExercise())
                        ->setPosition(0)
                        ->setPrescriptionType(PrescriptionType::SETS_REPS)
                        ->setSets(3)->setReps(8)
                );
            }
            $cell->addBlock($copy);
        }
        $this->em->persist($cell);

        $item = (new PlanItem())->setWorkout($cell)->setWeekNumber($week)->setDayOfWeek($day);
        $plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function place(PlanTemplate $plan, Workout $source, int $week, int $day): void
    {
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');
        $this->client->request('POST', '/plan-template/'.$plan->getId().'/place', [
            '_token' => $crawler->filter('[data-place-token]')->attr('data-place-token'),
            'workoutId' => (string) $source->getId(),
            'week' => (string) $week,
            'day' => (string) $day,
        ]);
    }

    /** Le jeton du formulaire « Donner ce plan à », tel que l'éditeur l'affiche. */
    private function assignToken(PlanTemplate $plan): string
    {
        $crawler = $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');

        return (string) $crawler->filter('form[action$="/assign"] input[name="_token"]')->attr('value');
    }
}
