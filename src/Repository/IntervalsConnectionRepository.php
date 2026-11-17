<?php

namespace App\Repository;

use App\Entity\IntervalsConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IntervalsConnection>
 */
class IntervalsConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntervalsConnection::class);
    }

    public function findForOwner(User $owner): ?IntervalsConnection
    {
        return $this->findOneBy(['owner' => $owner]);
    }

    /**
     * L'ordre de passage de la synchro automatique : jamais synchronisés d'abord
     * (MariaDB range NULL en tête d'un tri croissant), puis du plus ancien au
     * plus récent. Le propriétaire vient avec, `sync()` le lit pour chacun.
     *
     * @return list<IntervalsConnection>
     */
    public function findForAutoSync(): array
    {
        /** @var list<IntervalsConnection> */
        return $this->createQueryBuilder('c')
            ->addSelect('o')
            ->join('c.owner', 'o')
            ->orderBy('c.lastSyncedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
