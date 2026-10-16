<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016150213 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Matchs : horaire facultatif, date au week-end près, journée';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rencontre ADD date_confirmee BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE rencontre ADD journee VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ALTER heure DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rencontre DROP date_confirmee');
        $this->addSql('ALTER TABLE rencontre DROP journee');
        $this->addSql('ALTER TABLE rencontre ALTER heure SET NOT NULL');
    }
}
