<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Envoi d'images (actus, équipes, galerie, partenaires, boutique) et de documents PDF depuis l'espace admin.
 * Les fichiers sont rangés dans public/uploads/AAAA/MM/ et servis tels quels.
 *
 * PDF : reconnu sur son contenu (type application/pdf et signature « %PDF- »), enregistré sans modification.
 *
 * Le type est vérifié sur le contenu du fichier (pas sur son nom). Si l'extension GD est disponible,
 * l'image est réencodée (métadonnées EXIF/GPS retirées) et ramenée à COTE_MAX pixels au plus ;
 * sinon elle est enregistrée telle quelle. Les GIF ne sont pas retouchés (animations).
 */
class MediaController extends AbstractController
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const TAILLE_MAX = 10 * 1024 * 1024;
    private const COTE_MAX = 2400;
    /** Au-delà, le décodage par GD risquerait d'épuiser la mémoire */
    private const PIXELS_MAX = 50_000_000;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads')] private readonly string $dossier,
        #[Autowire('%env(default::UPLOADS_BASE_URL)%')] private readonly ?string $urlPublique,
    ) {
    }

    #[Route('/api/medias', name: 'api_media_upload', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function upload(Request $request): JsonResponse
    {
        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile) {
            return $this->json(['detail' => 'Aucun fichier reçu (champ « fichier »).'], 400);
        }
        if (!$fichier->isValid()) {
            return $this->json(['detail' => 'Envoi échoué : '.$fichier->getErrorMessage()], 400);
        }
        if ($fichier->getSize() > self::TAILLE_MAX) {
            return $this->json(['detail' => 'Fichier trop lourd (10 Mo maximum).'], 400);
        }

        $chemin = $fichier->getPathname();
        $type = (new \finfo(\FILEINFO_MIME_TYPE))->file($chemin);
        $base = rtrim($this->urlPublique ?: $request->getSchemeAndHttpHost(), '/');
        $sousDossier = date('Y/m');

        if ($type === 'application/pdf') {
            if (!self::estPdf($chemin)) {
                return $this->json(['detail' => 'PDF invalide.'], 400);
            }
            $nom = bin2hex(random_bytes(12)).'.pdf';
            $fichier->move($this->dossier.'/'.$sousDossier, $nom);

            return $this->json(['url' => "$base/uploads/$sousDossier/$nom"], 201);
        }

        $infos = @getimagesize($chemin);
        if (!isset(self::EXTENSIONS[$type]) || !$infos) {
            return $this->json(['detail' => 'Formats acceptés : JPG, PNG, WebP, GIF, PDF.'], 400);
        }
        if ($infos[0] * $infos[1] > self::PIXELS_MAX) {
            return $this->json(['detail' => 'Image trop grande (50 mégapixels maximum).'], 400);
        }

        $nom = bin2hex(random_bytes(12)).'.'.self::EXTENSIONS[$type];
        $cible = $this->dossier.'/'.$sousDossier;
        $contenu = $type !== 'image/gif' ? $this->reencoder($chemin, $type) : null;
        if ($contenu !== null) {
            (new Filesystem())->dumpFile("$cible/$nom", $contenu);
        } else {
            $fichier->move($cible, $nom);
        }

        return $this->json(['url' => "$base/uploads/$sousDossier/$nom"], 201);
    }

    /** Signature PDF en tête de fichier (finfo seul peut se contenter d'un « %PDF » plus loin). */
    private static function estPdf(string $chemin): bool
    {
        $f = @fopen($chemin, 'rb');
        if (!$f) {
            return false;
        }
        $debut = (string) fread($f, 1024);
        fclose($f);

        return preg_match('/^(?:\xEF\xBB\xBF)?\s{0,4}%PDF-\d/', $debut) === 1;
    }

    /** Image réencodée (sans métadonnées, redimensionnée si besoin), ou null si GD ne sait pas la traiter. */
    private function reencoder(string $chemin, string $type): ?string
    {
        $encodeur = ['image/jpeg' => 'imagejpeg', 'image/png' => 'imagepng', 'image/webp' => 'imagewebp'][$type];
        if (!function_exists('imagecreatefromstring') || !function_exists($encodeur)) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($chemin));
        if (!$image) {
            return null;
        }

        // Photo de téléphone : l'orientation EXIF est appliquée avant de retirer les métadonnées
        if ($type === 'image/jpeg' && function_exists('exif_read_data')) {
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($chemin)['Orientation'] ?? 1)] ?? 0;
            if ($angle && ($tournee = imagerotate($image, $angle, 0))) {
                $image = $tournee;
            }
        }

        $l = imagesx($image);
        $h = imagesy($image);
        $ratio = self::COTE_MAX / max($l, $h);
        if ($ratio < 1) {
            $reduite = imagecreatetruecolor(max(1, (int) round($l * $ratio)), max(1, (int) round($h * $ratio)));
            imagealphablending($reduite, false);
            imagesavealpha($reduite, true);
            imagecopyresampled($reduite, $image, 0, 0, 0, 0, imagesx($reduite), imagesy($reduite), $l, $h);
            $image = $reduite;
        } else {
            imagesavealpha($image, true);
        }

        ob_start();
        $ok = match ($type) {
            'image/jpeg' => imagejpeg($image, null, 85),
            'image/png' => imagepng($image, null, 9),
            'image/webp' => imagewebp($image, null, 85),
        };
        $contenu = ob_get_clean();

        return $ok && $contenu ? $contenu : null;
    }
}
