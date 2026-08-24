<?php

namespace App\DataFixtures;

use App\Entity\Tranche;
use App\Entity\Categorie;
use App\Entity\Championnat;
use App\Entity\Equipe;
use App\Entity\Rencontre;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tranche = new Tranche();
        $tranche->setNom('Seniors');
        $tranche->setAgeMin(18);
        $tranche->setAgeMax(99);
        $manager->persist($tranche);

        $categorie = new Categorie();
        $categorie->setNom('Seniors Masculins');
        $categorie->setTranche($tranche);
        $manager->persist($categorie);

        $championnat = new Championnat();
        $championnat->setNom('Départemental 1');
        $championnat->setNiveau('Départemental');
        $manager->persist($championnat);

        $equipeAjso = new Equipe();
        $equipeAjso->setNom('AJSO Seniors 1');
        $equipeAjso->setCategorie($categorie);
        $equipeAjso->setChampionnat($championnat);
        $manager->persist($equipeAjso);

        $equipeAdverse = new Equipe();
        $equipeAdverse->setNom('Ouistreham 1');
        $equipeAdverse->setCategorie($categorie);
        $equipeAdverse->setChampionnat($championnat);
        $manager->persist($equipeAdverse);

        $rencontre = new Rencontre();
        $rencontre->setEquipe($equipeAjso);
        $rencontre->setEquipeAdverse($equipeAdverse);
        $rencontre->setDate(new \DateTime('2026-09-12'));
        $rencontre->setHeure(new \DateTime('20:30'));
        $rencontre->setLieu('Gymnase AJSO');
        $rencontre->setDomicile(true);
        $manager->persist($rencontre);

        $manager->flush();
    }
}