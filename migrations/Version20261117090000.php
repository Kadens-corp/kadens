<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Origine du contenu ajouté par un coach (#80) : `added_by_id` sur les séances,
 * les plans et les séances datées.
 *
 * Rattrapage limité à ce que les données disent sans ambiguïté : une séance
 * copiée (`copied_from_id`) depuis celle d'un coach qui suit, ou a suivi, son
 * propriétaire. Le reste (séances et plans créés vides, poses au calendrier,
 * plans donnés) n'a laissé aucune trace et reste sans badge : on n'invente pas
 * une origine.
 */
final class Version20261117090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'workout, plan_template, scheduled_workout : added_by_id (contenu ajouté par le coach)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD added_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD CONSTRAINT FK_649FFB7255B127A4 FOREIGN KEY (added_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_649FFB7255B127A4 ON workout (added_by_id)');

        $this->addSql('ALTER TABLE plan_template ADD added_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE plan_template ADD CONSTRAINT FK_990528F955B127A4 FOREIGN KEY (added_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_990528F955B127A4 ON plan_template (added_by_id)');

        $this->addSql('ALTER TABLE scheduled_workout ADD added_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD3655B127A4 FOREIGN KEY (added_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_58AD3655B127A4 ON scheduled_workout (added_by_id)');

        $this->addSql(<<<'SQL'
            UPDATE workout w
            INNER JOIN workout src ON src.id = w.copied_from_id
            INNER JOIN coaching c ON c.coach_id = src.owner_id AND c.athlete_id = w.owner_id
            SET w.added_by_id = src.owner_id
            WHERE c.status IN ('accepted', 'ended')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout DROP FOREIGN KEY FK_649FFB7255B127A4');
        $this->addSql('DROP INDEX IDX_649FFB7255B127A4 ON workout');
        $this->addSql('ALTER TABLE workout DROP added_by_id');

        $this->addSql('ALTER TABLE plan_template DROP FOREIGN KEY FK_990528F955B127A4');
        $this->addSql('DROP INDEX IDX_990528F955B127A4 ON plan_template');
        $this->addSql('ALTER TABLE plan_template DROP added_by_id');

        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD3655B127A4');
        $this->addSql('DROP INDEX IDX_58AD3655B127A4 ON scheduled_workout');
        $this->addSql('ALTER TABLE scheduled_workout DROP added_by_id');
    }
}
