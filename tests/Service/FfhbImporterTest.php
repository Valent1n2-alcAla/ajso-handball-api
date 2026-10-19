<?php

namespace App\Tests\Service;

use App\Service\FfhbImporter;
use PHPUnit\Framework\TestCase;

class FfhbImporterTest extends TestCase
{
    /** Feuille fictive : 2 joueurs recevants (32 buts), 2 visiteurs (28 buts) */
    private static function feuille(?string $recevant, ?string $visiteur): array
    {
        return [
            'recevant' => [['buts' => 20], ['buts' => 12]],
            'visiteur' => [['buts' => 18], ['buts' => 10]],
            'noms' => ['recevant' => $recevant, 'visiteur' => $visiteur],
        ];
    }

    public function testNormaliser(): void
    {
        self::assertSame('ENT COTE DE NACRE', FfhbImporter::normaliser('  Ent. Côte-de-Nacre '));
    }

    public function testCoteParLeNom(): void
    {
        $nous = FfhbImporter::normaliser('AJS OUISTREHAM HANDBALL 2');
        // À domicile d'après le calendrier, mais la feuille nous place en visiteur : le nom l'emporte
        self::assertSame('visiteur', FfhbImporter::choisirCote(self::feuille('PL AVRANCHES', 'AJS OUISTREHAM HANDBALL 2'), true, 28, $nous));
        // Libellé de la feuille un peu différent (sans numéro, mots dans un autre ordre)
        self::assertSame('recevant', FfhbImporter::choisirCote(self::feuille('OUISTREHAM AJS HB', 'PL AVRANCHES 2'), false, null, $nous));
    }

    public function testRepliSurLesButs(): void
    {
        $nous = FfhbImporter::normaliser('AJS OUISTREHAM HANDBALL');
        // Noms absents : côté du calendrier
        self::assertSame('recevant', FfhbImporter::choisirCote(self::feuille(null, null), true, 32, $nous));
        // … corrigé par le total des buts
        self::assertSame('visiteur', FfhbImporter::choisirCote(self::feuille(null, null), true, 28, $nous));
        // Score inconnu ou ne correspondant à aucun côté : côté du calendrier
        self::assertSame('visiteur', FfhbImporter::choisirCote(self::feuille(null, null), false, null, $nous));
        self::assertSame('recevant', FfhbImporter::choisirCote(self::feuille(null, null), true, 30, $nous));
    }

    public function testNomsAmbigusOuInconnus(): void
    {
        $nous = FfhbImporter::normaliser('AJS OUISTREHAM HANDBALL 2');
        // Derby AJSO 1 - AJSO 2 sans numéro sur la feuille : les deux côtés correspondent, on se fie aux buts
        self::assertSame('visiteur', FfhbImporter::choisirCote(self::feuille('AJS OUISTREHAM HANDBALL', 'AJS OUISTREHAM HANDBALL'), true, 28, $nous));
        // Aucun des deux noms ne correspond
        self::assertSame('recevant', FfhbImporter::choisirCote(self::feuille('CAEN HB', 'PL AVRANCHES'), true, 32, $nous));
    }

    public function testCoteSansJoueurIgnore(): void
    {
        $nous = FfhbImporter::normaliser('AJS OUISTREHAM HANDBALL');
        $feuille = self::feuille('PL AVRANCHES', 'AJS OUISTREHAM HANDBALL');
        $feuille['visiteur'] = [];
        self::assertSame('recevant', FfhbImporter::choisirCote($feuille, true, 32, $nous));
    }
}
