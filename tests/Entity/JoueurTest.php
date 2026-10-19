<?php

namespace App\Tests\Entity;

use App\Entity\Categorie;
use App\Entity\Equipe;
use App\Entity\Joueur;
use App\Entity\Tranche;
use PHPUnit\Framework\TestCase;

class JoueurTest extends TestCase
{
    private static function joueur(?int $ageMin, string $prenom = 'Lucas', string $nom = 'Royer-Cenard'): Joueur
    {
        $equipe = new Equipe();
        if ($ageMin !== null) {
            $equipe->setCategorie((new Categorie())->setNom('Cat')->setTranche((new Tranche())->setNom('T')->setAgeMin($ageMin)->setAgeMax($ageMin + 2)));
        }

        return (new Joueur())->setEquipe($equipe)->setPrenom($prenom)->setNom($nom);
    }

    public function testNomCompletPourLesAdultes(): void
    {
        self::assertSame('Lucas Royer-Cenard', self::joueur(18)->getNomAffiche());
    }

    public function testInitialePourLesJeunes(): void
    {
        self::assertSame('Lucas R.', self::joueur(11)->getNomAffiche());
        self::assertSame('Léa É.', self::joueur(15, 'Léa', 'émery')->getNomAffiche());
    }

    public function testSansCategorieTraiteCommeMineur(): void
    {
        self::assertSame('Lucas R.', self::joueur(null)->getNomAffiche());
    }

    public function testNomVide(): void
    {
        self::assertSame('Lucas', self::joueur(11, 'Lucas', ' ')->getNomAffiche());
    }

    public function testPhotoPublique(): void
    {
        self::assertSame('/a.jpg', self::joueur(18)->setPhoto('/a.jpg')->getPhotoPublique());
        self::assertNull(self::joueur(13)->setPhoto('/a.jpg')->getPhotoPublique());
        self::assertSame('/a.jpg', self::joueur(13)->setPhoto('/a.jpg')->setPhotoAutorisee(true)->getPhotoPublique());
    }
}
