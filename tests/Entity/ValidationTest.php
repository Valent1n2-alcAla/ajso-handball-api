<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\Document;
use App\Entity\Permanence;
use App\Entity\Preinscription;
use App\Entity\Rencontre;
use App\Entity\Tarif;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ValidationTest extends TestCase
{
    private static function validateur(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /** @return list<string> propriétés en erreur */
    private static function erreurs(object $objet): array
    {
        $champs = [];
        foreach (self::validateur()->validate($objet) as $v) {
            $champs[] = $v->getPropertyPath();
        }

        return array_values(array_unique($champs));
    }

    private static function preinscription(): Preinscription
    {
        return (new Preinscription())->setPrenom('Léa')->setNom('Martin')->setDateNaissance(new \DateTime('2015-03-02'))
            ->setEmail('parent@exemple.fr')->setTelephone('06 12 34 56 78');
    }

    public function testPreinscriptionValide(): void
    {
        self::assertSame([], self::erreurs(self::preinscription()->setSexe('F')->setCategorieSouhaitee('-13 filles')));
    }

    public function testPreinscriptionInvalide(): void
    {
        $p = self::preinscription()->setEmail('pas-un-email')->setTelephone('<script>')->setSexe('X')
            ->setDateNaissance(new \DateTime('+1 day'))->setMessage(str_repeat('a', 2001))->setNom(str_repeat('b', 81));

        self::assertEqualsCanonicalizing(['email', 'telephone', 'sexe', 'dateNaissance', 'message', 'nom'], self::erreurs($p));
    }

    public function testPreinscriptionChampsObligatoires(): void
    {
        self::assertEqualsCanonicalizing(['prenom', 'nom', 'dateNaissance', 'email', 'telephone'], self::erreurs(new Preinscription()));
    }

    public function testPermanence(): void
    {
        $p = (new Permanence())->setRencontre(new Rencontre())->setRole('buvette')->setBenevole('  ');
        self::assertSame([], self::erreurs($p));
        self::assertNull($p->getBenevole()); // vide = à pourvoir
        self::assertSame(1, $p->getBesoin());

        self::assertEqualsCanonicalizing(['role', 'besoin'], self::erreurs($p->setRole('cuisine')->setBesoin(0)));
    }

    public function testDocument(): void
    {
        $d = (new Document())->setTitre('Dossier d\'inscription')->setUrl('http://localhost:8000/uploads/2026/10/a.pdf');
        self::assertSame([], self::erreurs($d));
        self::assertEqualsCanonicalizing(['categorie', 'url'], self::erreurs($d->setCategorie('divers')->setUrl('javascript:alert(1)')));
    }

    public function testHelloassoEnHttps(): void
    {
        $tarif = (new Tarif())->setCategorie('-11')->setPrix(120);
        self::assertSame([], self::erreurs($tarif->setHelloassoUrl('https://www.helloasso.com/associations/ajso/adhesions/2026')));
        self::assertSame(['helloassoUrl'], self::erreurs($tarif->setHelloassoUrl('http://www.helloasso.com/x')));
        self::assertNull($tarif->setHelloassoUrl('')->getHelloassoUrl());

        $club = (new Club())->setNom('AJSO')->setAdresse('Ouistreham')->setContact('Bureau');
        self::assertSame(['helloassoUrl'], self::erreurs($club->setHelloassoUrl('ftp://helloasso.com')));
    }
}
