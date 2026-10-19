<?php

namespace App\Command;

use App\Service\Notificateur;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde de la base (pg_dump, format SQL, compressé en .sql.gz si zlib est disponible)
 * et des fichiers envoyés (public/uploads, en .zip si l'extension zip est disponible),
 * dans var/sauvegardes/AAAA-MM-JJ_HHMM*. Seules les N dernières sauvegardes sont gardées.
 *
 * pg_dump : PG_DUMP_PATH (défaut « pg_dump », cherché dans le PATH puis, sous Windows,
 * dans C:\Program Files\PostgreSQL\<version>\bin). Connexion lue dans DATABASE_URL ;
 * le mot de passe est transmis par la variable PGPASSWORD du seul processus pg_dump.
 *
 * Restauration : gunzip puis « psql -U postgres -d ajso_handball -f AAAA-MM-JJ_HHMM.sql » sur une base vide.
 * À planifier chaque nuit (scripts/taches-planifiees.ps1 sous Windows, cron sous Linux).
 */
#[AsCommand(name: 'app:sauvegarde', description: 'Sauvegarde la base (pg_dump) et les fichiers envoyés dans var/sauvegardes')]
class SauvegardeCommand extends Command
{
    private const MOTIF = '/^(\d{4}-\d{2}-\d{2}_\d{4})(?:\.sql|\.sql\.gz|_uploads\.zip)$/';

    public function __construct(
        private readonly Connection $connexion,
        private readonly LoggerInterface $sauvegardeLogger,
        private readonly Notificateur $notificateur,
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
        #[Autowire('%env(default::PG_DUMP_PATH)%')] private readonly ?string $pgDump = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('garder', null, InputOption::VALUE_REQUIRED, 'Nombre de sauvegardes conservées', '14')
            ->addOption('sans-fichiers', null, InputOption::VALUE_NONE, 'Ne sauvegarde pas public/uploads')
            ->addOption('dossier', null, InputOption::VALUE_REQUIRED, 'Dossier des sauvegardes', 'var/sauvegardes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $garder = $input->getOption('garder');
        if (!ctype_digit((string) $garder) || (int) $garder < 1) {
            $io->error('--garder doit être un entier positif.');

            return Command::INVALID;
        }
        $fs = new Filesystem();
        $dossier = $input->getOption('dossier');
        $dossier = $fs->isAbsolutePath($dossier) ? $dossier : $this->dossierProjet.'/'.$dossier;
        $fs->mkdir($dossier);
        $horodatage = date('Y-m-d_Hi');

        try {
            $fichiers = [$this->sauvegarderBase($dossier, $horodatage)];
            if (!$input->getOption('sans-fichiers') && ($zip = $this->sauvegarderFichiers($dossier, $horodatage, $io))) {
                $fichiers[] = $zip;
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            $this->sauvegardeLogger->error('Sauvegarde impossible : {erreur}', ['erreur' => $e->getMessage()]);
            $this->notificateur->envoyer('Sauvegarde impossible', "La sauvegarde du $horodatage a échoué :\n\n".$e->getMessage());

            return Command::FAILURE;
        }

        foreach ($fichiers as $f) {
            $io->writeln(sprintf('<info>✔</info> %s (%s)', $f, self::taille((int) filesize($f))));
        }
        $supprimees = $this->nettoyer($dossier, (int) $garder);
        if ($supprimees) {
            $io->note(sprintf('%d ancienne(s) sauvegarde(s) supprimée(s) (on garde les %d dernières).', $supprimees, $garder));
        }
        $this->sauvegardeLogger->info('Sauvegarde terminée : {fichiers} ; {supprimees} ancienne(s) supprimée(s)', [
            'fichiers' => implode(', ', array_map(fn ($f) => basename($f).' ('.self::taille((int) filesize($f)).')', $fichiers)),
            'supprimees' => $supprimees,
        ]);
        $io->success('Sauvegarde terminée dans '.$dossier);

        return Command::SUCCESS;
    }

    private function sauvegarderBase(string $dossier, string $horodatage): string
    {
        $p = $this->connexion->getParams();
        if (!str_contains((string) ($p['driver'] ?? ''), 'pgsql')) {
            throw new \RuntimeException('Sauvegarde prévue pour PostgreSQL seulement (DATABASE_URL).');
        }
        $executable = $this->trouverPgDump();

        $temporaire = "$dossier/$horodatage.sql.partiel";
        $commande = [$executable, '--format=plain', '--no-owner', '--no-privileges', '--encoding=UTF8', '--no-password', '--file='.$temporaire];
        foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--username='] as $cle => $option) {
            if (!empty($p[$cle])) {
                $commande[] = $option.$p[$cle];
            }
        }
        $commande[] = (string) ($p['dbname'] ?? '');

        $process = new Process($commande, null, ['PGPASSWORD' => (string) ($p['password'] ?? '')], null, 900);
        $process->run();
        if (!$process->isSuccessful()) {
            @unlink($temporaire);
            throw new \RuntimeException(sprintf("pg_dump a échoué (code %s) :\n%s", $process->getExitCode(), trim($process->getErrorOutput() ?: $process->getOutput())));
        }

        if (!function_exists('gzopen')) {
            rename($temporaire, $cible = "$dossier/$horodatage.sql");

            return $cible;
        }
        // Compression par blocs : la base n'est jamais chargée entière en mémoire
        $cible = "$dossier/$horodatage.sql.gz";
        $source = fopen($temporaire, 'rb');
        $gz = gzopen($cible, 'wb6');
        while (!feof($source)) {
            gzwrite($gz, (string) fread($source, 1 << 20));
        }
        fclose($source);
        gzclose($gz);
        unlink($temporaire);

        return $cible;
    }

    private function trouverPgDump(): string
    {
        $chemin = trim((string) $this->pgDump, " \"'") ?: 'pg_dump';
        if (preg_match('#[/\\\\]#', $chemin)) {
            if (!is_file($chemin)) {
                throw new \RuntimeException(sprintf('pg_dump introuvable : « %s » (PG_DUMP_PATH).', $chemin));
            }

            return $chemin;
        }

        // Windows : installations de PostgreSQL, version la plus récente d'abord
        $dossiers = [];
        foreach (array_filter([getenv('ProgramFiles'), getenv('ProgramW6432'), 'C:\\Program Files']) as $base) {
            $dossiers = array_merge($dossiers, glob($base.'\\PostgreSQL\\*\\bin', GLOB_ONLYDIR) ?: []);
        }
        usort($dossiers, fn ($a, $b) => strnatcmp($b, $a));

        $trouve = (new ExecutableFinder())->find($chemin, null, array_values(array_unique($dossiers)));
        if ($trouve === null) {
            throw new \RuntimeException(sprintf("pg_dump introuvable (« %s »). Installer les outils clients PostgreSQL ou indiquer son chemin complet dans .env.local, par exemple :\nPG_DUMP_PATH='C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe'", $chemin));
        }

        return $trouve;
    }

    private function sauvegarderFichiers(string $dossier, string $horodatage, SymfonyStyle $io): ?string
    {
        $source = $this->dossierProjet.'/public/uploads';
        if (!is_dir($source)) {
            return null;
        }
        if (!class_exists(\ZipArchive::class)) {
            $io->warning('Extension PHP zip absente : public/uploads n\'est pas sauvegardé (activer extension=zip dans php.ini).');
            $this->sauvegardeLogger->warning('Extension zip absente : public/uploads non sauvegardé');

            return null;
        }

        $cible = "$dossier/{$horodatage}_uploads.zip";
        $zip = new \ZipArchive();
        if ($zip->open($cible, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer '.$cible);
        }
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $nom = 'uploads/'.str_replace('\\', '/', substr($f->getPathname(), strlen($source) + 1));
            $zip->addFile($f->getPathname(), $nom);
            // Images et PDF sont déjà compressés : stockés tels quels (plus rapide)
            if (preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $nom)) {
                $zip->setCompressionName($nom, \ZipArchive::CM_STORE);
            }
        }
        if ($zip->numFiles === 0) {
            $zip->addEmptyDir('uploads');
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Écriture de '.$cible.' impossible.');
        }

        return $cible;
    }

    /** Supprime les sauvegardes au-delà des $garder plus récentes. @return int nombre de sauvegardes supprimées */
    private function nettoyer(string $dossier, int $garder): int
    {
        $groupes = [];
        foreach (scandir($dossier) ?: [] as $f) {
            if (preg_match(self::MOTIF, $f, $m)) {
                $groupes[$m[1]][] = "$dossier/$f";
            }
        }
        krsort($groupes);
        $anciens = array_slice($groupes, $garder, null, true);
        foreach ($anciens as $fichiers) {
            foreach ($fichiers as $f) {
                @unlink($f);
            }
        }

        return count($anciens);
    }

    private static function taille(int $octets): string
    {
        return $octets >= 1 << 20 ? sprintf('%.1f Mo', $octets / (1 << 20)) : sprintf('%d ko', (int) ceil($octets / 1024));
    }
}
