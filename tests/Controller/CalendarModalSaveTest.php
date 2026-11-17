<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * La modale d'une séance au calendrier (#90) : statut, commentaire et ressenti
 * partent ensemble par un bouton « Enregistrer » explicite. Avant, c'était le
 * clic sur « Faite » qui enregistrait le texte, et « Déplacer », seul bouton
 * plein, se lisait comme la sauvegarde. Ce que ces tests tiennent : le statut se
 * **choisit** (radio) sans rien envoyer, et enregistrer sans y toucher garde le
 * statut courant.
 */
final class CalendarModalSaveTest extends WebTestCase
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

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    /** « Statut » nomme les trois choix, qui sont des radios ; un seul bouton plein, « Enregistrer ». */
    public function testTheModalSeparatesChoosingFromSaving(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);

        $this->client->request('GET', '/calendar/2026/3');

        $form = '#cal-event-'.$scheduled->getId().' dialog .kd-modal__form';
        $group = $form.' [role="radiogroup"]';
        self::assertSelectorTextContains('#'.$this->attr($group, 'aria-labelledby'), 'Statut');
        self::assertSelectorCount(3, $group.' input[type="radio"][name="status"]');
        self::assertSelectorExists($group.' input[value="planned"][checked]');
        self::assertSelectorNotExists($form.' button[name="status"]');

        self::assertSelectorCount(1, $form.' button[type="submit"]');
        self::assertSelectorTextContains($form.' button[type="submit"].kd-btn--primary', 'Enregistrer');
        // Hors l'encart caché de la garde, qui répète « Enregistrer ».
        self::assertSelectorCount(1, '#cal-event-'.$scheduled->getId().' dialog .kd-modal__section .kd-btn--primary');
        self::assertSelectorNotExists('#cal-event-'.$scheduled->getId().' dialog .kd-btn--secondary');
    }

    /**
     * Fermer avec une saisie en cours (#90) : la question vit dans la modale,
     * cachée tant que le contrôleur `dialog` ne la montre pas, et son
     * « Enregistrer » soumet le formulaire surveillé par son id, sans JS.
     */
    public function testTheModalCarriesItsUnsavedWarning(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $this->client->request('GET', '/calendar/2026/3');

        $dialog = '#cal-event-'.$id.' dialog';
        self::assertSame('click->dialog#backdrop cancel->dialog#cancel close->dialog#closed', $this->attr($dialog, 'data-action'));
        self::assertSelectorExists($dialog.' form#cal-save-'.$id.'[data-dialog-target="guarded"]');

        $warning = $dialog.' [data-dialog-target="unsaved"]';
        self::assertSelectorExists($warning.'[hidden][role="alert"]');
        self::assertSelectorTextContains($warning, 'Tes modifications ne sont pas enregistrées.');
        self::assertSelectorTextContains($warning.' button[type="submit"][form="cal-save-'.$id.'"]', 'Enregistrer');
        self::assertSelectorTextContains($warning.' button[data-action="dialog#discard"]', 'Ne pas enregistrer');

        // Déplacer recharge la vue : il passe par la même garde. Retirer, non.
        self::assertSelectorExists($dialog.' form[action$="/move"][data-action="submit->dialog#guardSubmit"]');
        self::assertSelectorNotExists($dialog.' form[action$="/delete"][data-action]');
    }

    /** Enregistrer sans toucher au statut garde le statut, et écrit texte et ressenti. */
    public function testSavingKeepsTheCurrentStatus(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $form = $this->modalForm($id);
        $form['completionNotes'] = 'Dos raide';
        $this->choice($form, 'feeling')->select('4');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $id);
        self::assertSame(ScheduledStatus::DONE, $stored->getStatus());
        self::assertSame('Dos raide', $stored->getCompletionNotes());
        self::assertSame(4, $stored->getFeeling());
    }

    /** Choisir « Faite » puis enregistrer pose le statut et le commentaire d'un coup. */
    public function testChoosingAStatusThenSavingSetsBoth(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createScheduled($owner, ScheduledStatus::PLANNED);
        $this->client->loginUser($owner);
        $id = (int) $scheduled->getId();

        $form = $this->modalForm($id);
        $this->choice($form, 'status')->select('done');
        $form['completionNotes'] = 'Charge revue à la baisse';
        $this->client->submit($form);

        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $id);
        self::assertSame(ScheduledStatus::DONE, $stored->getStatus());
        self::assertSame('Charge revue à la baisse', $stored->getCompletionNotes());
    }

    private function modalForm(int $id): Form
    {
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        return $crawler->filter('#cal-event-'.$id.' dialog .kd-modal__form button[type="submit"]')->form();
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function attr(string $selector, string $name): string
    {
        return (string) $this->client->getCrawler()->filter($selector)->attr($name);
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createScheduled(User $owner, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-15'))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
