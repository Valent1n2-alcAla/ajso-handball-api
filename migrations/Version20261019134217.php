<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019134217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Joueur : autorisation photo ; adversaire et gymnase non supprimables s\'ils sont utilisés ; index rencontre (date, fdm_code)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creneau DROP CONSTRAINT fk_f9668b5ff4f4ddd0');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT FK_F9668B5FF4F4DDD0 FOREIGN KEY (gymnase_id) REFERENCES gymnase (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE joueur ADD photo_autorisee BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT fk_460c35ed28c25bf2');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED28C25BF2 FOREIGN KEY (equipe_adverse_id) REFERENCES equipe (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_rencontre_date ON rencontre (date)');
        $this->addSql('CREATE INDEX idx_rencontre_fdm_code ON rencontre (fdm_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creneau DROP CONSTRAINT FK_F9668B5FF4F4DDD0');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT fk_f9668b5ff4f4ddd0 FOREIGN KEY (gymnase_id) REFERENCES gymnase (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE joueur DROP photo_autorisee');
        $this->addSql('ALTER TABLE rencontre DROP CONSTRAINT FK_460C35ED28C25BF2');
        $this->addSql('DROP INDEX idx_rencontre_date');
        $this->addSql('DROP INDEX idx_rencontre_fdm_code');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT fk_460c35ed28c25bf2 FOREIGN KEY (equipe_adverse_id) REFERENCES equipe (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
