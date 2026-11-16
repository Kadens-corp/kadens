<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Flux de relation : demande, acceptation, refus, fin. Le contrôle d'accès croisé
 * au contenu (coach accepté = co-éditeur) est couvert par CoachControllerTest.
 */
final class CoachingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : coaching référence user, il passe donc avant les users.
        foreach ($this->em->getRepository(Coaching::class)->findAll() as $coaching) {
            $this->em->remove($coaching);
        }
        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
        }
        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/coaching');

        self::assertResponseRedirects('/login');
    }

    public function testIndexIsOpenToPlainUser(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
    }

    /**
     * Page unique : un coach y voit ses athlètes ET le formulaire d'invitation,
     * qui vivaient auparavant sur un tableau de bord séparé (/coach).
     */
    public function testIndexShowsBothDirectionsForCoach(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED, $coach);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('athlete@example.com', $html);
        self::assertStringContainsString('Inviter un athlète', $html);
        self::assertStringContainsString('Demander à être coaché', $html);
        // La fiche de travail reste accessible depuis la carte (nom + bouton).
        self::assertGreaterThan(0, $crawler->filter('a[href="/coach/athlete/'.$athlete->getId().'"]')->count());
    }

    /** Un non-coach ne voit ni le formulaire d'invitation ni la section athlètes. */
    public function testIndexHidesCoachSideForPlainUser(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $crawler = $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Inviter un athlète', $crawler->html());
        self::assertStringContainsString('Demander à être coaché', $crawler->html());
    }

    /**
     * #66 : l'athlète lit ce qu'il ouvre avant d'accepter, sur le bandeau
     * d'accueil comme sur la carte de /coaching.
     */
    public function testAthleteSeesCoachRightsBeforeAccepting(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);

        // Sur /coaching, le formulaire « Demander à être coaché » porte aussi la
        // liste (repliée) : on vise la carte de la demande.
        foreach (['/' => '.kd-home__coach', '/coaching' => '.kd-coachcard'] as $url => $scope) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful();
            $rights = $crawler->filter($scope.' .kd-coachrights');
            self::assertCount(1, $rights, $url);
            self::assertStringContainsString('Ce que coach@example.com pourra faire', $rights->text());
            self::assertStringContainsString('notes privées', $rights->text());
            // Liste en clair, pas repliée : elle doit se lire avant le bouton.
            self::assertCount(0, $crawler->filter($scope.' details .kd-coachrights'), $url);
        }
    }

    /** Le coach qui reçoit une demande d'athlète n'ouvre rien : pas de liste. */
    public function testCoachReceivingRequestSeesNoRightsList(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $athlete);

        $this->client->loginUser($coach);

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('te demande de l', $crawler->filter('.kd-home__coach')->text());
        self::assertCount(0, $crawler->filter('.kd-coachrights'));

        // Sur /coaching, seul le formulaire « Demander à être coaché » la porte.
        $crawler = $this->client->request('GET', '/coaching');
        self::assertCount(0, $crawler->filter('.kd-coachcard .kd-coachrights'));
    }

    /** Relation active : la liste reste consultable côté athlète, au présent, et jamais côté coach. */
    public function testActiveRelationKeepsRightsOnAthleteCardOnly(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED, $coach);

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/coaching');
        $folded = $crawler->filter('.kd-coachcard details.kd-coachcard__rights');
        self::assertCount(1, $folded);
        self::assertStringContainsString('Ce que coach@example.com peut faire', $folded->filter('summary')->text());

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coaching');
        self::assertCount(0, $crawler->filter('.kd-coachcard .kd-coachrights'));
    }

    public function testAthleteRequestCreatesPendingRelation(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $this->createUser('coach@example.com', ['ROLE_COACH']);

        $this->client->loginUser($athlete);
        $this->submitRequest('coach@example.com', ['role' => 'athlete']);

        self::assertResponseRedirects('/coaching');

        $relation = $this->em->getRepository(Coaching::class)->findOneBy([]);
        self::assertNotNull($relation);
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
        self::assertSame('coach@example.com', $relation->getCoach()?->getEmail());
        self::assertSame('athlete@example.com', $relation->getAthlete()?->getEmail());
        self::assertSame($athlete->getId(), $this->reload($relation)->getRequestedBy()?->getId());
    }

    public function testRequestTowardsNonCoachIsRejected(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $this->createUser('someone@example.com');

        $this->client->loginUser($athlete);
        $this->submitRequest('someone@example.com', ['role' => 'athlete']);

        self::assertCount(0, $this->em->getRepository(Coaching::class)->findAll());
    }

    public function testSelfRequestIsRejected(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);

        $this->client->loginUser($coach);
        $this->submitRequest('coach@example.com');

        self::assertCount(0, $this->em->getRepository(Coaching::class)->findAll());
    }

    /**
     * Sans champ `role`, un ROLE_COACH est placé du côté coach de la relation :
     * c'est le formulaire « Inviter un athlète » de la page unique.
     */
    public function testCoachInviteCreatesRelationInCoachDirection(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $this->createUser('athlete@example.com');

        $this->client->loginUser($coach);
        $this->submitRequest('athlete@example.com');

        self::assertResponseRedirects('/coaching');

        $relation = $this->em->getRepository(Coaching::class)->findOneBy([]);
        self::assertNotNull($relation);
        self::assertSame($coach->getId(), $relation->getCoach()?->getId());
        self::assertSame($coach->getId(), $relation->getRequestedBy()?->getId());
    }

    public function testRecipientCanAcceptRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'accept', $this->cardToken($relation, 'respond'));

        self::assertResponseRedirects('/coaching');
        self::assertSame(CoachingStatus::ACCEPTED, $this->reload($relation)->getStatus());
        self::assertNotNull($this->reload($relation)->getRespondedAt());
    }

    public function testRecipientCanDeclineRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'decline', $this->cardToken($relation, 'respond'));

        self::assertSame(CoachingStatus::DECLINED, $this->reload($relation)->getStatus());
    }

    /** L'émetteur ne peut pas valider sa propre demande (sinon auto-promotion). */
    public function testRequesterCannotAcceptOwnRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($coach);
        $this->respond($relation, 'accept');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
    }

    public function testThirdPartyCannotRespondOrEnd(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($intruder);

        $this->respond($relation, 'accept');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/coaching/'.$relation->getId().'/end', [
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEitherPartyCanEndRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED, $coach);

        $this->client->loginUser($athlete);
        $this->client->request('POST', '/coaching/'.$relation->getId().'/end', [
            '_token' => $this->cardToken($relation, 'end'),
        ]);

        self::assertResponseRedirects('/coaching');
        self::assertSame(CoachingStatus::ENDED, $this->reload($relation)->getStatus());
    }

    /**
     * #65 : les notes privées du coach partent avec la relation. La ligne survit
     * et peut se rouvrir : elle ne doit pas les rendre des mois plus tard.
     */
    public function testEndingRelationErasesCoachNotes(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED, $coach);
        $relation->setCoachNotes('Surveiller le genou gauche');
        $this->em->flush();

        $this->client->loginUser($athlete);
        $this->client->request('POST', '/coaching/'.$relation->getId().'/end', [
            '_token' => $this->cardToken($relation, 'end'),
        ]);

        self::assertResponseRedirects('/coaching');
        self::assertNull($this->reload($relation)->getCoachNotes());
    }

    /** Une relation terminée se réouvre sur la même ligne (UniqueConstraint). */
    public function testEndedRelationIsReopenedInPlace(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::ENDED, $coach);

        $this->client->loginUser($athlete);
        $this->submitRequest('coach@example.com', ['role' => 'athlete']);

        self::assertCount(1, $this->em->getRepository(Coaching::class)->findAll());
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
        self::assertSame($athlete->getId(), $this->reload($relation)->getRequestedBy()?->getId());
    }

    public function testDuplicatePendingRequestIsRejected(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->submitRequest('coach@example.com', ['role' => 'athlete']);

        self::assertCount(1, $this->em->getRepository(Coaching::class)->findAll());
    }

    // ------------------------------------------------- #79 : demandes en attente

    /**
     * #79 : l'invitation envoyée se range sous « Mes athlètes », pas en bas de
     * page, et la section ne dit plus « aucun athlète » pendant ce temps.
     */
    public function testSentInvitationIsListedUnderMyAthletes(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $this->createUser('athlete@example.com');

        $this->client->loginUser($coach);
        $this->submitRequest('athlete@example.com');
        $crawler = $this->client->request('GET', '/coaching');

        $athletes = $crawler->filter('#athletes');
        self::assertStringContainsString('athlete@example.com', $athletes->text());
        self::assertStringContainsString('En attente', $athletes->text());
        self::assertStringContainsString('Tu as proposé d\'entraîner cette personne', $athletes->text());
        self::assertStringNotContainsString('Aucun athlète pour l\'instant', $athletes->text());
        self::assertStringNotContainsString('Tu entraînes cette personne', $crawler->html());
        self::assertStringNotContainsString('Demandes envoyées', $crawler->html());
    }

    /** #79 : la demande d'un athlète à son coach se range sous « Mes coachs ». */
    public function testSentRequestToCoachIsListedUnderMyCoaches(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $athlete);

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/coaching');

        $coaches = $crawler->filter('#coachs');
        self::assertStringContainsString('coach@example.com', $coaches->text());
        self::assertStringContainsString('Tu as demandé à cette personne de t\'entraîner', $coaches->text());
        self::assertStringNotContainsString('Personne ne t\'entraîne', $coaches->text());
    }

    /** #79 : côté athlète, une proposition reçue ne se dit pas au présent. */
    public function testReceivedProposalIsNotWordedAsActive(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/coaching');

        self::assertStringContainsString('Cette personne propose de t\'entraîner', $crawler->html());
        self::assertStringNotContainsString('Cette personne t\'entraîne', $crawler->html());
    }

    /** #79 : un pseudo reçoit l'explication du site, et rien n'est créé. */
    public function testUsernameIsRejectedWithAnExplanation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setUsername('Trailer38');
        $this->em->flush();

        $this->client->loginUser($coach);
        $this->submitRequest('Trailer38');
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('n\'est pas une adresse email', $crawler->filter('.kd-flash--error')->text());
        self::assertCount(0, $this->em->getRepository(Coaching::class)->findAll());
        // Le champ ne laisse plus la bulle du navigateur trancher à sa place.
        self::assertSame('text', $crawler->filter('#coach_invite_email')->attr('type'));
    }

    /** #79 : l'initiateur retire sa demande, la ligne disparaît. */
    public function testRequesterCanCancelPendingRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);
        $id = $relation->getId();

        $this->client->loginUser($coach);
        $this->client->request('POST', '/coaching/'.$id.'/cancel', [
            '_token' => $this->cardToken($relation, 'cancel'),
        ]);

        self::assertResponseRedirects('/coaching');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Coaching::class)->find($id));
    }

    /** Le destinataire refuse, il n'annule pas : sinon le refus ne laisserait pas de trace. */
    public function testRecipientCannotCancelRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/coaching');
        self::assertCount(0, $crawler->filter('form[action$="/cancel"]'));

        $this->client->request('POST', '/coaching/'.$relation->getId().'/cancel', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
    }

    /** L'autre a répondu entre l'affichage et le clic : l'annulation ne défait pas une relation. */
    public function testAnsweredRequestCannotBeCancelled(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($coach);
        $token = $this->cardToken($relation, 'cancel');

        $this->reload($relation)->setStatus(CoachingStatus::ACCEPTED);
        $this->em->flush();

        $this->client->request('POST', '/coaching/'.$relation->getId().'/cancel', ['_token' => $token]);

        self::assertResponseRedirects('/coaching');
        self::assertSame(CoachingStatus::ACCEPTED, $this->reload($relation)->getStatus());
    }

    /**
     * #79 : le coach apprend l'acceptation sur son accueil, jusqu'à ce qu'il
     * ferme le bandeau.
     */
    public function testAcceptanceIsAnnouncedOnRequesterHomeUntilAcknowledged(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setUsername('Trailer38');
        $this->em->flush();
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'accept', $this->cardToken($relation, 'respond'));

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('Trailer38 a accepté ton invitation', $crawler->filter('.kd-home__coach')->text());

        $token = (string) $crawler->filter('form[action$="/acknowledge"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/coaching/'.$relation->getId().'/acknowledge', ['_token' => $token]);
        self::assertResponseRedirects('/');

        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('form[action$="/acknowledge"]'));
        self::assertNotNull($this->reload($relation)->getResponseSeenAt());
    }

    /** « Programmer » ferme le bandeau et ouvre la fiche de l'athlète. */
    public function testAcknowledgingCanOpenTheAthleteSheet(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'accept', $this->cardToken($relation, 'respond'));

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/');
        $token = (string) $crawler->filter('form[action$="/acknowledge"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/coaching/'.$relation->getId().'/acknowledge', [
            '_token' => $token,
            'then' => 'athlete',
        ]);

        self::assertResponseRedirects('/coach/athlete/'.$athlete->getId());
    }

    /** Un refus s'annonce aussi : une demande ne s'évapore plus en silence. */
    public function testDeclineIsAnnouncedOnRequesterHome(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $athlete);

        $this->client->loginUser($coach);
        $this->respond($relation, 'decline', $this->cardToken($relation, 'respond'));

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('a refusé ta demande de coaching', $crawler->filter('.kd-home__coach')->text());
    }

    /** Seul l'initiateur ferme le bandeau ; le destinataire n'en a pas. */
    public function testOnlyRequesterCanAcknowledge(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'accept', $this->cardToken($relation, 'respond'));

        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('form[action$="/acknowledge"]'));

        $this->client->request('POST', '/coaching/'.$relation->getId().'/acknowledge', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------- helpers
    //
    // Les jetons CSRF sont lus dans les formulaires rendus (convention du projet,
    // cf. CalendarControllerTest) : les générer hors requête n'a pas de session.
    // Pour les cas « tiers non autorisé », le voter tranche avant la vérification
    // CSRF, un jeton bidon suffit donc.

    /** @param array<string, string> $extra */
    private function submitRequest(string $email, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/coaching');
        $token = $crawler->filter('form[action$="/coaching/request"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/coaching/request', array_merge([
            '_token' => $token,
            'email' => $email,
        ], $extra));
    }

    private function respond(Coaching $relation, string $decision, string $token = 'invalid'): void
    {
        $this->client->request('POST', '/coaching/'.$relation->getId().'/respond', [
            '_token' => $token,
            'decision' => $decision,
        ]);
    }

    /**
     * Relit la relation en base. Le client repart d'un conteneur frais à chaque
     * requête : l'instance de test n'est plus gérée après, `refresh()` échouerait.
     */
    private function reload(Coaching $relation): Coaching
    {
        $reloaded = $this->em->getRepository(Coaching::class)->find($relation->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /** Jeton d'une action portée par une carte de relation, lu depuis /coaching. */
    private function cardToken(Coaching $relation, string $action): string
    {
        $crawler = $this->client->request('GET', '/coaching');

        return (string) $crawler
            ->filter(sprintf('form[action$="/coaching/%d/%s"] input[name="_token"]', $relation->getId(), $action))
            ->first()
            ->attr('value');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createCoaching(User $coach, User $athlete, CoachingStatus $status, User $requestedBy): Coaching
    {
        $coaching = (new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus($status)
            ->setRequestedBy($requestedBy);

        // Une réponse déjà donnée est déjà vue, comme après la migration (#79) :
        // sans quoi chaque relation de test poserait un bandeau sur l'accueil.
        if (CoachingStatus::PENDING !== $status) {
            $coaching->setRespondedAt(new \DateTimeImmutable());
            $coaching->setResponseSeenAt(new \DateTimeImmutable());
        }

        $this->em->persist($coaching);
        $this->em->flush();

        return $coaching;
    }
}
