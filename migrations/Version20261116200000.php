<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes privées du coach sur son athlète (#65). Sur la relation, pas sur le
 * contenu : une séance ou un plan appartient à l'athlète, qui voit tout ce
 * qu'ils portent.
 */
final class Version20261116200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coaching.coach_notes (notes privées du coach sur son athlète)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching ADD coach_notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching DROP coach_notes');
    }
}
