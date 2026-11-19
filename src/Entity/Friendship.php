<?php

namespace App\Entity;

use App\Enum\FriendshipStatus;
use App\Repository\FriendshipRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Amitié entre deux comptes (#126) : la seconde entité qui relie deux `User`,
 * après `Coaching`, et elle n'en a aucun des droits.
 *
 * Une amitié acceptée ouvre une seule chose, **en lecture et dans les deux
 * sens** : le flux, c'est-à-dire les séances de salle faites de l'autre, avec
 * leur réalisé (exercices perso compris, par leur nom). Rien d'autre : ni
 * profil, ni plans, ni objectifs, ni séances prévues, et aucun voter ne la
 * consulte. Copier une séance d'ami la recopie chez soi, elle ne partage rien.
 *
 * Symétrique, mais stockée orientée (`requester` → `addressee`) : c'est ce qui
 * dit qui peut répondre. Une seule ligne par paire, dans un sens ou dans
 * l'autre : le contrôleur cherche les deux sens avant de créer
 * (`FriendshipRepository::findBetween()`), la contrainte unique est le filet
 * du sens écrit.
 */
#[ORM\Entity(repositoryClass: FriendshipRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_FRIENDSHIP_PAIR', columns: ['requester_id', 'addressee_id'])]
#[ORM\Index(name: 'IDX_FRIENDSHIP_ADDRESSEE_STATUS', columns: ['addressee_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Friendship
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $requester = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $addressee = null;

    #[ORM\Column(length: 20, enumType: FriendshipStatus::class, options: ['default' => FriendshipStatus::PENDING->value])]
    private FriendshipStatus $status = FriendshipStatus::PENDING;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    // Date d'acceptation, null tant que la demande attend.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function setRequester(User $requester): static
    {
        $this->requester = $requester;

        return $this;
    }

    public function getAddressee(): ?User
    {
        return $this->addressee;
    }

    public function setAddressee(User $addressee): static
    {
        $this->addressee = $addressee;

        return $this;
    }

    public function getStatus(): FriendshipStatus
    {
        return $this->status;
    }

    public function accept(): static
    {
        $this->status = FriendshipStatus::ACCEPTED;
        $this->acceptedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isAccepted(): bool
    {
        return FriendshipStatus::ACCEPTED === $this->status;
    }

    public function isPending(): bool
    {
        return FriendshipStatus::PENDING === $this->status;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    /** L'autre partie vue depuis `$user`. */
    public function otherParty(User $user): ?User
    {
        return $this->requester?->getId() === $user->getId() ? $this->addressee : $this->requester;
    }

    /** `$user` fait-il partie de cette amitié ? */
    public function involves(User $user): bool
    {
        return $this->requester?->getId() === $user->getId() || $this->addressee?->getId() === $user->getId();
    }

    /** `$user` a-t-il envoyé la demande (seul habilité à l'annuler) ? */
    public function isRequester(User $user): bool
    {
        return $this->requester?->getId() === $user->getId();
    }

    /** `$user` l'a-t-il reçue (seul habilité à y répondre) ? */
    public function isAddressee(User $user): bool
    {
        return $this->addressee?->getId() === $user->getId();
    }
}
