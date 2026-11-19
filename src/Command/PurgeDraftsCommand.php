<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DraftPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les brouillons de séance et de plan abandonnés (#115). En prod, le
 * même ménage passe par `/cron/drafts`, que le planificateur Infomaniak appelle ;
 * la commande sert en dev et pour un ménage à la main.
 */
#[AsCommand(
    name: 'app:drafts:purge',
    description: 'Supprime les brouillons de séance et de plan créés puis abandonnés sans contenu.',
)]
final class PurgeDraftsCommand extends Command
{
    public function __construct(
        private readonly DraftPurger $purger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'hours',
            null,
            InputOption::VALUE_REQUIRED,
            'Délai de grâce en heures (un brouillon plus récent est gardé)',
            (string) DraftPurger::DEFAULT_GRACE_HOURS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $hours = (int) $input->getOption('hours');

        if ($hours < 0) {
            $io->error('Le délai de grâce ne peut pas être négatif.');

            return Command::INVALID;
        }

        ['workouts' => $workouts, 'plans' => $plans] = $this->purger->purge($hours);

        if (0 === $workouts + $plans) {
            $io->info('Aucun brouillon abandonné.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d séance(s) et %d plan(s) supprimé(s).', $workouts, $plans));

        return Command::SUCCESS;
    }
}
