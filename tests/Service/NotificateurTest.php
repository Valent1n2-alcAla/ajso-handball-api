<?php

namespace App\Tests\Service;

use App\Service\Notificateur;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class NotificateurTest extends TestCase
{
    public function testSansAdresseAucunEnvoi(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        self::assertFalse((new Notificateur($mailer, $this->createStub(LoggerInterface::class), ''))->envoyer('Sujet', 'Texte'));
    }

    public function testEnvoi(): void
    {
        $mailer = new class implements MailerInterface {
            public ?Email $envoye = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->envoye = $message;
            }
        };

        $ok = (new Notificateur($mailer, $this->createStub(LoggerInterface::class), 'bureau@exemple.fr, president@exemple.fr'))
            ->envoyer('Nouveau message', 'Bonjour', 'parent@exemple.fr');

        self::assertTrue($ok);
        self::assertSame('[AJSO] Nouveau message', $mailer->envoye->getSubject());
        self::assertCount(2, $mailer->envoye->getTo());
        self::assertSame('bureau@exemple.fr', $mailer->envoye->getFrom()[0]->getAddress());
        self::assertSame('parent@exemple.fr', $mailer->envoye->getReplyTo()[0]->getAddress());
        self::assertSame('Bonjour', $mailer->envoye->getTextBody());
    }

    public function testEchecJournaliseSansException(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP injoignable'));
        $journal = $this->createMock(LoggerInterface::class);
        $journal->expects(self::once())->method('error');

        self::assertFalse((new Notificateur($mailer, $journal, 'bureau@exemple.fr'))->envoyer('Sujet', 'Texte'));
    }
}
