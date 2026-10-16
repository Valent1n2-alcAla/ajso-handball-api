<?php

namespace App\Entity;

use App\Repository\ChampionnatRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Symfony\Component\Serializer\Attribute\Groups;
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
    normalizationContext: ['groups' => ['championnat:read']],
)]
#[ORM\Entity(repositoryClass: ChampionnatRepository::class)]
class Championnat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['equipe:read', 'rencontre:read', 'championnat:read', 'classement:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['equipe:read', 'rencontre:read', 'championnat:read', 'classement:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    #[Groups(['equipe:read', 'rencontre:read', 'championnat:read', 'classement:read'])]
    private ?string $niveau = null;

    /** championnat | phase-1 | phase-2 | coupe */
    #[ORM\Column(length: 20, options: ['default' => 'championnat'])]
    #[Assert\Choice(choices: ['championnat', 'phase-1', 'phase-2', 'coupe'])]
    #[Groups(['equipe:read', 'rencontre:read', 'championnat:read', 'classement:read'])]
    private string $type = 'championnat';

    /** Page de la poule sur ffhandball.fr, pour l'import automatique des matchs et du classement */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(requireTld: true)]
    #[Groups(['championnat:read', 'equipe:read'])]
    private ?string $urlFfhb = null;

    /**
     * @var Collection<int, Equipe>
     */
    #[ORM\OneToMany(targetEntity: Equipe::class, mappedBy: 'championnat')]
    private Collection $equipes;

    public function __construct()
    {
        $this->equipes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getNiveau(): ?string
    {
        return $this->niveau;
    }

    public function setNiveau(string $niveau): static
    {
        $this->niveau = $niveau;

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

    public function getUrlFfhb(): ?string
    {
        return $this->urlFfhb;
    }

    public function setUrlFfhb(?string $urlFfhb): static
    {
        $this->urlFfhb = $urlFfhb;

        return $this;
    }

    /**
     * @return Collection<int, Equipe>
     */
    public function getEquipes(): Collection
    {
        return $this->equipes;
    }

    public function addEquipe(Equipe $equipe): static
    {
        if (!$this->equipes->contains($equipe)) {
            $this->equipes->add($equipe);
            $equipe->setChampionnat($this);
        }

        return $this;
    }

    public function removeEquipe(Equipe $equipe): static
    {
        if ($this->equipes->removeElement($equipe)) {
            if ($equipe->getChampionnat() === $this) {
                $equipe->setChampionnat(null);
            }
        }

        return $this;
    }
}