<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\ActivityType;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un message flash ne déplace jamais la page (#129). Il se rend en toast, dans
 * la pile fixe en bas à droite que pose `base.html.twig` (`.kd-toasts`), avec la
 * pastille « Enregistré » : plus aucun message en tête de <main>, qui poussait
 * le contenu vers le bas.
 */
final class FlashToastTest extends WebTestCase
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

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    public function testSuccessIsAToastOutsideTheContent(): void
    {
        $this->client->loginUser($this->createUser('owner@example.com'));

        $this->client->request('GET', '/exercise/new');
        $this->client->submitForm('Créer', [
            'exercise[name]' => 'Fentes',
            'exercise[activity]' => ActivityType::GYM->value,
        ]);
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.kd-toasts .kd-flash', 'Exercice créé.');
        // S'efface seul : un succès n'a pas à être fermé.
        self::assertSame('6000', $crawler->filter('.kd-toasts .kd-flash')->attr('data-toast-hide-after-value'));
        self::assertCount(0, $crawler->filter('main .kd-flash'));
    }

    public function testErrorIsAToastThatStays(): void
    {
        $this->client->loginUser($this->createUser('coach@example.com', ['ROLE_COACH']));

        $crawler = $this->client->request('GET', '/coaching');
        $token = $crawler->filter('form[action$="/coaching/request"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/coaching/request', ['_token' => $token, 'email' => 'pas-un-email']);
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('.kd-toasts .kd-flash--error'));
        // Pas de minuteur : un refus qui disparaît avant d'être lu n'a rien dit.
        self::assertNull($crawler->filter('.kd-toasts .kd-flash--error')->attr('data-toast-hide-after-value'));
        self::assertCount(0, $crawler->filter('main .kd-flash'));
    }

    /** Une seule pile : le toast et la pastille s'empilent au lieu de se recouvrir. */
    public function testSaveStatusSharesTheStack(): void
    {
        $this->client->loginUser($this->createUser('owner@example.com'));

        $crawler = $this->client->request('GET', '/exercise');

        self::assertCount(1, $crawler->filter('.kd-toasts > .kd-savestatus'));
        self::assertCount(1, $crawler->filter('.kd-savestatus:not(.kd-savestatus--inline)'));
    }

    /**
     * Garde-fou : `components/_flash_toasts` est le seul lecteur des flashes de
     * succès et d'erreur. Un template qui y bouclerait de nouveau consommerait le
     * message avant la pile, et le rendrait en tête de page.
     */
    public function testNoTemplateReadsTheFlashesItself(): void
    {
        $readers = [];
        $finder = (new Finder())->files()->in(\dirname(__DIR__, 2).'/templates')->name('*.twig');
        foreach ($finder as $file) {
            if (preg_match("/app\.flashes\(\s*(?:'(?:success|error)'\s*)?\)/", $file->getContents())) {
                $readers[] = $file->getRelativePathname();
            }
        }

        self::assertSame(['components/_flash_toasts.html.twig'], $readers);
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
}
