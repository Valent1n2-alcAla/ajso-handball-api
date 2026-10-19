<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Alertes envoyées par e-mail (texte brut) à l'adresse ADMIN_EMAIL : nouveau message de contact,
 * nouvelle préinscription, erreurs de l'import FFHandball.
 *
 * ADMIN_EMAIL vide = aucun envoi. Un échec d'envoi est journalisé mais ne fait jamais échouer
 * la requête ou la commande en cours.
 */
class Notificateur
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $notificationLogger,
        #[Autowire('%env(default::ADMIN_EMAIL)%')] private readonly ?string $destinataire = null,
        #[Autowire('%env(default::MAILER_FROM)%')] private readonly ?string $expediteur = null,
    ) {
    }

    public function estActif(): bool
    {
        return trim((string) $this->destinataire) !== '';
    }

    /**
     * @param string|null $repondreA adresse de réponse (l'auteur d'un message, par exemple)
     *
     * @return bool true si l'e-mail est parti
     */
    public function envoyer(string $sujet, string $texte, ?string $repondreA = null): bool
    {
        if (!$this->estActif()) {
            return false;
        }

        try {
            $destinataires = array_filter(array_map('trim', explode(',', (string) $this->destinataire)));
            $email = (new Email())
                ->from(trim((string) $this->expediteur) ?: $destinataires[0])
                ->to(...$destinataires)
                ->subject('[AJSO] '.$sujet)
                ->text($texte);
            if ($repondreA && filter_var($repondreA, FILTER_VALIDATE_EMAIL)) {
                $email->replyTo(new Address($repondreA));
            }
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->notificationLogger->error('Envoi de l\'e-mail « {sujet} » impossible : {erreur}', ['sujet' => $sujet, 'erreur' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }
}
