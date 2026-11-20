<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:user:reset-password` : ce que fait l'administrateur quand « Mot de passe
 * oublié ? » renvoie vers lui (#99). Le nouveau mot de passe remplace l'ancien,
 * avec la même longueur minimale qu'à la création.
 */
final class ResetPasswordCommandTest extends KernelTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->hasher = static::getContainer()->get('security.user_password_hasher');

        $this->purgeDatabase($this->em);
    }

    public function testItReplacesThePassword(): void
    {
        $user = $this->createUser('oubli@example.com', 'ancien-mdp');

        $tester = $this->launch(['email' => 'oubli@example.com', 'password' => 'nouveau-mdp']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->em->clear();
        $reloaded = $this->mustFind(User::class, (int) $user->getId());
        self::assertTrue($this->hasher->isPasswordValid($reloaded, 'nouveau-mdp'));
        self::assertFalse($this->hasher->isPasswordValid($reloaded, 'ancien-mdp'));
    }

    public function testATooShortPasswordIsRefused(): void
    {
        $user = $this->createUser('oubli@example.com', 'ancien-mdp');

        self::assertSame(Command::INVALID, $this->launch(['email' => 'oubli@example.com', 'password' => 'court'])->getStatusCode());

        $this->em->clear();
        self::assertTrue($this->hasher->isPasswordValid($this->mustFind(User::class, (int) $user->getId()), 'ancien-mdp'));
    }

    public function testAnUnknownEmailFails(): void
    {
        self::assertSame(Command::FAILURE, $this->launch(['email' => 'personne@example.com', 'password' => 'nouveau-mdp'])->getStatusCode());
    }

    private function createUser(string $email, string $password): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, string> $input
     */
    private function launch(array $input): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:user:reset-password'));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
