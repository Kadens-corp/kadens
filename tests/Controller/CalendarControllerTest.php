<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Tests\FindsEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CalendarControllerTest extends WebTestCase
{
    use FindsEntities;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        // Purge FK-safe : les exercices (laissés par d'autres classes de test)
        // référencent user ; les supprimer avant de retirer les utilisateurs.
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
        $this->client->request('GET', '/calendar');

        self::assertResponseRedirects('/login');
    }

    public function testMonthRendersWithScheduledWorkout(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mars 2026');
        self::assertStringContainsString('Sortie longue', $crawler->html());
    }

    /**
     * #107 : sur téléphone, la vue mois masque les jours sans séance (CSS,
     * `.kd-calday--free`) derrière une case qui les compte. Le marquage, lui, est
     * serveur : un jour posé ou hors du mois n'est jamais « libre ».
     */
    public function testMonthMarksTheDaysWithoutSessionAndCountsThem(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('kd-calday--free', (string) $crawler->filter('[data-day="2026-03-15"]')->attr('class'));
        self::assertStringContainsString('kd-calday--free', (string) $crawler->filter('[data-day="2026-03-16"]')->attr('class'));
        // Le 23 février complète la grille, il n'est pas du mois.
        self::assertStringNotContainsString('kd-calday--free', (string) $crawler->filter('[data-day="2026-02-23"]')->attr('class'));
        // Mars 2026 : 31 jours, un posé, aujourd'hui (novembre) hors du mois.
        self::assertCount(30, $crawler->filter('.kd-calday--free'));
        self::assertSelectorTextContains('.kd-calfree', 'Afficher les 30 jours sans séance');
    }

    public function testAddPlacesIsolatedWorkoutOnDate(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Fractionné');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        // Poser une séance passe désormais par l'endpoint `place` (carte-bouton de la
        // modale) : plus de ScheduleWorkoutType. On rejoue ce POST directement, avec
        // le jeton CSRF rendu dans le formulaire de la modale.
        $token = $crawler->filter('form[action$="/schedule/place"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/schedule/place', [
            '_token' => $token,
            'workoutId' => $workout->getId(),
            'date' => '2026-03-10',
        ]);

        $this->em->clear();
        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findOneBy([]);
        self::assertNotNull($scheduled);
        self::assertSame('2026-03-10', $scheduled->getScheduledDate()->format('Y-m-d'));
        self::assertSame(ScheduledStatus::PLANNED, $scheduled->getStatus());
        self::assertSame($workout->getId(), $scheduled->getWorkout()?->getId());
        self::assertResponseRedirects('/calendar/2026/3');
    }

    /**
     * « Nouvelle séance pour ce jour » (#92) : le même POST que la bibliothèque
     * crée le brouillon, le pose au jour choisi et ouvre le compositeur, dont le
     * retour ramène à la vue calendrier quittée.
     */
    public function testANewWorkoutCreatedFromADayIsPlacedThereAndLeadsBack(): void
    {
        $user = $this->createUser('owner@example.com');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/week/2026-03-10');

        $form = $crawler->filter('form[action$="/workout/new"]');
        self::assertCount(1, $form);
        self::assertSame('cal-week-2026-03-09', $form->filter('input[name="from"]')->attr('value'));
        self::assertStringContainsString('Nouvelle séance pour ce jour', $form->text());

        $this->client->request('POST', '/workout/new', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'date' => '2026-03-12',
            'from' => 'cal-week-2026-03-09',
        ]);

        $this->em->clear();
        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findOneBy([]);
        self::assertNotNull($scheduled);
        self::assertSame('2026-03-12', $scheduled->getScheduledDate()->format('Y-m-d'));
        self::assertSame(ScheduledStatus::PLANNED, $scheduled->getStatus());
        self::assertSame($user->getId(), $scheduled->getOwner()?->getId());

        $workout = $scheduled->getWorkout();
        self::assertNotNull($workout);
        self::assertFalse($workout->isPlanLocal());
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit?rename=1&from=cal-week-2026-03-09');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-backlink[href="/calendar/week/2026-03-09"]'));
        self::assertSelectorTextContains('.kd-flash', '12/03/2026');
    }

    /**
     * Depuis la vue mois, le jeton est celui du mois affiché ; un jeton absent ou
     * illisible retombe sur le mois de la date posée, jamais sur « Mes séances ».
     */
    public function testANewWorkoutFromTheMonthFallsBackToTheMonthOfItsDate(): void
    {
        $user = $this->createUser('owner@example.com');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        $form = $crawler->filter('form[action$="/workout/new"]');
        self::assertSame('cal-month-2026-03', $form->filter('input[name="from"]')->attr('value'));

        $this->client->request('POST', '/workout/new', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'date' => '2026-04-02',
            'from' => 'https://evil.example',
        ]);

        $workout = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $user]);
        self::assertNotNull($workout);
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit?rename=1&from=cal-month-2026-04');
    }

    /**
     * Sans date, « Nouvelle séance » garde son geste d'origine : un brouillon de
     * bibliothèque, rien dans l'agenda, pas de contexte de retour.
     */
    public function testANewWorkoutWithoutDateStaysOutOfTheCalendar(): void
    {
        $user = $this->createUser('owner@example.com');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/workout');
        $this->client->submit($crawler->filter('form[action$="/workout/new"]')->form());

        $workout = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $user]);
        self::assertNotNull($workout);
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit?rename=1');
        self::assertSame(0, $this->em->getRepository(ScheduledWorkout::class)->count([]));
    }

    public function testInstantiatePlanCreatesDatedWorkouts(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 2);

        // Semaine 1, mercredi (jour ISO 3).
        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/1');

        // Le bouton « Placer dans l'agenda » est désactivé tant qu'aucune carte de
        // plan n'est choisie (piloté par JS) : on soumet le formulaire directement.
        // 2026-01-07 est un mercredi -> ancre lundi 2026-01-05, item tombe le 07.
        $form = $crawler->filter('form[action$="/schedule/plan"]')->form();
        $form['plan_instantiation[planTemplate]'] = (string) $template->getId();
        $form['plan_instantiation[startDate]'] = '2026-01-07';
        $this->client->submit($form);

        // L'agenda s'ouvre sur la première séance posée (#68).
        self::assertResponseRedirects('/calendar/2026/1?focus=2026-01-07');

        $this->em->clear();
        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        self::assertSame('2026-01-07', $scheduled[0]->getScheduledDate()->format('Y-m-d'));
        self::assertNotNull($scheduled[0]->getSourcePlanTemplate());
    }

    /**
     * #68 : l'agenda s'ouvre là où tombe la première séance, pas sur le mois de
     * la date de départ. Ici la trame commence en semaine 3 : départ le
     * 2026-01-28 (mercredi, ancre lundi 26/01), première séance lundi 9 février.
     */
    public function testPlacingAPlanOpensTheMonthOfItsFirstSession(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan tardif', 3);
        $item = (new PlanItem())->setWeekNumber(3)->setDayOfWeek(1)->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/1');
        $form = $crawler->filter('form[action$="/schedule/plan"]')->form();
        $form['plan_instantiation[planTemplate]'] = (string) $template->getId();
        $form['plan_instantiation[startDate]'] = '2026-01-28';
        $this->client->submit($form);

        self::assertResponseRedirects('/calendar/2026/2?focus=2026-02-09');

        // La page d'arrivée passe le jour à amener à l'écran, et le porte.
        $crawler = $this->client->followRedirect();
        self::assertSame('2026-02-09', $crawler->filter('[data-controller="caladd"]')->attr('data-caladd-focus-value'));
        self::assertCount(1, $crawler->filter('[data-day="2026-02-09"]'));
        self::assertSelectorTextContains('.kd-flash', 'Plan placé dans ton agenda : 1 séance, la première le 09/02/2026.');
    }

    /**
     * Relancer sur un plan déjà placé ne redate rien : sans case nouvelle, on
     * ouvre la semaine où le plan commence déjà, pas la date saisie.
     */
    public function testReplacingAnUpToDatePlanOpensWhereItAlreadyStarts(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 1);
        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/1');
        $form = $crawler->filter('form[action$="/schedule/plan"]')->form();
        $form['plan_instantiation[planTemplate]'] = (string) $template->getId();
        $form['plan_instantiation[startDate]'] = '2026-01-07';
        $this->client->submit($form);

        $crawler = $this->client->request('GET', '/calendar/2026/1');
        $form = $crawler->filter('form[action$="/schedule/plan"]')->form();
        $form['plan_instantiation[planTemplate]'] = (string) $template->getId();
        $form['plan_instantiation[startDate]'] = '2026-04-01';
        $this->client->submit($form);

        self::assertResponseRedirects('/calendar/2026/1?focus=2026-01-07');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash', 'Ce plan est déjà dans ton agenda et à jour : aucune séance ajoutée.');
        self::assertCount(1, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    /**
     * « Placer dans mon agenda » depuis la page du plan : l'entrée du calendrier
     * reconduit le plan, la vue le présélectionne et fournit l'aperçu des dates
     * (décalages des cases depuis le lundi de la semaine 1, déjà placé ou non).
     */
    public function testPlaceParameterPreselectsThePlanWithItsPreview(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 2);
        foreach ([[2, 1], [1, 3]] as [$week, $day]) {
            $item = (new PlanItem())->setWeekNumber($week)->setDayOfWeek($day)->setWorkout($workout);
            $template->addPlanItem($item);
            $this->em->persist($item);
        }
        $this->em->flush();
        $id = (int) $template->getId();

        $this->client->loginUser($user);
        $this->client->request('GET', '/calendar?place='.$id);
        $now = new \DateTimeImmutable('today');
        self::assertResponseRedirects(sprintf('/calendar/%d/%d?place=%d', $now->format('Y'), $now->format('n'), $id));

        $crawler = $this->client->followRedirect();
        $wrapper = $crawler->filter('[data-controller="caladd"]');
        self::assertSame((string) $id, $wrapper->attr('data-caladd-preselect-value'));

        $plans = json_decode((string) $crawler->filter('form[action$="/schedule/plan"]')->attr('data-plan-preview-plans-value'), true);
        // Mercredi S1 = 2, lundi S2 = 7 : triés, quelle que soit l'ordre des cases.
        self::assertSame(['offsets' => [2, 7], 'placed' => false], $plans[$id]);

        // Un plan qui n'est pas à moi n'est jamais présélectionné.
        $other = $this->createPlanTemplate($this->createUser('other@example.com'), 'Pas à moi', 1);
        $crawler = $this->client->request('GET', sprintf('/calendar/2026/1?place=%d', $other->getId()));
        self::assertSame('0', $crawler->filter('[data-controller="caladd"]')->attr('data-caladd-preselect-value'));
    }

    public function testMoveChangesDate(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        // On soumet le vrai formulaire de déplacement rendu (token + session).
        $form = $crawler->filter('form[action*="/move"]')->form();
        $form['scheduledDate'] = '2026-03-20';
        $this->client->submit($form);

        $this->em->clear();
        $moved = $this->em->getRepository(ScheduledWorkout::class)->find($scheduled->getId());
        self::assertNotNull($moved);
        self::assertSame('2026-03-20', $moved->getScheduledDate()->format('Y-m-d'));
        self::assertResponseRedirects('/calendar/2026/3');
    }

    public function testDeleteRemovesScheduledWorkout(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));
        $id = $scheduled->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        $form = $crawler->filter('form[action*="/delete"]')->form();
        $this->client->submit($form);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ScheduledWorkout::class)->find($id));
    }

    public function testScheduledShowRendersWorkoutInItsDatedContext(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sortie longue', $crawler->html());
        // La date est ce qui distingue cette page de app_workout_show.
        self::assertSelectorTextContains('.kd-wk__date', 'Dimanche 15 mars 2026');
        // Le hero se dit « au calendrier » : ce n'est pas la séance type (#91).
        self::assertSelectorTextContains('.kd-wk__eyebrow', 'Séance au calendrier');
        // Le bilan en tête, avec le lien vers la séance type.
        self::assertSelectorTextContains('#schedule-summary', 'Prévue');
        self::assertSelectorExists('#schedule-summary a[href^="/workout/'.$workout->getId().'"]');
        // Les trois statuts au choix, plus seulement la bascule prévue / faite.
        self::assertSelectorCount(3, '#schedule-done input[type="radio"][name="status"]');
        self::assertSelectorExists('#schedule-done input[name="status"][value="missed"]');
        self::assertSelectorTextContains('.kd-done', 'Enregistrer');
    }

    public function testScheduledShowDeniedToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $workout = $this->createWorkout($owner, 'Sortie longue');
        $scheduled = $this->createScheduled($owner, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFormSavesAnyStatusWithItsCommentAndStaysOnThePage(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));
        $id = $scheduled->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$id);

        $this->client->submit($crawler->filter('.kd-done__form')->form([
            'status' => 'done',
            'completionNotes' => 'Dos raide, allure tenue.',
        ]));

        // `return=schedule` : on reste sur la séance au lieu d'être renvoyé au
        // calendrier, sinon enregistrer ferait perdre la page qu'on lisait.
        self::assertResponseRedirects('/schedule/'.$id);

        $this->em->clear();
        self::assertSame(ScheduledStatus::DONE, $this->mustFind(ScheduledWorkout::class, $id)->getStatus());

        // Le commentaire se lit dans le bilan, plus seulement dans la zone de saisie.
        $this->client->followRedirect();
        self::assertSelectorTextContains('#schedule-summary', 'Dos raide, allure tenue.');
        self::assertSelectorTextContains('#schedule-summary', 'Faite');

        // « Manquée » se choisit ici aussi, plus seulement au calendrier.
        $crawler = $this->client->request('GET', '/schedule/'.$id);
        $this->client->submit($crawler->filter('.kd-done__form')->form(['status' => 'missed']));

        $this->em->clear();
        self::assertSame(ScheduledStatus::MISSED, $this->mustFind(ScheduledWorkout::class, $id)->getStatus());
        self::assertSame('Dos raide, allure tenue.', $this->mustFind(ScheduledWorkout::class, $id)->getCompletionNotes());
    }

    /**
     * Avec JS, Turbo poste en demandant un stream. La réponse doit viser les
     * fragments **de cette page** : viser `#cal-event-{id}` (la pastille de
     * calendrier, absente ici) ne remplace rien, et le statut ne bougeait à
     * l'écran qu'après un rechargement manuel.
     */
    public function testDoneButtonAnswersWithAStreamTargetingThisPage(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));
        $id = $scheduled->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$id);

        $this->client->request('POST', '/schedule/'.$id.'/status', [
            '_token' => $crawler->filter('.kd-done__form input[name="_token"]')->attr('value'),
            'return' => 'schedule',
            'status' => 'done',
            'completionNotes' => '',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('target="schedule-badge"', $html);
        self::assertStringContainsString('target="schedule-done"', $html);
        self::assertStringContainsString('target="schedule-summary"', $html);
        self::assertStringNotContainsString('cal-event-'.$id, $html);
        // Le fragment renvoyé porte bien le nouvel état, pas l'ancien.
        self::assertStringContainsString('value="done" checked', $html);
        self::assertStringContainsString('kd-sumcard__status--done', $html);
    }

    public function testCalendarEventLinksToTheDatedPage(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        // Au doigt, la pastille doit mener quelque part sans JS : c'est un lien,
        // pas un bouton de modale. Le href porte en plus son ancre de contexte
        // (`?from=`), d'où le sélecteur par préfixe.
        $target = '/schedule/'.$scheduled->getId();
        self::assertCount(2, $crawler->filter('a.kd-calevent__open[href^="'.$target.'"], a.kd-calevent__eye[href^="'.$target.'"]'));

        // Le mois **affiché**, pas celui de la séance : la grille déborde sur ses
        // voisins, et une pastille datée du mois précédent renverrait ailleurs.
        // Trois liens : le titre, l'œil et « Voir la séance » dans la modale,
        // qui ouvre la séance datée et plus la séance type (#91).
        self::assertCount(3, $crawler->filter('a[href="'.$target.'?from=cal-month-2026-03"]'));
        self::assertCount(1, $crawler->filter('dialog a[href="'.$target.'?from=cal-month-2026-03"]'));
        self::assertCount(0, $crawler->filter('dialog a[href^="/workout/"]'));
    }

    /**
     * La chaîne « calendrier → séance datée → compositeur » : le retour doit
     * ramener au calendrier d'où l'on vient, y compris après le second saut. Sans
     * la reconduite du jeton, le compositeur retombait sur « Mes séances ».
     */
    public function testTheCalendarContextSurvivesToTheComposer(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId().'?from=cal-month-2026-03');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-wk__back[href="/calendar/2026/3"]'));

        $editLink = '/workout/'.$workout->getId().'/edit?from=cal-month-2026-03';
        self::assertCount(1, $crawler->filter('a[href="'.$editLink.'"]'));

        $crawler = $this->client->request('GET', $editLink);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-backlink[href="/calendar/2026/3"]'));
    }

    /**
     * Un jeton illisible n'est pas une erreur : la page se rend, avec le retour
     * qu'elle avait avant cette fonctionnalité. C'est le pire cas assumé, et il
     * arrive tout seul (URL tronquée, recopiée, vieillie).
     */
    public function testAnUnreadableContextFallsBackInsteadOfBreaking(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId().'?from=cal-month-2026-13');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-wk__back[href="/calendar"]'));
    }

    /**
     * L'historique s'ouvre depuis les deux vues du calendrier, et son retour
     * repart à la vue quittée — pas au profil, où il est rangé. Sans jeton, ou
     * avec un jeton illisible, il retombe sur le profil.
     */
    public function testTheHistoryIsReachableFromTheCalendarAndLeadsBackToIt(): void
    {
        $this->client->loginUser($this->createUser('owner@example.com'));

        $crawler = $this->client->request('GET', '/calendar/2026/3');
        self::assertCount(1, $crawler->filter('a[href="/profile/history?from=cal-month-2026-03"]'));

        $crawler = $this->client->request('GET', '/calendar/week/2026-03-18');
        self::assertCount(1, $crawler->filter('a[href="/profile/history?from=cal-week-2026-03-16"]'));

        $crawler = $this->client->request('GET', '/profile/history?from=cal-month-2026-03');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.kd-btn[href="/calendar/2026/3"]'));

        $crawler = $this->client->request('GET', '/profile/history?from=cal-week-2026-03-16');
        self::assertCount(1, $crawler->filter('a.kd-btn[href="/calendar/week/2026-03-16"]'));

        foreach (['', '?from=cal-month-2026-13'] as $query) {
            $crawler = $this->client->request('GET', '/profile/history'.$query);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('a.kd-btn[href="/profile"]'), 'Sans contexte lisible, retour au profil.');
        }
    }

    public function testMoveDeniedToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $workout = $this->createWorkout($owner, 'Sortie longue');
        $scheduled = $this->createScheduled($owner, $workout, new \DateTimeImmutable('2026-03-15'));

        // Le voter tranche avant la vérification CSRF : un token bidon suffit à
        // prouver le refus d'accès (403) sur une séance qui n'est pas la sienne.
        $this->client->loginUser($intruder);
        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/move', [
            '_token' => 'invalid',
            'scheduledDate' => '2026-03-20',
        ]);

        self::assertResponseStatusCodeSame(403);
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

    private function createScheduled(User $owner, Workout $workout, \DateTimeImmutable $date): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::PLANNED);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
