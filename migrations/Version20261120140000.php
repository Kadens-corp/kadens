<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Images d'exercice (#38) : la liste ordonnée des fichiers qui montrent le
 * mouvement. Null = aucune image, l'état de tous les exercices existants.
 */
final class Version20261120140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'exercise.images (#38)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise ADD images JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise DROP images');
    }
}
