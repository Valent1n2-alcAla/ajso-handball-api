<?php

namespace App\DataFixtures;

use App\Entity\Actualite;
use App\Entity\Categorie;
use App\Entity\Championnat;
use App\Entity\Club;
use App\Entity\Creneau;
use App\Entity\Equipe;
use App\Entity\Evenement;
use App\Entity\Gymnase;
use App\Entity\Joueur;
use App\Entity\Partenaire;
use App\Entity\Produit;
use App\Entity\Rencontre;
use App\Entity\Tarif;
use App\Entity\Tranche;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de démarrage. Club, dirigeants, gymnases et équipes proviennent des informations publiques
 * du club (saison 2025-2026) ; matchs, créneaux, tarifs et partenaires sont des exemples à remplacer
 * depuis l'espace d'administration.
 */
class AppFixtures extends Fixture
{
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

        // Tranches / catégories
        $tranches = [];
        foreach ([['École de hand', 3, 10], ['Jeunes', 11, 17], ['Adultes', 18, 99]] as [$nom, $min, $max]) {
            $tranches[$nom] = (new Tranche())->setNom($nom)->setAgeMin($min)->setAgeMax($max);
            $manager->persist($tranches[$nom]);
        }
        $categories = [];
        foreach ([
            ['Baby Hand', 'École de hand'], ['Premiers Pas', 'École de hand'], ['-11 ans Mixte', 'École de hand'],
            ['-13 ans Masculins', 'Jeunes'], ['-15 ans Féminines', 'Jeunes'], ['-17 ans Masculins', 'Jeunes'],
            ['Seniors Masculins', 'Adultes'], ['Seniors Féminines', 'Adultes'],
        ] as [$nom, $tranche]) {
            $categories[$nom] = (new Categorie())->setNom($nom)->setTranche($tranches[$tranche]);
            $manager->persist($categories[$nom]);
        }

        $championnats = [];
        foreach ([
            'D1' => ['1ère Division Masculine', 'Départemental'],
            'D2' => ['2ème Division Masculine', 'Départemental'],
            'D3' => ['3ème Division Masculine', 'Départemental'],
            'J' => ['Championnat Jeunes Calvados', 'Départemental'],
        ] as $code => [$nom, $niveau]) {
            $championnats[$code] = (new Championnat())->setNom($nom)->setNiveau($niveau);
            $manager->persist($championnats[$code]);
        }

        $equipes = [];
        $ordre = 0;
        foreach ([
            ['Seniors M1', 'Seniors Masculins', 'D1', 'Équipe fanion du club, engagée en 1ère Division du Comité du Calvados.'],
            ['Seniors M2', 'Seniors Masculins', 'D2', null],
            ['Seniors M3', 'Seniors Masculins', 'D3', null],
            ['-17 ans M', '-17 ans Masculins', 'J', null],
            ['-15 ans F', '-15 ans Féminines', 'J', null],
            ['-13 ans M', '-13 ans Masculins', 'J', null],
            ['-11 ans Mixte', '-11 ans Mixte', 'J', null],
            ['Premiers Pas', 'Premiers Pas', null, 'Découverte du handball par le jeu.'],
            ['Baby Hand', 'Baby Hand', null, 'Éveil moteur et premiers ballons pour les plus petits.'],
        ] as [$nom, $cat, $champ, $desc]) {
            $equipes[$nom] = (new Equipe())
                ->setNom($nom)
                ->setEstAjso(true)
                ->setOrdre($ordre++)
                ->setCategorie($categories[$cat])
                ->setChampionnat($champ ? $championnats[$champ] : null)
                ->setDescription($desc);
            $manager->persist($equipes[$nom]);
        }

        $adversaires = [];
        foreach (['JS Douvres', 'Caen HB', 'HBC Bayeux', 'Mondeville HB', 'Hérouville HB', 'Lisieux HB'] as $nom) {
            $adversaires[$nom] = (new Equipe())->setNom($nom)->setEstAjso(false)->setCategorie($categories['Seniors Masculins']);
            $manager->persist($adversaires[$nom]);
        }

        $cosec = (new Gymnase())->setNom('COSEC Maurice Chevalier')->setAdresse('Avenue du Général Leclerc')->setCodePostal('14150')->setVille('Ouistreham');
        $kieffer = (new Gymnase())->setNom('Salle Stade Kieffer')->setAdresse('85 avenue du Général Leclerc')->setCodePostal('14150')->setVille('Ouistreham');
        $manager->persist($cosec);
        $manager->persist($kieffer);

        // Matchs d'exemple : passés (avec score) et à venir
        foreach ([
            ['Seniors M1', 'JS Douvres', '2026-09-26', '20:30', true, 28, 24],
            ['Seniors M1', 'Caen HB', '2026-10-03', '20:00', false, 25, 25],
            ['Seniors M2', 'Mondeville HB', '2026-10-10', '18:00', true, 31, 22],
            ['-17 ans M', 'HBC Bayeux', '2026-10-11', '14:00', true, 22, 27],
            ['Seniors M1', 'HBC Bayeux', '2026-10-24', '20:30', true, null, null],
            ['Seniors M2', 'Hérouville HB', '2026-10-25', '16:00', false, null, null],
            ['-15 ans F', 'Lisieux HB', '2026-11-07', '14:00', true, null, null],
            ['Seniors M1', 'Mondeville HB', '2026-11-14', '20:30', false, null, null],
        ] as [$eq, $adv, $date, $heure, $dom, $sd, $se]) {
            $manager->persist((new Rencontre())
                ->setEquipe($equipes[$eq])
                ->setEquipeAdverse($adversaires[$adv])
                ->setDate(new \DateTime($date))
                ->setHeure(new \DateTime($heure))
                ->setDomicile($dom)
                ->setLieu($dom ? 'COSEC Maurice Chevalier, Ouistreham' : $adv)
                ->setScoreDom($sd)
                ->setScoreExt($se));
        }

        foreach ([
            ['Seniors M1', $cosec, 2, '20:00', '22:00'], ['Seniors M1', $cosec, 4, '20:00', '22:00'],
            ['Seniors M2', $cosec, 4, '20:00', '22:00'], ['Seniors M3', $kieffer, 3, '20:30', '22:00'],
            ['-17 ans M', $cosec, 3, '18:30', '20:00'], ['-15 ans F', $kieffer, 2, '18:00', '19:30'],
            ['-13 ans M', $kieffer, 1, '17:30', '19:00'], ['-11 ans Mixte', $cosec, 3, '17:00', '18:30'],
            ['Premiers Pas', $cosec, 6, '10:00', '11:00'], ['Baby Hand', $cosec, 6, '11:00', '12:00'],
        ] as [$eq, $gym, $jour, $debut, $fin]) {
            $manager->persist((new Creneau())
                ->setEquipe($equipes[$eq])
                ->setGymnase($gym)
                ->setJour($jour)
                ->setHeureDebut(new \DateTime($debut))
                ->setHeureFin(new \DateTime($fin)));
        }

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
            ['Victoire des Seniors M1 face à Douvres', "Belle entame de championnat pour notre équipe fanion qui s'impose 28-24 au COSEC devant un public nombreux.", '-20 days'],
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

        // Effectifs d'exemple (noms fictifs)
        foreach ([
            'Seniors M1' => [[1, 'Thomas', 'Lefèvre', 'gardien'], [16, 'Hugo', 'Marie', 'gardien'], [7, 'Maxime', 'Duval', 'ailier-gauche'],
                [9, 'Julien', 'Hébert', 'arriere-gauche', true], [11, 'Antoine', 'Lebrun', 'demi-centre'], [13, 'Nicolas', 'Guérin', 'pivot'],
                [21, 'Romain', 'Lecomte', 'arriere-droit'], [5, 'Kévin', 'Morel', 'ailier-droit'], [24, 'Florian', 'Roussel', 'polyvalent']],
            '-15 ans F' => [[1, 'Léa', 'Martin', 'gardien'], [4, 'Chloé', 'Bernard', 'demi-centre', true], [8, 'Inès', 'Petit', 'pivot'],
                [10, 'Manon', 'Durand', 'arriere-gauche'], [14, 'Jade', 'Leroy', 'ailier-droit']],
        ] as $eq => $liste) {
            foreach ($liste as $j) {
                $manager->persist((new Joueur())->setEquipe($equipes[$eq])->setNumero($j[0])->setPrenom($j[1])->setNom($j[2])
                    ->setPoste($j[3])->setCapitaine($j[4] ?? false));
            }
        }

        $manager->flush();
    }
}
