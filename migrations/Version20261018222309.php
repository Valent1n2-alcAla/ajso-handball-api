<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018222309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Joueur : créé depuis une feuille de match';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE joueur ADD cree_par_feuille BOOLEAN DEFAULT false NOT NULL');
        // Joueurs déjà créés par une feuille de match lue (avant ce marqueur)
        $this->addSql('UPDATE joueur SET cree_par_feuille = true WHERE id IN (SELECT p.joueur_id FROM participation p JOIN rencontre r ON r.id = p.rencontre_id WHERE r.feuille_lue = true)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE joueur DROP cree_par_feuille');
    }
}
