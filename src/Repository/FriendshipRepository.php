<?php

namespace App\Repository;

use App\Entity\Friendship;
use App\Entity\User;
use App\Enum\FriendshipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Friendship>
 */
class FriendshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Friendship::class);
    }

    /** La ligne de la paire, dans un sens ou dans l'autre, quel que soit son statut. */
    public function findBetween(User $a, User $b): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->andWhere('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** `$a` et `$b` sont-ils amis (amitié acceptée) ? */
    public function areFriends(User $a, User $b): bool
    {
        return $this->findBetween($a, $b)?->isAccepted() ?? false;
    }

    /**
     * Les amitiés acceptées de `$user`, les deux comptes chargés, la plus
     * récemment nouée d'abord.
     *
     * @return list<Friendship>
     */
    public function findAcceptedFor(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('r', 'a')
            ->join('f.requester', 'r')
            ->join('f.addressee', 'a')
            ->andWhere('f.requester = :user OR f.addressee = :user')
            ->andWhere('f.status = :accepted')
            ->setParameter('user', $user)
            ->setParameter('accepted', FriendshipStatus::ACCEPTED)
            ->orderBy('f.acceptedAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les amis de `$user`, rangés par nom affiché.
     *
     * @return list<User>
     */
    public function friendsOf(User $user): array
    {
        $friends = [];
        foreach ($this->findAcceptedFor($user) as $friendship) {
            $other = $friendship->otherParty($user);
            if (null !== $other) {
                $friends[] = $other;
            }
        }

        usort($friends, static fn (User $a, User $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $friends;
    }

    /**
     * Demandes que `$user` doit traiter, les plus récentes d'abord.
     *
     * @return list<Friendship>
     */
    public function findReceivedPending(User $user): array
    {
        return $this->pending('addressee', $user);
    }

    /**
     * Demandes envoyées par `$user`, encore sans réponse.
     *
     * @return list<Friendship>
     */
    public function findSentPending(User $user): array
    {
        return $this->pending('requester', $user);
    }

    /**
     * Le compte des demandes reçues : la pastille de l'entrée « Amis » du menu,
     * donc lu sur chaque page. Un COUNT sur l'index (addressee, status).
     */
    public function countReceivedPending(User $user): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.addressee = :user')
            ->andWhere('f.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', FriendshipStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Friendship>
     */
    private function pending(string $side, User $user): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('r', 'a')
            ->join('f.requester', 'r')
            ->join('f.addressee', 'a')
            ->andWhere(sprintf('f.%s = :user', $side))
            ->andWhere('f.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', FriendshipStatus::PENDING)
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
