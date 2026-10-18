<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018220139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feuilles de match : code FDME des rencontres, statistiques détaillées des joueurs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE participation ADD buts7m INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE participation ADD tirs INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE participation ADD arrets INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE participation ADD exclusions INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE participation ADD avertissement BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE participation ADD disqualification BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE rencontre ADD fdm_code VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE participation DROP buts7m');
        $this->addSql('ALTER TABLE participation DROP tirs');
        $this->addSql('ALTER TABLE participation DROP arrets');
        $this->addSql('ALTER TABLE participation DROP exclusions');
        $this->addSql('ALTER TABLE participation DROP avertissement');
        $this->addSql('ALTER TABLE participation DROP disqualification');
        $this->addSql('ALTER TABLE rencontre DROP fdm_code');
    }
}
