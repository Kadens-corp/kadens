<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomMessage;
use App\Entity\User;
use App\Enum\MessageAudience;
use App\Enum\MessagePlatform;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomMessage>
 */
class CustomMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomMessage::class);
    }

    /**
     * Les messages actifs adressés à ce compte, pas encore vus, dont la fenêtre
     * croise `[$from, $until]` (bornes incluses), du plus ancien début au plus
     * récent. Le web demande le jour même (`$from = $until = aujourd'hui`), le
     * téléphone un horizon, pour pouvoir l'afficher hors réseau le jour venu.
     * `$surface` (WEB ou APP) écarte les messages réservés à l'autre côté.
     *
     * @return list<CustomMessage>
     */
    public function findUnseenFor(User $user, \DateTimeImmutable $from, \DateTimeImmutable $until, MessagePlatform $surface): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.active = true')
            ->andWhere('m.startsOn <= :until')
            ->andWhere('m.endsOn >= :from')
            ->andWhere('m.audience = :all OR :user MEMBER OF m.recipients')
            ->andWhere(':user NOT MEMBER OF m.seenBy')
            ->andWhere('m.platform IN (:platforms)')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('until', $until, Types::DATE_IMMUTABLE)
            ->setParameter('all', MessageAudience::ALL)
            ->setParameter('user', $user)
            ->setParameter('platforms', array_map(static fn (MessagePlatform $p): string => $p->value, MessagePlatform::shownOn($surface)))
            ->orderBy('m.startsOn', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le back-office : tout, le plus récent en tête.
     *
     * @return list<CustomMessage>
     */
    public function findForAdmin(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.startsOn', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
