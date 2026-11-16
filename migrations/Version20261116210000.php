<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réponse vue par l'initiateur d'une demande de coaching (#79). Les réponses
 * déjà données sont marquées vues à leur date : sans quoi chaque relation
 * existante ferait surgir un bandeau « X a accepté ton invitation » à la mise
 * en production.
 */
final class Version20261116210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coaching.response_seen_at (bandeau de réponse chez l\'initiateur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching ADD response_seen_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE coaching SET response_seen_at = responded_at WHERE responded_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching DROP response_seen_at');
    }
}
