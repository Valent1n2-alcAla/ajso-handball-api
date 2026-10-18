<?php

namespace App\DataFixtures;

use App\Entity\Actualite;
use App\Entity\Club;
use App\Entity\Evenement;
use App\Entity\Partenaire;
use App\Entity\Produit;
use App\Entity\Tarif;
use App\Service\CompositionsImporter;
use App\Service\EquipesImporter;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de démarrage. Club et équipes réels (équipes, compétitions, salles et créneaux lus dans
 * docs/equipes-2026-2027.csv) ; tarifs, partenaires, actualités, événements et produits sont des exemples
 * à remplacer depuis l'espace d'administration. Matchs et classements : import FFHandball.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly EquipesImporter $equipes,
        private readonly CompositionsImporter $compositions,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $club = (new Club())
            ->setNom('AJS Ouistreham Handball')
            ->setAdresse('Avenue du Général Leclerc, 14150 Ouistreham')
            ->setContact('Cyril Lioult')
            ->setPresident('Cyril Lioult')
            ->setEmail('ajso.handball@gmail.com')
            ->setLabel('Label Argent FFHandball')
            ->setPresentation("Club de handball de Ouistreham Riva-Bella, l'AJSO accueille petits et grands, du Baby Hand aux seniors, en loisir comme en compétition. Convivialité, formation et esprit d'équipe : bienvenue chez les Ratons !");
        $manager->persist($club);

        // Équipes, compétitions, salles et créneaux de la saison : tableau du club
        $resultat = $this->equipes->importer(__DIR__.'/../../docs/equipes-2026-2027.csv');
        foreach ($resultat['avertissements'] as $a) {
            echo "  ! $a\n";
        }
        $manager->flush();

        // Joueurs des compositions de match (rattachées aux matchs après l'import FFHB : app:import-compositions)
        $this->compositions->importer(__DIR__.'/../../docs/compositions-2026-2027.csv');

        foreach ([
            ['Baby Hand', '2021 – 2022', 90], ['Premiers Pas / -9 ans', '2018 – 2020', 110],
            ['-11 à -17 ans', '2009 – 2017', 130], ['Seniors', '2008 et avant', 160], ['Loisirs', 'Adultes', 100],
        ] as $i => [$cat, $annees, $prix]) {
            $manager->persist((new Tarif())->setCategorie($cat)->setAnneesNaissance($annees)->setPrix($prix)->setOrdre($i)
                ->setDetails('Licence FFHandball et assurance incluses'));
        }

        foreach ([['Ville de Ouistreham Riva-Bella', 'officiel', 'https://ouistreham-rivabella.fr'], ['Comité du Calvados de Handball', 'officiel', null]] as $i => [$nom, $niveau, $url]) {
            $manager->persist((new Partenaire())->setNom($nom)->setNiveau($niveau)->setUrl($url)->setOrdre($i));
        }

        foreach ([
            ['Reprise de la saison 2026-2027', "Les entraînements reprennent pour toutes les catégories. Venez essayer : deux séances d'essai gratuites !", '-10 days'],
            ['Belle victoire des Séniors A', "Belle entame de championnat pour notre équipe fanion qui s'impose 28-24 au COSEC devant un public nombreux.", '-20 days'],
            ['Tournoi Baby Hand', "Nos plus jeunes licenciés ont participé à leur premier plateau de la saison. Bravo à tous !", '-35 days'],
        ] as [$titre, $contenu, $quand]) {
            $manager->persist((new Actualite())->setTitre($titre)->setContenu($contenu)->setExtrait($contenu)->setDatePub(new \DateTime($quand)));
        }

        foreach ([
            ['Tournoi de Noël des jeunes', 'tournoi', '2026-12-13 09:00', '2026-12-13 17:00', 'COSEC Maurice Chevalier', "Plateaux -9 à -13 ans, buvette et tombola au profit de l'école de hand."],
            ['Stage des vacances de février', 'stage', '2027-02-15 10:00', '2027-02-17 16:00', 'COSEC Maurice Chevalier', 'Trois jours de hand et de jeux pour les -11 et -13 ans.'],
            ['Assemblée générale', 'assemblee', '2027-06-20 18:30', null, 'Salle des fêtes de Ouistreham', 'Bilan de la saison et élection du bureau.'],
        ] as [$titre, $type, $debut, $fin, $lieu, $desc]) {
            $manager->persist((new Evenement())->setTitre($titre)->setType($type)->setDebut(new \DateTime($debut))
                ->setFin($fin ? new \DateTime($fin) : null)->setLieu($lieu)->setDescription($desc));
        }

        foreach ([
            ['Maillot domicile AJSO', 35.0, 'XS à XXL', 'Le maillot officiel des Ratons, floqué au dos sur demande.'],
            ['Sweat à capuche club', 30.0, '6 ans à XXL', 'Sweat bleu nuit, logo raton brodé.'],
            ['Gourde AJSO', 8.5, null, 'Gourde inox 50 cl.'],
        ] as $i => [$nom, $prix, $tailles, $desc]) {
            $manager->persist((new Produit())->setNom($nom)->setPrix($prix)->setTailles($tailles)->setDescription($desc)->setOrdre($i));
        }

        $manager->flush();
    }
}
