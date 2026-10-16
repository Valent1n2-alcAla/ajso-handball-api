<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016195959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compétitions multiples par équipe, type de compétition, entente, compétition des matchs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE equipe_competition (equipe_id INT NOT NULL, championnat_id INT NOT NULL, PRIMARY KEY (equipe_id, championnat_id))');
        $this->addSql('CREATE INDEX IDX_7F9ADD616D861B89 ON equipe_competition (equipe_id)');
        $this->addSql('CREATE INDEX IDX_7F9ADD61627A0DA8 ON equipe_competition (championnat_id)');
        $this->addSql('ALTER TABLE equipe_competition ADD CONSTRAINT FK_7F9ADD616D861B89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipe_competition ADD CONSTRAINT FK_7F9ADD61627A0DA8 FOREIGN KEY (championnat_id) REFERENCES championnat (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE championnat ADD type VARCHAR(20) DEFAULT \'championnat\' NOT NULL');
        $this->addSql('ALTER TABLE equipe ADD entente VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD championnat_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED627A0DA8 FOREIGN KEY (championnat_id) REFERENCES championnat (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_460C35ED627A0DA8 ON rencontre (championnat_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipe_competition DROP CONSTRAINT FK_7F9ADD616D861B89');
        $this->addSql('ALTER TABLE equipe_competition DROP CONSTRAINT FK_7F9ADD61627A0DA8');
        $this->addSql('DROP TABLE equipe_competition');
        $this->addSql('ALTER TABLE championnat DROP type');
        $this->addSql('ALTER TABLE equipe DROP entente');
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT FK_460C35ED627A0DA8');
        $this->addSql('DROP INDEX IDX_460C35ED627A0DA8');
        $this->addSql('ALTER TABLE rencontre DROP championnat_id');
    }
}
