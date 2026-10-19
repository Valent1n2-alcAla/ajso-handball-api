<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Preinscription;
use App\Service\Notificateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Préinscription : limite d'envois par adresse IP (anti-spam), enregistrement normal,
 * puis alerte e-mail à l'admin (ADMIN_EMAIL).
 *
 * @implements ProcessorInterface<Preinscription, Preinscription>
 */
final class PreinscriptionProcessor implements ProcessorInterface
{
    use LimiteParIp;

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')] private readonly ProcessorInterface $enregistrement,
        private readonly RequestStack $requetes,
        private readonly RateLimiterFactoryInterface $preinscriptionLimiter,
        private readonly Notificateur $notificateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->limiterParIp($this->requetes, $this->preinscriptionLimiter, 'Trop de préinscriptions envoyées.');

        $resultat = $this->enregistrement->process($data, $operation, $uriVariables, $context);

        if ($data instanceof Preinscription) {
            $this->notificateur->envoyer(
                sprintf('Préinscription : %s %s', $data->getPrenom(), $data->getNom()),
                self::texte($data),
                $data->getEmail(),
            );
        }

        return $resultat;
    }

    public static function texte(Preinscription $p): string
    {
        $naissance = $p->getDateNaissance();

        return implode("\n", [
            'Nouvelle préinscription reçue depuis le site.',
            '',
            'Prénom : '.$p->getPrenom(),
            'Nom : '.$p->getNom(),
            'Date de naissance : '.($naissance ? $naissance->format('d/m/Y').' (né(e) en '.$naissance->format('Y').')' : '—'),
            'Sexe : '.(['F' => 'féminin', 'M' => 'masculin'][$p->getSexe()] ?? '—'),
            'Catégorie souhaitée : '.($p->getCategorieSouhaitee() ?: '—'),
            'Déjà licencié(e) : '.($p->isDejaLicencie() ? 'oui' : 'non'),
            'E-mail : '.$p->getEmail(),
            'Téléphone : '.$p->getTelephone(),
            '',
            $p->getMessage() ?: '(pas de message)',
            '',
            '— Répondre directement à cet e-mail écrit à la famille. Préinscription aussi visible dans l\'espace admin.',
        ]);
    }
}
