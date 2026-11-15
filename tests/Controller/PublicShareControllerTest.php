<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicShareControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

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
        $crawler = $this->client->request('GET', '/s/'.$workout->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Squat');
        // Même rendu que la page privée : une ligne par série.
        self::assertSelectorTextContains('.kd-exrow__flag', '4 séries');
        self::assertCount(4, $crawler->filter('.kd-settable tbody tr'));
        // Aucune action d'édition sur la page publique.
        self::assertSelectorNotExists('a[href$="/edit"]');
    }

    public function testUnknownSlugReturns404(): void
    {
        $this->client->request('GET', '/s/slug-inexistant');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * #58 : le visiteur anonyme peut repartager le lien, et se voit proposer de
     * se connecter pour copier — jamais un formulaire qui partirait sans compte.
     */
    public function testAnonymousVisitorCanShareAndIsInvitedToLogInToCopy(): void
    {
        $owner = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($owner, 'Jambes');

        $this->client->request('GET', '/s/'.$workout->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[data-action="clipboard#share"]');
        self::assertSelectorExists('a[href="/workout/shared/'.$workout->getSlug().'/copy"]');
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
        $workout = $this->createWorkout($owner, 'Jambes');
        $entry = '/workout/shared/'.$workout->getSlug().'/copy';

        $this->client->request('GET', $entry);
        self::assertResponseRedirects('/login');

        $this->client->loginUser($reader);
        $this->client->request('GET', $entry);
        self::assertResponseRedirects('/s/'.$workout->getSlug());
        self::assertCount(1, $this->em->getRepository(Workout::class)->findAll());
    }

    public function testLoggedInReaderCopiesTheWorkoutIntoHisLibrary(): void
    {
        $owner = $this->createUser('owner@example.com');
        $reader = $this->createUser('reader@example.com');
        $exercise = $this->createExercise($owner, 'Tirage serré');
        $workout = $this->createWorkoutWith($owner, 'Séance dos', $exercise);

        $this->client->loginUser($reader);
        $crawler = $this->client->request('GET', '/s/'.$workout->getSlug());
        self::assertSelectorExists('button[form="wk-copy-shared"]');
        $this->client->submit($crawler->filter('#wk-copy-shared')->form());

        $copy = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $reader]);
        self::assertNotNull($copy);
        self::assertSame('Séance dos', $copy->getTitle());
        self::assertResponseRedirects('/workout/'.$copy->getId());

        // L'exercice perso de l'auteur a été recopié chez le lecteur...
        $copied = $this->em->getRepository(Exercise::class)->findOneBy(['owner' => $reader]);
        self::assertNotNull($copied);
        self::assertSame('Tirage serré', $copied->getName());

        // ...donc l'auteur peut toujours supprimer le sien : aucune référence
        // croisée ne le retient (FK RESTRICT sur prescribed_exercise.exercise_id).
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
        $this->client->request('GET', '/s/'.$workout->getSlug());

        self::assertSelectorExists('a[href="/workout/'.$workout->getId().'"]');
        self::assertSelectorNotExists('#wk-copy-shared');
    }

    public function testCopyWithoutValidTokenCopiesNothing(): void
    {
        $owner = $this->createUser('owner@example.com');
        $reader = $this->createUser('reader@example.com');
        $workout = $this->createWorkout($owner, 'Jambes');

        $this->client->loginUser($reader);
        $this->client->request('POST', '/workout/shared/'.$workout->getSlug().'/copy', ['_token' => 'invalide']);

        self::assertResponseRedirects('/s/'.$workout->getSlug());
        self::assertCount(1, $this->em->getRepository(Workout::class)->findAll());
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

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
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
