<?php

namespace App\Controller;

use App\Repository\EquipeRepository;
use App\Service\FfhbImporter;
use App\Service\ImportEnCoursException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Déclenchement manuel de l'import FFHandball depuis l'espace admin. */
#[IsGranted('ROLE_ADMIN')]
class FfhbController extends AbstractController
{
    #[Route('/api/ffhb/import/{id}', name: 'api_ffhb_import', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function importer(int $id, EquipeRepository $equipes, FfhbImporter $importer): JsonResponse
    {
        $equipe = $equipes->find($id);
        if (!$equipe || !$equipe->isEstAjso()) {
            return $this->json(['detail' => 'Équipe introuvable.'], 404);
        }
        set_time_limit(180);

        try {
            return $this->json($importer->importer($equipe));
        } catch (ImportEnCoursException $e) {
            return $this->json(['detail' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['detail' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->json(['detail' => 'Import impossible : '.$e->getMessage()], 502);
        }
    }
}
