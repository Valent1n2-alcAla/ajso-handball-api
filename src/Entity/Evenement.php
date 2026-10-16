<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\EvenementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    order: ['debut' => 'ASC'],
)]
#[ApiFilter(DateFilter::class, properties: ['debut'])]
#[ApiFilter(OrderFilter::class, properties: ['debut'])]
#[ORM\Entity(repositoryClass: EvenementRepository::class)]
class Evenement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private ?string $titre = null;

    /** Heure locale du club, sans fuseau (AAAA-MM-JJTHH:MM) */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\\TH:i'])]
    private ?\DateTime $debut = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'debut')]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\\TH:i'])]
    private ?\DateTime $fin = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $lieu = null;

    #[ORM\Column(length: 30, options: ['default' => 'evenement'])]
    #[Assert\Choice(choices: ['tournoi', 'stage', 'festif', 'assemblee', 'evenement'])]
    private string $type = 'evenement';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $image = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(requireTld: true)]
    private ?string $lienInscription = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDebut(): ?\DateTime
    {
        return $this->debut;
    }

    public function setDebut(?\DateTime $debut): static
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTime
    {
        return $this->fin;
    }

    public function setFin(?\DateTime $fin): static
    {
        $this->fin = $fin;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getLienInscription(): ?string
    {
        return $this->lienInscription;
    }

    public function setLienInscription(?string $lienInscription): static
    {
        $this->lienInscription = $lienInscription;

        return $this;
    }
}
