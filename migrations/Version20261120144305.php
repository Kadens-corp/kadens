<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages programmés : où le message s'affiche (site, appli ou les deux).
 * Les messages existants gardent leur comportement, les deux.
 */
final class Version20261120144305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'custom_message.platform';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_message ADD platform VARCHAR(10) DEFAULT \'both\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_message DROP platform');
    }
}
