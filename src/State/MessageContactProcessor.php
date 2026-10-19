<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Formulaire de contact : limite d'envois par adresse IP (anti-spam), puis enregistrement normal.
 *
 * @implements ProcessorInterface<\App\Entity\MessageContact, \App\Entity\MessageContact>
 */
final class MessageContactProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')] private readonly ProcessorInterface $enregistrement,
        private readonly RequestStack $requetes,
        private readonly RateLimiterFactoryInterface $contactLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $ip = $this->requetes->getCurrentRequest()?->getClientIp() ?? 'inconnue';
        $limite = $this->contactLimiter->create($ip)->consume();
        if (!$limite->isAccepted()) {
            $attente = max(1, $limite->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($attente, sprintf('Trop de messages envoyés. Réessayez dans %d minute(s).', (int) ceil($attente / 60)));
        }

        return $this->enregistrement->process($data, $operation, $uriVariables, $context);
    }
}
