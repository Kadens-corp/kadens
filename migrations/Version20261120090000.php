<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Amis et flux de séances (#126) : la relation d'amitié, le réglage de partage
 * du compte (ouvert par défaut), la séance masquée pour ses amis, et le repère
 * de la séance d'ami dont une séance de bibliothèque est la copie.
 */
final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'friendship, user.share_with_friends, scheduled_workout.hidden_from_friends, workout.copied_from_session_id (#126)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE friendship (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, created_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, requester_id INT NOT NULL, addressee_id INT NOT NULL, INDEX IDX_7234A45FED442CF4 (requester_id), INDEX IDX_7234A45F2261B4C3 (addressee_id), INDEX IDX_FRIENDSHIP_ADDRESSEE_STATUS (addressee_id, status), UNIQUE INDEX UNIQ_FRIENDSHIP_PAIR (requester_id, addressee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45FED442CF4 FOREIGN KEY (requester_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45F2261B4C3 FOREIGN KEY (addressee_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_workout ADD hidden_from_friends TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user ADD share_with_friends TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE workout ADD copied_from_session_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD CONSTRAINT FK_649FFB725E96496D FOREIGN KEY (copied_from_session_id) REFERENCES scheduled_workout (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_649FFB725E96496D ON workout (copied_from_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE friendship DROP FOREIGN KEY FK_7234A45FED442CF4');
        $this->addSql('ALTER TABLE friendship DROP FOREIGN KEY FK_7234A45F2261B4C3');
        $this->addSql('DROP TABLE friendship');
        $this->addSql('ALTER TABLE scheduled_workout DROP hidden_from_friends');
        $this->addSql('ALTER TABLE user DROP share_with_friends');
        $this->addSql('ALTER TABLE workout DROP FOREIGN KEY FK_649FFB725E96496D');
        $this->addSql('DROP INDEX IDX_649FFB725E96496D ON workout');
        $this->addSql('ALTER TABLE workout DROP copied_from_session_id');
    }
}
