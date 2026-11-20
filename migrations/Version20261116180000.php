<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partage par lien (#61) : `share_token` sur les séances et les plans. Null =
 * privé, et c'est l'état de TOUT l'existant à l'arrivée de la colonne : décision
 * prise avec le ticket, personne n'avait consenti à publier, et rien en base ne
 * dit quels liens avaient vraiment été envoyés. Les anciennes adresses
 * `/s/{slug}` rendent donc 404 ; repartager se fait en un clic, sous un nouveau
 * lien non devinable.
 */
final class Version20261116180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'workout.share_token et plan_template.share_token (partage par lien, privé par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD share_token VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_649FFB72D6594DD6 ON workout (share_token)');
        $this->addSql('ALTER TABLE plan_template ADD share_token VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_990528F9D6594DD6 ON plan_template (share_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_649FFB72D6594DD6 ON workout');
        $this->addSql('ALTER TABLE workout DROP share_token');
        $this->addSql('DROP INDEX UNIQ_990528F9D6594DD6 ON plan_template');
        $this->addSql('ALTER TABLE plan_template DROP share_token');
    }
}
