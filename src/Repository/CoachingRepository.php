<?php

namespace App\Repository;

use App\Entity\Coaching;
use App\Entity\User;
use App\Enum\CoachingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Coaching>
 */
class CoachingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coaching::class);
    }

    /**
     * La relation du couple **ordonné** (coach, athlète), quel que soit son statut.
     * Sert à éviter les doublons avant création (l'UniqueConstraint est le filet).
     */
    public function findRelation(User $coach, User $athlete): ?Coaching
    {
        return $this->findOneBy(['coach' => $coach, 'athlete' => $athlete]);
    }

    /**
     * La relation ACCEPTÉE où `$coach` suit `$athlete`, sinon null. Porte les
     * notes privées du coach (#65) : n'est rendue qu'au coach lui-même, jamais
     * dans l'autre sens, et rien pour soi-même.
     */
    public function findAcceptedRelation(User $coach, ?User $athlete): ?Coaching
    {
        if (null === $athlete || $athlete->getId() === $coach->getId()) {
            return null;
        }

        $coaching = $this->findRelation($coach, $athlete);

        return null !== $coaching && $coaching->isActive() ? $coaching : null;
    }

    /**
     * Relation dans un sens ou dans l'autre : deux utilisateurs ne peuvent pas être
     * à la fois coach l'un de l'autre. Sert au contrôle avant création d'une demande.
     */
    public function findAnyBetween(User $a, User $b): ?Coaching
    {
        return $this->createQueryBuilder('c')
            ->andWhere('(c.coach = :a AND c.athlete = :b) OR (c.coach = :b AND c.athlete = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `$coach` est-il coach **accepté** de `$athlete` ? Requête COUNT : c'est le
     * test chaud des voters, appelé sur chaque décision d'accès (mémoïsé en amont
     * par CoachingResolver).
     */
    public function isAcceptedCoachOf(User $coach, User $athlete): bool
    {
        return 0 < (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.coach = :coach')
            ->andWhere('c.athlete = :athlete')
            ->andWhere('c.status = :status')
            ->setParameter('coach', $coach)
            ->setParameter('athlete', $athlete)
            ->setParameter('status', CoachingStatus::ACCEPTED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Athlètes suivis (relations acceptées), les plus récemment nouées d'abord.
     *
     * @return list<Coaching>
     */
    public function findAcceptedAthletes(User $coach): array
    {
        return $this->byStatus('coach', $coach, CoachingStatus::ACCEPTED);
    }

    /**
     * Anciens athlètes de `$coach` (#81) : relations terminées, les plus
     * récemment closes d'abord. Une relation refusée n'y entre pas, elle n'a
     * jamais existé.
     *
     * @return list<Coaching>
     */
    public function findEndedAthletes(User $coach): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.coach = :coach')
            ->andWhere('c.status = :status')
            ->setParameter('coach', $coach)
            ->setParameter('status', CoachingStatus::ENDED)
            ->orderBy('c.endedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Coachs actifs de l'athlète.
     *
     * @return list<Coaching>
     */
    public function findAcceptedCoaches(User $athlete): array
    {
        return $this->byStatus('athlete', $athlete, CoachingStatus::ACCEPTED);
    }

    /**
     * Demandes que `$user` doit traiter : PENDING dont il est partie prenante mais
     * pas l'initiateur. Couvre les deux sens (un coach reçoit aussi des demandes).
     *
     * @return list<Coaching>
     */
    public function findPendingReceivedBy(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.coach = :user OR c.athlete = :user')
            ->andWhere('c.requestedBy != :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', CoachingStatus::PENDING)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Demandes envoyées par `$user` et encore sans réponse.
     *
     * @return list<Coaching>
     */
    public function findPendingSentBy(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.requestedBy = :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', CoachingStatus::PENDING)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Réponses que `$user` n'a pas encore vues à ses propres demandes (#79) :
     * acceptées ou refusées, bandeau d'accueil tant qu'il ne l'a pas fermé. Une
     * relation acceptée puis terminée avant qu'il passe n'a plus rien à annoncer.
     *
     * @return list<Coaching>
     */
    public function findUnseenResponsesFor(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.requestedBy = :user')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('c.respondedAt IS NOT NULL')
            ->andWhere('c.responseSeenAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('statuses', [CoachingStatus::ACCEPTED, CoachingStatus::DECLINED])
            ->orderBy('c.respondedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Compteur pour la pastille de navigation (demandes à traiter). */
    public function countPendingReceivedBy(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.coach = :user OR c.athlete = :user')
            ->andWhere('c.requestedBy != :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', CoachingStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Coaching>
     */
    private function byStatus(string $side, User $user, CoachingStatus $status): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere(sprintf('c.%s = :user', $side))
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->orderBy('c.respondedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
