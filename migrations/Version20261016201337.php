<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016201337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression d\'une équipe : ses matchs sont supprimés avec elle';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT fk_460c35ed6d861b89');
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT fk_460c35ed28c25bf2');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED6D861B89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED28C25BF2 FOREIGN KEY (equipe_adverse_id) REFERENCES equipe (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT FK_460C35ED6D861B89');
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT FK_460C35ED28C25BF2');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT fk_460c35ed6d861b89 FOREIGN KEY (equipe_id) REFERENCES equipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT fk_460c35ed28c25bf2 FOREIGN KEY (equipe_adverse_id) REFERENCES equipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
