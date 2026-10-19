<?php

namespace App\Tests\Command;

use App\Command\ImportFfhbCommand;
use PHPUnit\Framework\TestCase;

class ImportFfhbCommandTest extends TestCase
{
    public function testResumeDesErreurs(): void
    {
        $texte = ImportFfhbCommand::resume(
            ['Séniors A — « AJS » introuvable dans la poule', '-15 M — délai dépassé'],
            ['equipes' => 3, 'crees' => 2, 'maj' => 10, 'retires' => 1, 'feuilles' => 4],
            4,
        );

        self::assertStringContainsString('3 équipe(s) sur 4', $texte);
        self::assertStringContainsString('2 créé(s), 10 mis à jour, 1 retiré(s) ; 4 feuille(s)', $texte);
        self::assertStringContainsString("- Séniors A — « AJS » introuvable dans la poule\n- -15 M — délai dépassé", $texte);
    }
}
