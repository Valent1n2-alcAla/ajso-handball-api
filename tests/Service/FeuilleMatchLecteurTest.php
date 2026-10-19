<?php

namespace App\Tests\Service;

use App\Service\FeuilleMatchLecteur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeuilleMatchLecteurTest extends TestCase
{
    public static function noms(): iterable
    {
        yield 'simple' => ['GAUCHER David', ['David', 'Gaucher']];
        yield 'nom composé' => ['ROYER--CENARD Lucas', ['Lucas', 'Royer-Cenard']];
        yield 'particule' => ['LE BEUVANT Julien', ['Julien', 'Le Beuvant']];
        yield 'prénom composé' => ['MARTIN Jean-Pierre', ['Jean-Pierre', 'Martin']];
        yield 'tout en majuscules' => ['DUPONT PAUL', ['Paul', 'Dupont']];
        yield 'espaces' => ['  DURAND   Léa ', ['Léa', 'Durand']];
        yield 'apostrophe' => ["D'ALMEIDA Émile", ['Émile', "D'Almeida"]];
    }

    #[DataProvider('noms')]
    public function testDecouper(string $nom, array $attendu): void
    {
        self::assertSame($attendu, FeuilleMatchLecteur::decouper($nom));
    }

    public static function feuilles(): iterable
    {
        // fichier => [joueurs, buts] recevant puis visiteur
        yield 'feuille standard' => ['test.pdf', [6, 32], [2, 28]];
        yield 'second en-tête non reconnu' => ['sans-entete2.pdf', [2, 20], [2, 20]];
        yield 'chiffrée AES-256 (flux d\'objets)' => ['enc-aes-256-objstm.pdf', [6, 32], [2, 28]];
    }

    #[DataProvider('feuilles')]
    public function testLire(string $fichier, array $recevant, array $visiteur): void
    {
        $feuille = (new FeuilleMatchLecteur())->lire((string) file_get_contents(__DIR__.'/../Fixtures/'.$fichier));

        foreach (['recevant' => $recevant, 'visiteur' => $visiteur] as $cote => [$joueurs, $buts]) {
            self::assertCount($joueurs, $feuille[$cote], $cote);
            self::assertSame($buts, array_sum(array_column($feuille[$cote], 'buts')), $cote);
            foreach ($feuille[$cote] as $j) {
                self::assertIsInt($j['numero']);
                self::assertNotSame('', $j['prenom']);
                self::assertArrayNotHasKey('licence', $j); // jamais conservée
            }
        }
    }

    public function testFeuilleIllisible(): void
    {
        $this->expectException(\Throwable::class);
        (new FeuilleMatchLecteur())->lire('pas un PDF');
    }
}
