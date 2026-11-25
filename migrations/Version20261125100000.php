<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le cardio se prescrit en durée ou distance, intensité et allure. Ni séries, ni
 * repos, ni D+ : le fractionné devient des tours de bloc, et ce qui ne se
 * convertit pas passe dans les notes de l'exercice, pour ne rien perdre.
 *
 * 1. Exercice cardio seul dans son bloc, avec des séries : les séries passent
 *    dans les tours du bloc (« 6 × 400 m » = bloc à 6 tours de 400 m).
 * 2. Séries restantes (bloc partagé) : « À répéter N fois. » en note.
 * 3. Repos et D+ : « Récup 1:30. », « D+ 100 m. » en note.
 */
final class Version20261125100000 extends AbstractMigration
{
    private const string CARDIO = "pe.prescription_type IN ('distance_pace', 'duration')";

    public function getDescription(): string
    {
        return 'Cardio : séries en tours de bloc, repos et D+ en notes, colonne elevation_gain_meters retirée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE block b
            JOIN prescribed_exercise pe ON pe.block_id = b.id
            SET b.rounds = b.rounds * pe.sets
            WHERE '.self::CARDIO.' AND pe.sets > 1 AND '.self::alone('b.id').' = 1');

        $this->addSql("UPDATE prescribed_exercise pe
            SET pe.notes = CONCAT_WS('\\n', CONCAT('À répéter ', pe.sets, ' fois.'), NULLIF(pe.notes, ''))
            WHERE ".self::CARDIO.' AND pe.sets > 1 AND '.self::alone('pe.block_id').' > 1');

        $this->addSql("UPDATE prescribed_exercise pe
            SET pe.notes = CONCAT_WS('\\n', NULLIF(pe.notes, ''), CONCAT('Récup ', FLOOR(pe.rest_seconds / 60), ':', LPAD(pe.rest_seconds % 60, 2, '0'), '.'))
            WHERE ".self::CARDIO.' AND pe.rest_seconds > 0');

        $this->addSql("UPDATE prescribed_exercise pe
            SET pe.notes = CONCAT_WS('\\n', NULLIF(pe.notes, ''), CONCAT('D+ ', pe.elevation_gain_meters, ' m.'))
            WHERE pe.elevation_gain_meters IS NOT NULL");

        $this->addSql('UPDATE prescribed_exercise pe SET pe.sets = NULL, pe.rest_seconds = NULL WHERE '.self::CARDIO);
        $this->addSql('ALTER TABLE prescribed_exercise DROP elevation_gain_meters');
    }

    /**
     * Nombre d'exercices du bloc. La table dérivée contourne l'interdiction de
     * MariaDB de relire la table qu'on met à jour.
     */
    private static function alone(string $blockId): string
    {
        return '(SELECT COUNT(*) FROM (SELECT block_id FROM prescribed_exercise) o WHERE o.block_id = '.$blockId.')';
    }

    public function down(Schema $schema): void
    {
        // La colonne revient vide : séries, repos et D+ sont dans les notes et
        // les tours de bloc, on ne les en extrait pas.
        $this->addSql('ALTER TABLE prescribed_exercise ADD elevation_gain_meters INT DEFAULT NULL');
    }
}
