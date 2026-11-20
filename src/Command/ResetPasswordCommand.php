<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Redéfinit le mot de passe d'un compte existant. Sans réinitialisation par
 * email, c'est ce que fait l'administrateur quand quelqu'un a oublié le sien
 * (« Mot de passe oublié ? » sur /login renvoie vers lui). L'intéressé le
 * change ensuite depuis ses paramètres.
 *
 * Même saisie masquée et même longueur minimale que `app:user:create`.
 */
#[AsCommand(
    name: 'app:user:reset-password',
    description: "Redéfinit le mot de passe d'un User (identifié par son email).",
)]
final class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte')
            ->addArgument(
                'password',
                InputArgument::OPTIONAL,
                'Nouveau mot de passe en clair (demandé en saisie masquée si omis)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim((string) $input->getArgument('email'));
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('Aucun User trouvé pour l\'email : %s', $email));

            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if (null === $password) {
            $password = $this->askPassword($io, $input);
            if (null === $password) {
                return Command::INVALID;
            }
        }

        if (\strlen((string) $password) < CreateUserCommand::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe doit faire au moins %d caractères.', CreateUserCommand::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));
        $this->em->flush();

        $io->success(sprintf('Mot de passe redéfini pour %s. Les cookies « Se souvenir de moi » existants ne valent plus.', $email));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io, InputInterface $input): ?string
    {
        if (!$input->isInteractive()) {
            $io->error('Mode non interactif : passe le mot de passe en second argument.');

            return null;
        }

        $password = (string) $io->askHidden('Nouveau mot de passe');
        if ($password !== (string) $io->askHidden('Répéter le mot de passe')) {
            $io->error('Les deux saisies ne correspondent pas.');

            return null;
        }

        return $password;
    }
}
