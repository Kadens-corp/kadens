<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Service\ShareLink;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Page publique d'une séance. Depuis #61, elle n'existe que si son propriétaire a
 * ouvert le partage par lien : l'adresse porte alors un jeton aléatoire, qui vaut
 * à lui seul droit de lecture (et de copie, #58).
 */
final class PublicShareControllerTest extends WebTestCase
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

    // ---- Privé par défaut ------------------------------------------------------

    /**
     * Ni l'ancienne adresse tirée du titre, ni aucune autre : une séance jamais
     * partagée n'a pas de page publique.
     */
    public function testAWorkoutHasNoPublicPageUntilItsOwnerSharesIt(): void
    {
        $owner = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($owner, 'Séance jambes');

        self::assertNull($workout->getShareToken());
        $this->client->request('GET', '/s/'.$workout->getSlug());
        self::assertResponseStatusCodeSame(404);

        $token = $this->share($workout);
        $this->client->request('GET', '/s/'.$token);
        self::assertResponseIsSuccessful();
    }

    public function testPublicPageIsAccessibleAnonymouslyAndReadOnly(): void
    {
        $owner = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($owner, 'Squat');
        $workout = $this->createWorkout($owner, 'Séance jambes');

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $prescribed = (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(4)->setReps(8)->setWeightKg(60.0);
        $block->addPrescribedExercise($prescribed);
        $workout->addBlock($block);
        $this->em->persist($block);
        $this->em->flush();

        // Aucune authentification : la page publique doit répondre en 200.
        $crawler = $this->client->request('GET', '/s/'.$this->share($workout));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Squat');
        // Même rendu que la page privée : une ligne par série...
        self::assertSelectorTextContains('.kd-exrow__flag', '4 séries');
        self::assertCount(4, $crawler->filter('.kd-settable tbody tr'));
        // ...mais un bandeau dit qu'elle est publique (A-44).
        self::assertSelectorTextContains('.kd-publicbanner', 'Page publique');
        // Aucune action d'édition sur la page publique.
        self::assertSelectorNotExists('a[href$="/edit"]');
    }

    public function testUnknownTokenReturns404(): void
    {
        $this->client->request('GET', '/s/'.bin2hex(random_bytes(16)));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Arrêter le partage efface le jeton : l'adresse déjà envoyée tombe sur
     * EXACTEMENT ce que rend une adresse inventée. Rien ne confirme au visiteur
     * qu'une séance a existé derrière.
     */
    public function testAStoppedShareAnswersExactlyLikeAnUnknownToken(): void
    {
        $owner = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($owner, 'Jambes');

        $this->client->loginUser($owner);
        $this->toggle($workout, 'open');
        $this->em->clear();
        $token = (string) $this->mustFind(Workout::class, $workout->getId())->getShareToken();
        $this->client->request('GET', '/s/'.$token);
        self::assertResponseIsSuccessful();

        $this->toggle($workout, 'close');
        self::assertResponseRedirects('/workout/'.$workout->getId());
        $this->em->clear();
        self::assertNull($this->mustFind(Workout::class, $workout->getId())->getShareToken());

        $stopped = $this->notFoundFor('/s/'.$token);
        $unknown = $this->notFoundFor('/s/'.bin2hex(random_bytes(16)));
        self::assertSame($unknown->getStatusCode(), $stopped->getStatusCode());
        self::assertSame($unknown->getMessage(), $stopped->getMessage());
        self::assertSame($unknown->getHeaders(), $stopped->getHeaders());

        // La copie depuis le lien suit le même jeton : plus rien à copier.
        $this->client->request('GET', '/workout/shared/'.$token.'/copy');
        self::assertResponseStatusCodeSame(404);
    }

    /** Repartager tire un nouveau lien : l'ancien, révoqué, ne revient jamais. */
    public function testSharingAgainDrawsANewLink(): void
    {
        $owner = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($owner, 'Jambes');

        $this->client->loginUser($owner);
        $this->toggle($workout, 'open');
        $this->em->clear();
        $first = $this->mustFind(Workout::class, $workout->getId())->getShareToken();

        $this->toggle($workout, 'close');
        $this->toggle($workout, 'open');
        $this->em->clear();
        $second = $this->mustFind(Workout::class, $workout->getId())->getShareToken();

        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        $this->client->request('GET', '/s/'.$first);
        self::assertResponseStatusCodeSame(404);
    }

    // ---- Le propriétaire seul -------------------------------------------------

    /**
     * Le coach co-édite la séance de son athlète, il ne la publie pas (C-15) :
     * pas de bandeau de partage chez lui, et l'endpoint le refuse.
     */
    public function testTheCoachCannotShareHisAthleteWorkout(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->em->persist(
            (new Coaching())
                ->setCoach($coach)
                ->setAthlete($athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($coach)
                ->setRespondedAt(new \DateTimeImmutable())
        );
        $workout = $this->createWorkout($athlete, 'Jambes');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-sharelink');

        $this->client->request('POST', '/workout/'.$workout->getId().'/share', ['_token' => 'x', 'action' => 'open']);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->mustFind(Workout::class, $workout->getId())->getShareToken());
    }

    /** Une case de plan n'a jamais de lien à elle : elle se lit sous celui du plan. */
    public function testAPlanCellIsNeverSharedOnItsOwn(): void
    {
        $owner = $this->createUser('owner@example.com');
        $cell = $this->createWorkout($owner, 'Jambes S2')->setPlanLocal(true);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/workout/'.$cell->getId());
        self::assertSelectorExists('.kd-sharelink--note');
        self::assertSelectorNotExists('.kd-sharelink form');

        $this->client->request('POST', '/workout/'.$cell->getId().'/share', ['_token' => 'x', 'action' => 'open']);
        self::assertResponseStatusCodeSame(403);

        $this->expectException(\LogicException::class);
        (new ShareLink())->open($cell);
    }

    // ---- Gestes du visiteur (#58) ---------------------------------------------

    /**
     * Le visiteur anonyme peut renvoyer le lien (une seule action, qui copie), et
     * se voit proposer de se connecter pour copier — jamais un formulaire qui
     * partirait sans compte.
     */
    public function testAnonymousVisitorCanSendTheLinkAndIsInvitedToLogInToCopy(): void
    {
        $owner = $this->createUser('owner@example.com');
        $token = $this->share($this->createWorkout($owner, 'Jambes'));

        $crawler = $this->client->request('GET', '/s/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button[data-action="clipboard#copy"]', 'Copier le lien à envoyer');
        self::assertStringEndsWith('/s/'.$token, (string) $crawler->filter('.kd-wk__bar')->attr('data-clipboard-url-value'));
        self::assertSelectorNotExists('[data-action="clipboard#share"]');
        self::assertSelectorExists('a[href="/workout/shared/'.$token.'/copy"]');
        self::assertSelectorNotExists('#wk-copy-shared');
        self::assertSelectorNotExists('a[href$="/edit"]');
    }

    /**
     * L'entrée anonyme passe par le login, qui le ramène ici, puis on le repose
     * sur la page publique : aucune copie ne part d'un GET.
     */
    public function testCopyEntryGoesThroughLoginThenBackToThePublicPage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $reader = $this->createUser('reader@example.com');
        $token = $this->share($this->createWorkout($owner, 'Jambes'));
        $entry = '/workout/shared/'.$token.'/copy';

        $this->client->request('GET', $entry);
        self::assertResponseRedirects('/login');

        $this->client->loginUser($reader);
        $this->client->request('GET', $entry);
        self::assertResponseRedirects('/s/'.$token);
        self::assertCount(1, $this->em->getRepository(Workout::class)->findAll());
    }

    public function testLoggedInReaderCopiesTheWorkoutIntoHisLibrary(): void
    {
        $owner = $this->createUser('owner@example.com');
        $reader = $this->createUser('reader@example.com');
        $exercise = $this->createExercise($owner, 'Tirage serré');
        $workout = $this->createWorkoutWith($owner, 'Séance dos', $exercise);

        $this->client->loginUser($reader);
        $crawler = $this->client->request('GET', '/s/'.$this->share($workout));
        self::assertSelectorExists('button[form="wk-copy-shared"]');
        $this->client->submit($crawler->filter('#wk-copy-shared')->form());

        $copy = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $reader]);
        self::assertNotNull($copy);
        self::assertSame('Séance dos', $copy->getTitle());
        self::assertResponseRedirects('/workout/'.$copy->getId());
        // La copie arrive privée : le partage de l'auteur ne la suit pas.
        self::assertNull($copy->getShareToken());

        // L'exercice perso de l'auteur a été recopié chez le lecteur...
        $copied = $this->em->getRepository(Exercise::class)->findOneBy(['owner' => $reader]);
        self::assertNotNull($copied);
        self::assertSame('Tirage serré', $copied->getName());

        // ...donc l'auteur peut toujours supprimer le sien : aucune référence
        // croisée ne le retient (FK RESTRICT sur prescribed_exercise.exercise_id).
        // Gestionnaire vidé d'abord : la copie chargée pointe la source par
        // `copiedFrom`, que la base remet à NULL, pas la mémoire du test.
        $this->em->clear();
        $workout = $this->em->find(Workout::class, $workout->getId());
        self::assertNotNull($workout);
        $this->em->remove($workout);
        $this->em->flush();
        $authorExercise = $this->em->find(Exercise::class, $exercise->getId());
        self::assertNotNull($authorExercise);
        $this->em->remove($authorExercise);
        $this->em->flush();
        self::assertNull($this->em->find(Exercise::class, $exercise->getId()));
    }

    /** Sur sa propre séance, rien à copier : on renvoie à la fiche. */
    public function testOwnerIsSentToHisOwnWorkoutInsteadOfCopying(): void
    {
        $owner = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($owner, 'Jambes');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/s/'.$this->share($workout));

        self::assertSelectorExists('a[href="/workout/'.$workout->getId().'"]');
        self::assertSelectorNotExists('#wk-copy-shared');
        self::assertSelectorTextContains('.kd-publicbanner', 'ce que voient les personnes à qui tu envoies le lien');
    }

    public function testCopyWithoutValidTokenCopiesNothing(): void
    {
        $owner = $this->createUser('owner@example.com');
        $reader = $this->createUser('reader@example.com');
        $token = $this->share($this->createWorkout($owner, 'Jambes'));

        $this->client->loginUser($reader);
        $this->client->request('POST', '/workout/shared/'.$token.'/copy', ['_token' => 'invalide']);

        self::assertResponseRedirects('/s/'.$token);
        self::assertCount(1, $this->em->getRepository(Workout::class)->findAll());
    }

    // ---- Helpers --------------------------------------------------------------

    /** Ouvre le partage comme le fait le service, et rend le jeton. */
    private function share(Workout $workout): string
    {
        (new ShareLink())->open($workout);
        $this->em->flush();

        return (string) $workout->getShareToken();
    }

    /** Ouvre ou arrête le partage par le bandeau de la fiche, comme le propriétaire. */
    private function toggle(Workout $workout, string $action): void
    {
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId());
        $form = $crawler->filter('.kd-sharelink form input[name="action"][value="'.$action.'"]')->closest('form');
        self::assertNotNull($form, sprintf('Pas de formulaire « %s » sur la fiche.', $action));
        $this->client->submit($form->form());
    }

    /** L'exception que lève une adresse publique, telle que le noyau la reçoit. */
    private function notFoundFor(string $url): NotFoundHttpException
    {
        $this->client->catchExceptions(false);
        try {
            $this->client->request('GET', $url);
        } catch (NotFoundHttpException $exception) {
            return $exception;
        } finally {
            $this->client->catchExceptions(true);
        }

        self::fail(sprintf('%s devait rendre 404.', $url));
    }

    private function createWorkoutWith(User $owner, string $title, Exercise $exercise): Workout
    {
        $workout = $this->createWorkout($owner, $title);
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(10)
        );
        $workout->addBlock($block);
        $this->em->persist($block);
        $this->em->flush();

        return $workout;
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
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
}
