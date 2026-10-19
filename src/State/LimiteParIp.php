<?php

namespace App\State;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Formulaires publics : limite d'envois par adresse IP (anti-spam).
 */
trait LimiteParIp
{
    private function limiterParIp(RequestStack $requetes, RateLimiterFactoryInterface $limiteur, string $trop): void
    {
        $ip = $requetes->getCurrentRequest()?->getClientIp() ?? 'inconnue';
        $limite = $limiteur->create($ip)->consume();
        if (!$limite->isAccepted()) {
            $attente = max(1, $limite->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($attente, sprintf('%s Réessayez dans %d minute(s).', $trop, (int) ceil($attente / 60)));
        }
    }
}
