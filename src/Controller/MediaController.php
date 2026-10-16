<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Envoi d'images depuis l'espace admin (actus, équipes, galerie, partenaires, boutique).
 * Les fichiers sont rangés dans public/uploads/AAAA/MM/ et servis tels quels.
 */
class MediaController extends AbstractController
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads')] private readonly string $dossier,
        #[Autowire('%env(default::UPLOADS_BASE_URL)%')] private readonly ?string $urlPublique,
    ) {
    }

    #[Route('/api/medias', name: 'api_media_upload', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function upload(Request $request, ValidatorInterface $validator): JsonResponse
    {
        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile) {
            return $this->json(['detail' => 'Aucun fichier reçu (champ « fichier »).'], 400);
        }

        $violations = $validator->validate($fichier, new Assert\Image(
            maxSize: '8M',
            mimeTypes: array_keys(self::EXTENSIONS),
            mimeTypesMessage: 'Formats acceptés : JPG, PNG, WebP, GIF.',
        ));
        if (count($violations) > 0) {
            return $this->json(['detail' => $violations[0]->getMessage()], 422);
        }

        $sousDossier = date('Y/m');
        $nom = bin2hex(random_bytes(12)).'.'.self::EXTENSIONS[$fichier->getMimeType()];
        $fichier->move($this->dossier.'/'.$sousDossier, $nom);

        $base = rtrim($this->urlPublique ?: $request->getSchemeAndHttpHost(), '/');

        return $this->json(['url' => "$base/uploads/$sousDossier/$nom"], 201);
    }
}
