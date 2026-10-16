<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire les données d'exemple des premières versions (équipes, adversaires, championnats, catégories fictifs).
 *
 * Garde-fous : une équipe n'est supprimée que si elle n'a aucun match importé de ffhandball.fr et, pour les
 * équipes du club, aucun nom FFHandball renseigné ; championnats et catégories seulement s'ils ne servent plus.
 * Les matchs, créneaux et joueurs d'exemple partent avec leur équipe (suppression en cascade).
 */
final class Version20261017090000 extends AbstractMigration
{
    private const ADVERSAIRES = ['JS Douvres', 'Caen HB', 'HBC Bayeux', 'Mondeville HB', 'Hérouville HB', 'Lisieux HB', 'Ouistreham 1'];
    private const EQUIPES_EXEMPLE = ['Seniors M1', 'Seniors M2', 'Seniors M3', '-17 ans M', '-15 ans F', '-13 ans M', '-11 ans Mixte', 'Premiers Pas', 'Baby Hand', 'AJSO Seniors 1'];
    private const CHAMPIONNATS = ['1ère Division Masculine', '2ème Division Masculine', '3ème Division Masculine', 'Championnat Jeunes Calvados', 'Départemental 1'];
    private const CATEGORIES = ['Baby Hand', 'Premiers Pas', '-11 ans Mixte', '-13 ans Masculins', '-15 ans Féminines', '-17 ans Masculins', 'Seniors Masculins', 'Seniors Féminines'];

    public function getDescription(): string
    {
        return 'Suppression des équipes, adversaires, championnats et catégories d\'exemple';
    }

    public function up(Schema $schema): void
    {
        $sansImport = 'NOT EXISTS (SELECT 1 FROM rencontre r WHERE (r.equipe_id = e.id OR r.equipe_adverse_id = e.id) AND r.ffhb_id IS NOT NULL)';

        $this->addSql("DELETE FROM equipe e WHERE e.est_ajso = false AND e.nom IN ({$this->liste(self::ADVERSAIRES)}) AND $sansImport");
        $this->addSql("DELETE FROM equipe e WHERE e.est_ajso = true AND (e.nom_ffhb IS NULL OR e.nom_ffhb = '') AND e.nom IN ({$this->liste(self::EQUIPES_EXEMPLE)}) AND $sansImport");
        // Matchs d'exemple restants entre équipes réelles (aucun : ils impliquaient tous un adversaire fictif) — rien à faire.

        $this->addSql("DELETE FROM championnat c WHERE c.nom IN ({$this->liste(self::CHAMPIONNATS)})
            AND NOT EXISTS (SELECT 1 FROM equipe e WHERE e.championnat_id = c.id)
            AND NOT EXISTS (SELECT 1 FROM equipe_competition ec WHERE ec.championnat_id = c.id)
            AND NOT EXISTS (SELECT 1 FROM rencontre r WHERE r.championnat_id = c.id)");
        $this->addSql("DELETE FROM categorie c WHERE c.nom IN ({$this->liste(self::CATEGORIES)})
            AND NOT EXISTS (SELECT 1 FROM equipe e WHERE e.categorie_id = c.id)");
    }

    public function down(Schema $schema): void
    {
        // Données d'exemple : pas de restauration.
    }

    /** @param list<string> $noms */
    private function liste(array $noms): string
    {
        return implode(', ', array_map(fn (string $n) => $this->connection->quote($n), $noms));
    }
}
