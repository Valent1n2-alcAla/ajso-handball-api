<?php

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Preinscription;
use App\Service\Notificateur;
use App\State\PreinscriptionProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class PreinscriptionProcessorTest extends TestCase
{
    public function testEnregistreNotifiePuisLimite(): void
    {
        $enregistrement = $this->createMock(ProcessorInterface::class);
        $enregistrement->expects(self::exactly(2))->method('process')->willReturnArgument(0);

        $mailer = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $envoyes = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->envoyes[] = $message;
            }
        };
        $requetes = new RequestStack();
        $requetes->push(Request::create('/api/preinscriptions', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));
        $limiteur = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'], new InMemoryStorage());

        $processeur = new PreinscriptionProcessor($enregistrement, $requetes, $limiteur, new Notificateur($mailer, new NullLogger(), 'bureau@exemple.fr'));
        $p = (new Preinscription())->setPrenom('Léa')->setNom('Martin')->setDateNaissance(new \DateTime('2015-03-02'))
            ->setEmail('parent@exemple.fr')->setTelephone('0612345678')->setDejaLicencie(true);

        $processeur->process($p, new Post());
        $processeur->process($p, new Post());
        self::assertCount(2, $mailer->envoyes);
        self::assertStringContainsString('Léa Martin', $mailer->envoyes[0]->getSubject());
        self::assertStringContainsString('02/03/2015', $mailer->envoyes[0]->getTextBody());
        self::assertStringContainsString('Déjà licencié(e) : oui', $mailer->envoyes[0]->getTextBody());

        $this->expectException(TooManyRequestsHttpException::class);
        $processeur->process($p, new Post());
    }
}
