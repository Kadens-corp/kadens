<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Date de fin d'une relation de coaching (#81), pour la ligne « Anciens
 * athlètes » du coach.
 *
 * Rattrapage des relations déjà terminées : leur dernière mise à jour. Une ligne
 * ENDED n'est plus modifiée après « Terminer » (une réouverture la repasse en
 * PENDING), donc `updated_at` est bien le moment de la fin. À défaut, la date de
 * réponse, puis celle de la demande : une borne basse plutôt que rien.
 */
final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coaching.ended_at (date de fin, anciens athlètes du coach)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching ADD ended_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE coaching SET ended_at = COALESCE(updated_at, responded_at, created_at) WHERE status = 'ended'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching DROP ended_at');
    }
}
