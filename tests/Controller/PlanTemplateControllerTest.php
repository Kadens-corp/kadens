<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\ImportedActivity;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\WorkoutCloner;
use App\Tests\FindsEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PlanTemplateControllerTest extends WebTestCase
{
    use FindsEntities;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : les séances datées citent trame et case, elles partent
        // en premier ; les plans portent la jointure vers les objectifs, ils
        // passent donc avant eux, et les deux avant les utilisateurs.
        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
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

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/plan-template');

        self::assertResponseRedirects('/login');
    }

    /**
     * La création est un simple POST depuis l'index : pas d'écran de formulaire,
     * on atterrit sur l'éditeur avec un brouillon de 4 semaines dont le titre
     * s'ouvre d'emblée (`rename=1`).
     */
    public function testCreatePlanLandsOnEditorWithFourWeeks(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/plan-template');
        $this->client->submitForm('Nouveau plan');

        $created = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Nouveau plan']);
        self::assertNotNull($created);
        self::assertSame('nouveau-plan', $created->getSlug());
        self::assertSame(4, $created->getDurationWeeks());
        self::assertNotNull($created->getCreatedAt());
        self::assertResponseRedirects('/plan-template/'.$created->getId().'/edit?rename=1');
    }

    /**
     * Les semaines s'ajoutent une par une ou par paquet, et le plafond borne le
     * paquet au lieu de le refuser entièrement.
     */
    public function testAddWeeksOneByOneAndInBatch(): void
    {
        $user = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 4);
        $this->client->loginUser($user);

        $url = '/plan-template/'.$template->getId().'/weeks/add';
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token]);
        $this->em->clear();
        self::assertSame(5, $this->mustFind(PlanTemplate::class, $template->getId())->getDurationWeeks());

        $this->client->request('POST', $url, ['_token' => $token, 'count' => 6]);
        $this->em->clear();
        self::assertSame(11, $this->mustFind(PlanTemplate::class, $template->getId())->getDurationWeeks());

        // Paquet qui dépasse : borné à 52, pas rejeté.
        $this->client->request('POST', $url, ['_token' => $token, 'count' => 90]);
        $this->em->clear();
        self::assertSame(52, $this->mustFind(PlanTemplate::class, $template->getId())->getDurationWeeks());
    }

    /**
     * Le rattachement se pose aussi depuis l'éditeur de plan (bandeau #plan-goals),
     * pas seulement depuis la fiche objectif : la relation se navigue dans les deux
     * sens.
     */
    public function testAttachGoalFromThePlanEditor(): void
    {
        $user = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($user, 'Prépa trail', 4);

        $goal = (new Goal())
            ->setOwner($user)
            ->setTitle('Trail 42k')
            ->setTargetDate(new \DateTimeImmutable('+10 weeks'));
        $this->em->persist($goal);
        $this->em->flush();

        $templateId = $template->getId();
        $url = '/plan-template/'.$templateId.'/goals';

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'action' => 'attach', 'goalId' => $goal->getId()]);
        $this->em->clear();
        self::assertCount(1, $this->mustFind(PlanTemplate::class, $templateId)->getGoals());

        $this->client->request('POST', $url, ['_token' => $token, 'action' => 'detach', 'goalId' => $goal->getId()]);
        $this->em->clear();
        self::assertCount(0, $this->mustFind(PlanTemplate::class, $templateId)->getGoals());
    }

    /**
     * B-05 coach : après « Associer » dans l'éditeur, le flash d'arrivée
     * (« Plan créé pour… ») restait en haut de page et passait pour la réponse.
     * Le stream le retire et confirme dans le bandeau même.
     */
    public function testAttachGoalStreamClearsStaleFlashAndConfirmsInPlace(): void
    {
        $user = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($user, 'Prépa trail', 4);

        $goal = (new Goal())
            ->setOwner($user)
            ->setTitle('Trail 42k')
            ->setTargetDate(new \DateTimeImmutable('+10 weeks'));
        $this->em->persist($goal);
        $this->em->flush();

        $templateId = $template->getId();
        $url = '/plan-template/'.$templateId.'/goals';

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'action' => 'attach', 'goalId' => $goal->getId()], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" targets=".kd-flash">', $body);
        self::assertStringContainsString('Plan associé à « Trail 42k ».', $body);
        self::assertStringContainsString('role="status"', $body);
    }

    /** Sans JS, la même confirmation arrive en flash sur l'éditeur. */
    public function testAttachGoalWithoutJsConfirmsWithAFlash(): void
    {
        $user = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($user, 'Prépa trail', 4);

        $goal = (new Goal())
            ->setOwner($user)
            ->setTitle('Trail 42k')
            ->setTargetDate(new \DateTimeImmutable('+10 weeks'));
        $this->em->persist($goal);
        $this->em->flush();

        $templateId = $template->getId();
        $url = '/plan-template/'.$templateId.'/goals';

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'action' => 'attach', 'goalId' => $goal->getId()]);
        self::assertResponseRedirects('/plan-template/'.$templateId.'/edit');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash', 'Plan associé à « Trail 42k ».');
    }

    public function testPlaceItemForksWorkoutInCell(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 1);
        $this->client->loginUser($user);

        // La pose se fait par la palette (mode tampon / glisser-déposer) : POST
        // vers /place avec workoutId + week + day. Le jeton CSRF est porté par la
        // palette dans la page d'édition.
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-place-token]')->attr('data-place-token');

        $this->client->request('POST', '/plan-template/'.$template->getId().'/place', [
            '_token' => $token,
            'workoutId' => $workout->getId(),
            'week' => 1,
            'day' => 1,
        ]);

        $this->em->clear();
        $item = $this->em->getRepository(PlanItem::class)->findOneBy([]);
        self::assertNotNull($item);
        self::assertSame(1, $item->getWeekNumber());
        self::assertSame(1, $item->getDayOfWeek());
        // Fork à la pose : la case porte une COPIE privée (planLocal), pas la
        // séance de bibliothèque elle-même.
        $fork = $item->getWorkout();
        self::assertNotNull($fork);
        self::assertNotSame($workout->getId(), $fork->getId());
        self::assertTrue($fork->isPlanLocal());
        self::assertSame('Sortie longue', $fork->getTitle());
    }

    /**
     * Reposer une séance déjà faite part de son dernier réalisé, et la palette
     * l'annonce. La copie reste rattachée à la séance de bibliothèque.
     */
    public function testPlaceItemStartsFromTheLastDoneSession(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $prescribed = $library->getBlocks()->first() ?: throw new \LogicException();
        $squat = $prescribed->getPrescribedExercises()->first() ?: throw new \LogicException();

        $scheduled = (new ScheduledWorkout())->setOwner($user)->setWorkout($library)
            ->setScheduledDate(new \DateTimeImmutable('2026-10-14'))->setStatus(ScheduledStatus::DONE);
        $logged = (new LoggedExercise())->setExercise($squat->getExercise())->setExerciseName('Squat barre')
            ->setSourcePrescribedExercise($squat)->setPosition(1);
        foreach ([1, 2, 3, 4, 5] as $position) {
            $logged->addLoggedSet((new LoggedSet())->setPosition($position)->setSetType(SetType::NORMAL)->setReps(5)->setWeightKg(105.0));
        }
        $scheduled->addLoggedExercise($logged);
        $this->em->persist($scheduled);

        $template = $this->createPlanTemplate($user, 'Plan force', 1);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("d'après le 14/10", $crawler->filter('[data-workout-id="'.$library->getId().'"]')->text());
        $token = $crawler->filter('[data-place-token]')->attr('data-place-token');

        $this->client->request('POST', '/plan-template/'.$template->getId().'/place', [
            '_token' => $token,
            'workoutId' => $library->getId(),
            'week' => 1,
            'day' => 2,
        ]);

        $this->em->clear();
        $fork = $this->em->getRepository(PlanItem::class)->findOneBy([])?->getWorkout();
        self::assertNotNull($fork);
        self::assertSame($library->getId(), $fork->getOrigin()?->getId());
        $copied = $fork->getBlocks()->first() ?: throw new \LogicException();
        $copiedSquat = $copied->getPrescribedExercises()->first() ?: throw new \LogicException();
        self::assertSame(105.0, $copiedSquat->getWeightKg());
        self::assertSame(5, $copiedSquat->getSets());
    }

    /**
     * On compose la semaine suivante en sachant comment s'est passée la
     * précédente : la carte de palette donne le dernier ressenti et la moyenne
     * des dernières exécutions notées de la lignée, copies de plan comprises.
     */
    public function testThePaletteCardTellsHowTheLineageFelt(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $copy = (new Workout())->setOwner($user)->setTitle('Upper A')->setSlug('upper-a-copie')->setPlanLocal(true)->setOrigin($library);
        $this->em->persist($copy);

        foreach ([['2026-10-07', $library, 2, 8], ['2026-10-14', $copy, 4, 6]] as [$date, $workout, $feeling, $rpe]) {
            $this->em->persist((new ScheduledWorkout())->setOwner($user)->setWorkout($workout)
                ->setScheduledDate(new \DateTimeImmutable($date))->setStatus(ScheduledStatus::DONE)
                ->setFeeling($feeling)->setSessionRpe($rpe));
        }

        $template = $this->createPlanTemplate($user, 'Plan force', 1);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-workout-id="'.$library->getId().'"] .kd-palettecard__feel');
        self::assertStringContainsString('En forme', $card->text(), 'La dernière exécution en tête.');
        self::assertStringContainsString('Difficulté 6/10', $card->text());
        self::assertStringContainsString('moy. 2 : normal, 7,0/10', $card->text());
        // La liste datée, au survol, de la plus récente à la plus ancienne.
        self::assertSame("14/10 : En forme, difficulté 6/10\n07/10 : Faible, difficulté 8/10", $card->attr('title'));
    }

    /**
     * Repli sans JS du glisser-déposer : le menu d'une case porte un vrai
     * formulaire « Déplacer vers » (selects semaine/jour). C'est le seul chemin de
     * déplacement au clavier, et le seul praticable au doigt quand la trame est
     * longue — l'appui long suppose de voir la case d'arrivée.
     */
    public function testMoveItemFromTheRowMenuWithoutJs(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 3);

        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(1);
        $item->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();
        $itemId = $item->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/items/'.$itemId.'/move"]')->form();
        $form['week'] = '3';
        $form['day'] = '6';
        $this->client->submit($form);

        // Sans format stream demandé, la route redirige vers l'éditeur (gridResponse).
        self::assertResponseRedirects('/plan-template/'.$template->getId().'/edit');

        $this->em->clear();
        $moved = $this->em->getRepository(PlanItem::class)->find($itemId);
        self::assertNotNull($moved);
        self::assertSame(3, $moved->getWeekNumber());
        self::assertSame(6, $moved->getDayOfWeek());
    }

    /**
     * Copier UNE séance vers une autre semaine, sans recopier toute la sienne.
     * La copie a sa propre séance locale (progression indépendante) et garde la
     * lignée ; la source ne bouge pas ; la case cible ne perd rien.
     */
    public function testCopyOneItemToAnotherWeek(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $template = $this->createPlanTemplate($user, 'Bloc force', 4);
        $source = $this->placeCell($template, $user, $library, 1, 2, 'Lourd');
        $sourceCell = $source->getWorkout();
        self::assertNotNull($sourceCell);
        $alreadyThere = $this->placeCell($template, $user, $library, 3, 5, null);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $form = $crawler->filter('form[action$="/items/'.$source->getId().'/copy"]')->form();
        // Par défaut : semaine suivante, même jour.
        self::assertSame(['week' => '2', 'day' => '2'], array_intersect_key($form->getValues(), ['week' => 0, 'day' => 0]));
        $form['week'] = '3';
        $form['day'] = '5';
        $this->client->submit($form);

        self::assertResponseRedirects('/plan-template/'.$template->getId().'/edit');

        $this->em->clear();
        $items = $this->em->getRepository(PlanItem::class)->findBy(['planTemplate' => $template->getId()]);
        self::assertCount(3, $items, 'La copie s\'ajoute, elle ne remplace rien.');

        $copy = null;
        foreach ($items as $item) {
            if (3 === $item->getWeekNumber() && 5 === $item->getDayOfWeek() && $item->getId() !== $alreadyThere->getId()) {
                $copy = $item;
            }
        }
        self::assertNotNull($copy);
        self::assertSame('Lourd', $copy->getNotes());
        $copyCell = $copy->getWorkout();
        self::assertNotNull($copyCell);
        self::assertNotSame($sourceCell->getId(), $copyCell->getId(), 'Sa propre séance locale.');
        self::assertTrue($copyCell->isPlanLocal());
        self::assertSame($library->getId(), $copyCell->getOrigin()?->getId(), 'Même lignée.');

        $kept = $this->em->getRepository(PlanItem::class)->find($source->getId());
        self::assertNotNull($kept);
        self::assertSame([1, 2], [$kept->getWeekNumber(), $kept->getDayOfWeek()]);
    }

    public function testCopyOneItemOutsideThePlanIsRefused(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $template = $this->createPlanTemplate($user, 'Bloc force', 4);
        $source = $this->placeCell($template, $user, $library, 1, 2, null);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $token = $crawler->filter('form[action$="/items/'.$source->getId().'/copy"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/plan-template/'.$template->getId().'/items/'.$source->getId().'/copy', [
            '_token' => $token, 'week' => '9', 'day' => '1',
        ]);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(PlanItem::class)->findBy(['planTemplate' => $template->getId()]));
    }

    /**
     * #111 : « Copier vers » d'une semaine prend plusieurs cibles d'un coup. Chaque
     * cible est remplacée et reçoit ses propres copies locales ; la suivante est
     * cochée d'office ; la confirmation dit ce qui a été copié où.
     */
    public function testCopyAWeekToSeveralWeeks(): void
    {
        $user = $this->createUser('owner@example.com');
        $upper = $this->createLiftWorkout($user, 'Upper A');
        $lower = $this->createLiftWorkout($user, 'Lower A');
        $template = $this->createPlanTemplate($user, 'Bloc force', 4);
        $this->placeCell($template, $user, $upper, 1, 1, 'Lourd');
        $this->placeCell($template, $user, $lower, 1, 4, null);
        $replaced = $this->placeCell($template, $user, $upper, 3, 2, null);
        $templateId = $template->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $form = $crawler->filter('form[action$="/weeks/1/copy"]')->form();
        self::assertSame(['2'], array_values($form->getPhpValues()['targets'] ?? []), 'La semaine suivante est cochée d\'office.');
        self::assertCount(3, $crawler->filter('form[action$="/weeks/1/copy"] input[name="targets[]"]'), 'Toutes les semaines sauf la source.');

        $this->client->request('POST', '/plan-template/'.$templateId.'/weeks/1/copy', [
            '_token' => $form->getValues()['_token'],
            'targets' => ['4', '2', '3', '3', '1', '9'],
        ]);
        self::assertResponseRedirects('/plan-template/'.$templateId.'/edit');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash', 'Semaine 1 copiée sur S2, S3 et S4.');

        $this->em->clear();
        $byWeek = [];
        $cells = [];
        foreach ($this->em->getRepository(PlanItem::class)->findBy(['planTemplate' => $templateId]) as $item) {
            $byWeek[(int) $item->getWeekNumber()][] = [$item->getDayOfWeek(), $item->getNotes()];
            $cells[] = $item->getWorkout()?->getId();
        }
        ksort($byWeek);
        foreach ($byWeek as &$days) {
            sort($days);
        }
        unset($days);
        $expected = [[1, 'Lourd'], [4, null]];
        self::assertSame([1 => $expected, 2 => $expected, 3 => $expected, 4 => $expected], $byWeek, 'S3 remplacée, la source et les doublons ignorés.');
        self::assertCount(8, array_unique($cells), 'Une copie locale par case et par semaine.');
        self::assertNull($this->em->getRepository(PlanItem::class)->find($replaced->getId()));
    }

    /** En Turbo, la confirmation arrive sous l'en-tête de la semaine source, les flashes partent. */
    public function testCopyAWeekConfirmsUnderItsHeaderInTheStream(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $template = $this->createPlanTemplate($user, 'Bloc force', 3);
        $this->placeCell($template, $user, $library, 2, 1, null);
        $templateId = $template->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $token = $crawler->filter('form[action$="/weeks/2/copy"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/plan-template/'.$templateId.'/weeks/2/copy', ['_token' => $token, 'targets' => ['3']], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" targets=".kd-flash">', $body);
        self::assertStringContainsString('Semaine 2 copiée sur S3.', $body);
        self::assertSame(1, substr_count($body, 'kd-planweek__notice'), 'Sous la seule semaine source.');
    }

    /**
     * « Toutes les suivantes » copie vers chaque semaine après la source, quelles
     * que soient les cases cochées, et ne s'affiche que s'il en reste deux ou plus.
     */
    public function testCopyAWeekToAllTheFollowingOnes(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $template = $this->createPlanTemplate($user, 'Bloc force', 5);
        $this->placeCell($template, $user, $library, 2, 3, null);
        $this->placeCell($template, $user, $library, 1, 1, null);
        $templateId = $template->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        self::assertCount(1, $crawler->filter('form[action$="/weeks/2/copy"] button[name="all_after"]'));
        self::assertCount(1, $crawler->filter('form[action$="/weeks/3/copy"] button[name="all_after"]'));
        self::assertCount(0, $crawler->filter('form[action$="/weeks/4/copy"] button[name="all_after"]'), 'Une seule suivante : la case cochée suffit.');
        self::assertCount(0, $crawler->filter('form[action$="/weeks/5/copy"] button[name="all_after"]'));

        $token = $crawler->filter('form[action$="/weeks/2/copy"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/plan-template/'.$templateId.'/weeks/2/copy', [
            '_token' => $token,
            'targets' => ['1'],
            'all_after' => '1',
        ]);
        self::assertResponseRedirects('/plan-template/'.$templateId.'/edit');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash', 'Semaine 2 copiée sur toutes les suivantes (S3 à S5).');

        $this->em->clear();
        $weeks = [];
        foreach ($this->em->getRepository(PlanItem::class)->findBy(['planTemplate' => $templateId]) as $item) {
            $weeks[] = [$item->getWeekNumber(), $item->getDayOfWeek()];
        }
        sort($weeks);
        self::assertSame([[1, 1], [2, 3], [3, 3], [4, 3], [5, 3]], $weeks, 'S1 cochée mais ignorée : le bouton cliqué l\'emporte.');
    }

    /** Rien de coché : rien ne bouge, et on le dit au lieu de se taire. */
    public function testCopyAWeekWithoutTargetSaysSo(): void
    {
        $user = $this->createUser('owner@example.com');
        $library = $this->createLiftWorkout($user, 'Upper A');
        $template = $this->createPlanTemplate($user, 'Bloc force', 3);
        $this->placeCell($template, $user, $library, 1, 1, null);
        $templateId = $template->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $token = $crawler->filter('form[action$="/weeks/1/copy"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/plan-template/'.$templateId.'/weeks/1/copy', ['_token' => $token]);
        self::assertResponseRedirects('/plan-template/'.$templateId.'/edit');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash--error', 'Coche au moins une semaine de destination.');

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(PlanItem::class)->findBy(['planTemplate' => $templateId]));
    }

    /** Une case comme la pose la crée : une copie locale racinée sur la séance de bibliothèque. */
    private function placeCell(PlanTemplate $template, User $owner, Workout $library, int $week, int $day, ?string $notes): PlanItem
    {
        $cell = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($library, $owner, null, true);
        $item = (new PlanItem())->setWeekNumber($week)->setDayOfWeek($day)->setNotes($notes)->setWorkout($cell);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    public function testDuplicateCopiesGrid(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 2);

        $item = (new PlanItem())->setWeekNumber(2)->setDayOfWeek(5)->setNotes('tempo');
        $item->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $this->client->submitForm('Dupliquer ce plan');

        $copy = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Plan 5k (copie)']);
        self::assertNotNull($copy);
        self::assertSame($user->getId(), $copy->getOwner()?->getId());
        self::assertSame(2, $copy->getDurationWeeks());
        self::assertNotSame($template->getSlug(), $copy->getSlug());
        self::assertCount(1, $copy->getPlanItems());

        $copiedItem = $copy->getPlanItems()->first();
        self::assertNotFalse($copiedItem);
        self::assertSame(2, $copiedItem->getWeekNumber());
        self::assertSame(5, $copiedItem->getDayOfWeek());
        // Duplication de plan = plans indépendants : la case porte une COPIE clonée
        // (planLocal), pas la même séance, pour que les progressions ne se partagent pas.
        $copiedWorkout = $copiedItem->getWorkout();
        self::assertNotNull($copiedWorkout);
        self::assertNotSame($workout->getId(), $copiedWorkout->getId());
        self::assertSame('Sortie longue', $copiedWorkout->getTitle());
        self::assertTrue($copiedWorkout->isPlanLocal());
        self::assertResponseRedirects('/plan-template/'.$copy->getId().'/edit');
    }

    public function testShowDeniedToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $template = $this->createPlanTemplate($owner, 'Plan privé', 4);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/plan-template/'.$template->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * KL-49 — un plan jamais posé au calendrier n'a pas de réalisé : le bloc reste
     * celui du prévu seul, et il le dit, sans espace réservé à ce qui n'existe pas.
     */
    public function testShowWithoutInstantiationKeepsThePlannedRampAlone(): void
    {
        $owner = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($owner, 'Plan 5k', 2);
        $template->addPlanItem(
            (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($this->createLiftWorkout($owner, 'Squat lourd'))
        );
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/plan-template/'.$template->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun détail saisi');
        self::assertSelectorNotExists('.kd-prog__adherence');
        self::assertSelectorNotExists('.kd-prog__runpick');
    }

    /**
     * Avec une instanciation, l'observance s'affiche. Une seule instanciation =
     * pas de sélecteur : il n'y a rien à choisir.
     */
    public function testShowWithOneInstantiationShowsAdherenceWithoutPicker(): void
    {
        $owner = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($owner, 'Plan force', 2);
        $workout = $this->createWorkout($owner, 'Squat lourd');
        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->flush();

        $this->scheduleFromPlan($owner, $template, $item, '2026-03-02', '2026-03-04', ScheduledStatus::DONE);
        $this->scheduleFromPlan($owner, $template, $item, '2026-03-02', '2026-03-06', ScheduledStatus::MISSED);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/plan-template/'.$template->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-prog__adherence', 'séance tenue sur 2');
        self::assertSelectorNotExists('.kd-prog__runpick');
    }

    /**
     * Deux instanciations : la plus récente par défaut, l'autre à une navigation
     * GET (`?run=`). Une trame n'ayant pas de dates, c'est l'ancre qui dit quelle
     * fois on regarde.
     */
    public function testShowPicksTheMostRecentRunAndOffersTheOthers(): void
    {
        $owner = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($owner, 'Plan repassé', 2);
        $workout = $this->createWorkout($owner, 'Squat lourd');
        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->flush();

        // Premier passage : deux séances, une seule tenue. Second : tout tenu.
        $this->scheduleFromPlan($owner, $template, $item, '2026-01-05', '2026-01-07', ScheduledStatus::DONE);
        $this->scheduleFromPlan($owner, $template, $item, '2026-01-05', '2026-01-09', ScheduledStatus::MISSED);
        $this->scheduleFromPlan($owner, $template, $item, '2026-03-02', '2026-03-04', ScheduledStatus::DONE);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/plan-template/'.$template->getId());

        self::assertSelectorExists('.kd-prog__runpick');
        // Par défaut, la plus récente : une séance, tenue.
        self::assertSelectorTextContains('.kd-prog__adherence', '1');
        self::assertSelectorTextContains('.kd-prog__adherence', 'sur 1');

        $this->client->request('GET', '/plan-template/'.$template->getId().'?run=2026-01-05');
        self::assertSelectorTextContains('.kd-prog__adherence', 'sur 2');

        // Une ancre inconnue est un paramètre d'affichage, pas une ressource :
        // elle retombe sur le défaut au lieu de lever.
        $this->client->request('GET', '/plan-template/'.$template->getId().'?run=1999-01-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-prog__adherence', 'sur 1');
    }

    /**
     * L'impasse que l'ancre de contexte corrige : depuis l'éditeur de trame, la
     * case ouvre la **copie locale** (`planLocal`), que la bibliothèque exclut.
     * Un retour vers `/workout` menait donc à un index où la séance qu'on venait
     * de quitter n'apparaît pas.
     */
    /**
     * Le réalisé d'une sortie vient de la montre : une activité importée rattachée
     * à une séance du plan pose sa barre sur la série de course, et la page le dit
     * en toutes lettres dans l'en-tête de la série. De bout en bout : agrégat SQL,
     * contrôleur, rendu.
     */
    public function testShowOverlaysTheImportedActivityOnTheEnduranceSeries(): void
    {
        $owner = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($owner, 'Plan 10k', 2);
        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($this->createRunWorkout($owner, 'Sortie tempo', 8000));
        $template->addPlanItem($item);
        $this->em->flush();

        $session = $this->scheduleFromPlan($owner, $template, $item, '2026-03-02', '2026-03-04', ScheduledStatus::DONE);
        $activity = (new ImportedActivity($owner, ActivitySource::INTERVALS, 'i9001', 'Run', new \DateTimeImmutable('2026-03-04 07:00'), new \DateTimeImmutable('2026-03-04')))
            ->setActivity(ActivityType::RUNNING)
            ->setDistanceMeters(8400)
            ->setMovingSeconds(2520)
            ->setScheduledWorkout($session);
        $this->em->persist($activity);
        $this->em->flush();

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId());

        self::assertResponseIsSuccessful();
        $heads = $crawler->filter('.kd-prog__rowhead')->each(static fn ($node): string => (string) preg_replace('/\s+/', ' ', $node->text()));
        $running = array_values(array_filter($heads, static fn (string $head): bool => str_contains($head, 'Course')));
        self::assertCount(1, $running);
        self::assertStringContainsString('en distance', $running[0]);
        self::assertStringContainsString('prévu 8 km', $running[0]);
        self::assertStringContainsString('fait 8,4 km', $running[0]);
        // Sous les barres : la distance courue sur la course, et son temps en
        // mouvement sur la ligne « Temps ».
        $reals = $crawler->filter('.kd-prog__val--real')->each(static fn ($node): string => trim($node->text()));
        self::assertContains('8,4 km', $reals);
        self::assertContains('42 min', $reals);
        // Une activité importée est du réalisé : l'indice « rien de remonté » se tait.
        self::assertSelectorTextNotContains('.kd-prog__legend', 'Rien de remonté');
    }

    public function testTheComposerOpenedFromThePlanEditorReturnsToIt(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 3);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $this->client->request('POST', '/plan-template/'.$template->getId().'/place', [
            '_token' => $crawler->filter('[data-place-token]')->attr('data-place-token'),
            'workoutId' => $workout->getId(),
            'week' => 1,
            'day' => 1,
        ]);

        $this->em->clear();
        $local = $this->em->getRepository(PlanItem::class)->findOneBy([])?->getWorkout();
        self::assertNotNull($local);
        self::assertTrue($local->isPlanLocal());

        $editLink = '/workout/'.$local->getId().'/edit?from=plan-edit-'.$template->getId();
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertGreaterThan(0, $crawler->filter('a[href="'.$editLink.'"]')->count());

        $crawler = $this->client->request('GET', $editLink);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-backlink[href="/plan-template/'.$template->getId().'/edit"]'));
        self::assertSelectorTextContains('.kd-backlink', 'Plan 5k');
    }

    /**
     * Le cas d'usage d'origine : « Plans → mon plan → séance → composer ». Le
     * contexte doit tenir sur les deux sauts, sinon le retour redevient « Mes
     * séances » dès la page de consultation quittée.
     */
    public function testThePlanContextSurvivesToTheComposer(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 3);

        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(1);
        $item->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);

        $planUrl = '/plan-template/'.$template->getId();
        $crawler = $this->client->request('GET', $planUrl);
        $showLink = '/workout/'.$workout->getId().'?from=plan-'.$template->getId();
        self::assertCount(1, $crawler->filter('a.kd-planday__item[href="'.$showLink.'"]'));

        $crawler = $this->client->request('GET', $showLink);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-wk__back[href="'.$planUrl.'"]'));

        $editLink = '/workout/'.$workout->getId().'/edit?from=plan-'.$template->getId();
        self::assertCount(1, $crawler->filter('a[href="'.$editLink.'"]'));

        $crawler = $this->client->request('GET', $editLink);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-backlink[href="'.$planUrl.'"]'));
    }

    /**
     * Le titre d'un plan est privé. Un identifiant deviné dans la query ne doit
     * donc pas le révéler par le seul libellé du retour, sans jamais ouvrir la
     * page : le voter tranche avant le libellé, et son refus se lit comme une
     * absence de contexte.
     */
    public function testAForeignPlanContextIsIgnoredRatherThanLabelled(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $template = $this->createPlanTemplate($owner, 'Prépa marathon secrète', 3);
        $workout = $this->createWorkout($intruder, 'Sortie longue');

        $this->client->loginUser($intruder);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'?from=plan-'.$template->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Prépa marathon secrète', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('a.kd-wk__back[href="/workout"]'));
    }

    /** Une séance qui porte réellement de la charge : sans ça, il n'y a pas de rampe à tracer. */
    private function createLiftWorkout(User $owner, string $title): Workout
    {
        $exercise = (new Exercise())->setName('Squat barre')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)).'-'.uniqid());
        $workout->addBlock(
            (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(1)->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(1)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(5)
                    ->setReps(5)
                    ->setWeightKg(100.0)
            )
        );

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function createRunWorkout(User $owner, string $title, int $meters): Workout
    {
        $exercise = (new Exercise())->setName('Course à pied')->setActivity(ActivityType::RUNNING);
        $this->em->persist($exercise);

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)).'-'.uniqid());
        $workout->addBlock(
            (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(1)->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(1)
                    ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                    ->setDistanceMeters($meters)
            )
        );

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function scheduleFromPlan(
        User $owner,
        PlanTemplate $template,
        PlanItem $item,
        string $anchor,
        string $date,
        ScheduledStatus $status,
    ): ScheduledWorkout {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($item->getWorkout())
            ->setSourcePlanTemplate($template)
            ->setSourcePlanItem($item)
            ->setPlanAnchorDate(new \DateTimeImmutable($anchor))
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createWorkout(User $owner, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)));

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
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
}
