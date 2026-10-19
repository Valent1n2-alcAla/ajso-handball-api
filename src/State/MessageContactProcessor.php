<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MessageContact;
use App\Service\Notificateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Formulaire de contact : limite d'envois par adresse IP (anti-spam), enregistrement normal,
 * puis alerte e-mail à l'admin (ADMIN_EMAIL).
 *
 * @implements ProcessorInterface<MessageContact, MessageContact>
 */
final class MessageContactProcessor implements ProcessorInterface
{
    use LimiteParIp;

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')] private readonly ProcessorInterface $enregistrement,
        private readonly RequestStack $requetes,
        private readonly RateLimiterFactoryInterface $contactLimiter,
        private readonly Notificateur $notificateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->limiterParIp($this->requetes, $this->contactLimiter, 'Trop de messages envoyés.');

        $resultat = $this->enregistrement->process($data, $operation, $uriVariables, $context);

        if ($data instanceof MessageContact) {
            $this->notificateur->envoyer(
                'Nouveau message : '.$data->getSujet(),
                self::texte($data),
                $data->getEmail(),
            );
        }

        return $resultat;
    }

    public static function texte(MessageContact $m): string
    {
        return implode("\n", [
            'Nouveau message reçu depuis le formulaire de contact du site.',
            '',
            'Nom : '.$m->getNom(),
            'E-mail : '.$m->getEmail(),
            'Téléphone : '.($m->getTelephone() ?: '—'),
            'Sujet : '.$m->getSujet(),
            '',
            (string) $m->getMessage(),
            '',
            '— Répondre directement à cet e-mail écrit à l\'expéditeur. Message aussi visible dans l\'espace admin.',
        ]);
    }
}
