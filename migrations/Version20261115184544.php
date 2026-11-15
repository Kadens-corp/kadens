<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partage de séances (#58) : `workout.copied_from_id` relie la copie donnée à un
 * autre utilisateur à la séance d'origine. Sert à reposer la même copie quand le
 * coach repose sa séance dans le plan d'un athlète.
 *
 * SET NULL : la copie appartient à quelqu'un d'autre, supprimer l'original ne la
 * touche pas, et l'auteur n'est jamais retenu par une référence croisée.
 */
final class Version20261115184544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'workout.copied_from_id (copie donnée à un autre utilisateur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD copied_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD CONSTRAINT FK_649FFB7258B20D94 FOREIGN KEY (copied_from_id) REFERENCES workout (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_649FFB7258B20D94 ON workout (copied_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout DROP FOREIGN KEY FK_649FFB7258B20D94');
        $this->addSql('DROP INDEX IDX_649FFB7258B20D94 ON workout');
        $this->addSql('ALTER TABLE workout DROP copied_from_id');
    }
}
