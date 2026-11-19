<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\Friendship;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Service\FriendFeed;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Amis et flux de séances (#126) : la relation (demander, accepter, refuser,
 * annuler, retirer), ce que le flux montre et à qui, la copie d'une séance
 * d'ami « telle qu'il l'a faite », et la trace « Copiée par » chez l'auteur.
 */
final class FriendControllerTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testPagesRequireALogin(): void
    {
        foreach (['/friends', '/friends/feed'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login');
        }
    }

    // ------------------------------------------------------------- relation

    /** Pseudo ou email EXACTS : « cam » ne liste pas Camille, pas d'annuaire. */
    public function testSearchFindsOneAccountByExactPseudoOrEmailOnly(): void
    {
        $me = $this->createUser('me@example.com');
        $this->createUser('camille@example.com', 'Camille_run');
        $this->client->loginUser($me);

        $crawler = $this->client->request('GET', '/friends?q=cam');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.kd-friends__found'));
        self::assertStringContainsString('Aucun compte avec ce pseudo', $crawler->filter('.kd-friends__result')->text());

        // Insensible à la casse, par la collation du pseudo.
        $crawler = $this->client->request('GET', '/friends?q=camille_RUN');
        self::assertSame('Camille_run', $crawler->filter('.kd-friends__found')->text());

        $crawler = $this->client->request('GET', '/friends?q=camille@example.com');
        self::assertSame('Camille_run', $crawler->filter('.kd-friends__found')->text());

        // Soi-même ne se trouve pas.
        $crawler = $this->client->request('GET', '/friends?q=me@example.com');
        self::assertCount(0, $crawler->filter('.kd-friends__found'));
    }

    public function testRequestThenAcceptMakesFriendsAndTheMenuCountsTheRequest(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com', 'Léa');

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends?q=Léa');
        $this->client->submit($crawler->selectButton('Demander')->form());
        self::assertResponseRedirects();

        $friendship = $this->em->getRepository(Friendship::class)->findOneBy([]);
        self::assertNotNull($friendship);
        self::assertTrue($friendship->isPending());
        self::assertSame($me->getId(), $friendship->getRequester()?->getId());

        // Côté émetteur : la demande envoyée, annulable.
        $crawler = $this->client->request('GET', '/friends');
        self::assertStringContainsString('Envoyée', $crawler->filter('.kd-friends__section')->first()->text());

        // Côté destinataire : la pastille du menu, puis l'acceptation.
        $this->client->loginUser($lea);
        $crawler = $this->client->request('GET', '/friends');
        self::assertSame('1', $crawler->filter('.kd-usermenu__count')->text());
        $this->client->submit($crawler->selectButton('Accepter')->form());
        self::assertResponseRedirects('/friends');

        $this->em->clear();
        $friendship = $this->mustFind(Friendship::class, $friendship->getId());
        self::assertTrue($friendship->isAccepted());
        self::assertNotNull($friendship->getAcceptedAt());

        $crawler = $this->client->request('GET', '/friends');
        self::assertCount(0, $crawler->filter('.kd-usermenu__count'));
        self::assertStringContainsString('me@example.com', $crawler->filter('.kd-friends__list')->text());
    }

    /** Deux personnes qui se cherchent en même temps sont amies, pas en attente. */
    public function testRequestingSomeoneWhoAlreadyAskedAcceptsTheirRequest(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com');
        $friendship = $this->befriend($lea, $me, accepted: false);

        $this->client->loginUser($me);
        $token = $this->tokenFrom('/friends?q=lea@example.com', '/friends/request');
        $this->client->request('POST', '/friends/request', ['q' => 'lea@example.com', '_token' => $token]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->mustFind(Friendship::class, $friendship->getId())->isAccepted());
        self::assertSame(1, $this->em->getRepository(Friendship::class)->count([]));
    }

    public function testDeclineAndCancelEraseTheRequest(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com');
        $tom = $this->createUser('tom@example.com');
        $received = $this->befriend($lea, $me, accepted: false);
        $sent = $this->befriend($me, $tom, accepted: false);

        $this->client->loginUser($me);
        $token = $this->tokenFrom('/friends', '/friends/'.$received->getId().'/respond');
        $this->client->request('POST', '/friends/'.$received->getId().'/respond', ['decision' => 'decline', '_token' => $token]);
        self::assertResponseRedirects('/friends');
        $token = $this->tokenFrom('/friends', '/friends/'.$sent->getId().'/remove');
        $this->client->request('POST', '/friends/'.$sent->getId().'/remove', ['_token' => $token]);
        self::assertResponseRedirects('/friends');

        self::assertSame(0, $this->em->getRepository(Friendship::class)->count([]));
    }

    /** Seul le destinataire répond, seul l'émetteur annule, un tiers n'existe pas. */
    public function testOnlyTheRightSideActsOnARequest(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com');
        $stranger = $this->createUser('x@example.com');
        $sent = $this->befriend($me, $lea, accepted: false);

        $this->client->loginUser($me);
        $this->client->request('POST', '/friends/'.$sent->getId().'/respond', ['decision' => 'accept', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($lea);
        $this->client->request('POST', '/friends/'.$sent->getId().'/remove', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($stranger);
        $this->client->request('POST', '/friends/'.$sent->getId().'/respond', ['decision' => 'accept', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/friends/'.$sent->getId().'/remove', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertTrue($this->mustFind(Friendship::class, $sent->getId())->isPending());
    }

    // ----------------------------------------------------------------- flux

    /**
     * Le flux montre les séances de salle FAITES avec réalisé, des amis et de
     * soi ; ni une séance prévue, ni une séance cochée sans réalisé, ni celle
     * d'un inconnu. Les exercices perso d'un ami se lisent par leur nom.
     */
    public function testFeedShowsDoneLoggedSessionsOfFriendsAndMine(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com', 'Léa Martin');
        $stranger = $this->createUser('x@example.com', 'Inconnu');
        $this->befriend($me, $lea);

        $bench = $this->createExercise(null, 'Développé couché barre');
        $rotations = $this->createExercise($lea, 'Rotations d\'épaules à l\'élastique');

        $this->logSession($lea, 'Upper A', 'today', [[$rotations, [[15, null]]], [$bench, [[6, 92.5], [6, 92.5], [6, 92.5], [6, 92.5]]]], note: 'Enfin 92,5 sur les 6 reps.');
        $this->logSession($me, 'Lower A', 'today', [[$bench, [[5, 60.0]]]]);
        $this->logSession($stranger, 'Séance secrète', 'today', [[$bench, [[5, 60.0]]]]);
        $this->logSession($lea, 'Prévue', 'today', [[$bench, [[5, 60.0]]]], status: ScheduledStatus::PLANNED);
        $this->logSession($lea, 'Cochée sans réalisé', 'today', []);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertResponseIsSuccessful();

        $titles = $crawler->filter('.kd-feedcard__title')->each(static fn (Crawler $n): string => $n->text());
        sort($titles);
        self::assertSame(['Lower A', 'Upper A'], $titles);

        $lea = $this->card($crawler, 'Upper A');
        self::assertStringContainsString('Rotations d\'épaules à l\'élastique', $lea->text());
        self::assertCount(1, $lea->filter('.kd-feedex__perso'));
        self::assertStringContainsString('4 × 6 · 92,5 kg', $lea->text());
        self::assertStringContainsString('Enfin 92,5 sur les 6 reps.', $lea->filter('.kd-feedcard__note')->text());
        self::assertCount(1, $lea->filter('button:contains("Copier dans mes séances")'));

        $mine = $this->card($crawler, 'Lower A');
        self::assertStringContainsString('Toi', $mine->filter('.kd-feedcard__who')->text());
        self::assertCount(1, $mine->filter('button:contains("Masquer pour mes amis")'));
    }

    /** Couper le partage retire tout, l'ancien compris ; masquer retire une séance. */
    public function testSharingOffAndHiddenSessionsLeaveTheFriendsFeedOnly(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com', 'Léa');
        $this->befriend($me, $lea);
        $bench = $this->createExercise(null, 'Développé couché barre');
        $hidden = $this->logSession($lea, 'Masquée', 'today', [[$bench, [[5, 60.0]]]]);
        $this->logSession($lea, 'Visible', 'yesterday', [[$bench, [[5, 60.0]]]]);

        // Léa masque une séance : elle la voit encore, en retrait, pas moi.
        $this->client->loginUser($lea);
        $token = $this->tokenFrom('/friends/feed', '/friends/feed/'.$hidden->getId().'/visibility');
        $this->client->request('POST', '/friends/feed/'.$hidden->getId().'/visibility', ['hidden' => '1', '_token' => $token]);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertStringContainsString('est masquée pour tes amis', $crawler->filter('.kd-feedcard--hidden')->text());

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertSame(['Visible'], $crawler->filter('.kd-feedcard__title')->each(static fn (Crawler $n): string => $n->text()));

        // Léa coupe son partage : plus rien, ni l'ancien.
        $this->client->loginUser($lea);
        $token = $this->tokenFrom('/friends', '/friends/sharing');
        $this->client->request('POST', '/friends/sharing', ['share' => '0', '_token' => $token]);
        self::assertResponseRedirects();

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertCount(0, $crawler->filter('.kd-feedcard__title'));
        self::assertStringContainsString('Rien à lire', $crawler->filter('.kd-feedempty')->text());

        // Et je ne peux pas masquer la séance de quelqu'un d'autre.
        $this->client->request('POST', '/friends/feed/'.$hidden->getId().'/visibility', ['hidden' => '0', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFeedFiltersByFriendAndPages(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com', 'Léa');
        $tom = $this->createUser('tom@example.com', 'Tom');
        $this->befriend($me, $lea);
        $this->befriend($tom, $me);
        $bench = $this->createExercise(null, 'Développé couché barre');

        for ($i = 0; $i < FriendFeed::PAGE_SIZE; ++$i) {
            $this->logSession($lea, 'Léa '.$i, sprintf('-%d days', $i), [[$bench, [[5, 60.0]]]]);
        }
        $this->logSession($tom, 'Tom 0', '-20 days', [[$bench, [[5, 60.0]]]]);
        $stranger = $this->createUser('x@example.com');
        $this->logSession($stranger, 'Secret', 'today', [[$bench, [[5, 60.0]]]]);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertCount(FriendFeed::PAGE_SIZE, $crawler->filter('.kd-feedcard__title'));
        $older = $crawler->filter('a.kd-feed__older');
        self::assertCount(1, $older);

        // Le lien vise son frame : la page suivante s'y charge, sous la liste.
        self::assertSame('feed-page-2', $older->attr('data-turbo-frame'));
        self::assertCount(1, $crawler->filter('turbo-frame#feed-page-2 > a.kd-feed__older'));

        // Sans JS, c'est une page entière.
        $crawler = $this->client->request('GET', (string) $older->attr('href'));
        self::assertSame(['Tom 0'], $crawler->filter('.kd-feedcard__title')->each(static fn (Crawler $n): string => $n->text()));
        self::assertCount(1, $crawler->filter('.kd-pagehead'));

        // Par son frame, le frame seul, sans redire le jour déjà affiché.
        $tomDay = (new \DateTimeImmutable('-20 days'))->format('Y-m-d');
        $crawler = $this->client->request('GET', '/friends/feed?page=2&jour='.$tomDay, server: ['HTTP_TURBO_FRAME' => 'feed-page-2']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.kd-pagehead'));
        self::assertCount(1, $crawler->filter('turbo-frame#feed-page-2'));
        self::assertSame(['Tom 0'], $crawler->filter('.kd-feedcard__title')->each(static fn (Crawler $n): string => $n->text()));
        self::assertCount(0, $crawler->filter('.kd-feed__day'));

        $crawler = $this->client->request('GET', '/friends/feed?ami='.$tom->getId());
        self::assertSame(['Tom 0'], $crawler->filter('.kd-feedcard__title')->each(static fn (Crawler $n): string => $n->text()));

        // Un filtre hors droits ne filtre rien, il n'ouvre rien non plus.
        $crawler = $this->client->request('GET', '/friends/feed?ami='.$stranger->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Secret', $crawler->filter('.kd-feed__list')->text());
    }

    /**
     * La règle de l'écran de clôture : une charge plus lourde qu'avant est un
     * record, la première fois n'en est pas un, et à charge égale les
     * répétitions départagent.
     */
    public function testFeedAnnouncesRecordsBrokenInTheSession(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com', 'Léa');
        $this->befriend($me, $lea);
        $bench = $this->createExercise(null, 'Développé couché barre');
        $squat = $this->createExercise(null, 'Squat arrière barre');

        $this->logSession($lea, 'Avant', '-7 days', [[$bench, [[6, 90.0]]], [$squat, [[3, 140.0]]]]);
        $this->logSession($lea, 'Upper A', 'today', [[$bench, [[6, 92.5], [5, 92.5]]], [$squat, [[3, 140.0]]]]);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends/feed');

        $card = $this->card($crawler, 'Upper A');
        $records = $card->filter('.kd-feedcard__record');
        self::assertCount(1, $records);
        self::assertStringContainsString('Développé couché barre · 92,5 kg × 6 (+2,5 kg)', $records->text());
        self::assertCount(1, $card->filter('.kd-feedex__record'));

        // La première séance n'a rien battu : elle n'avait pas de marque.
        self::assertCount(0, $this->card($crawler, 'Avant')->filter('.kd-feedcard__record'));
    }

    // ---------------------------------------------------------------- copie

    /**
     * Copiée telle que faite : le réalisé devient le prescrit, l'exercice
     * sauté et l'exercice non fait sortent, l'exercice hors programme entre, et
     * l'exercice perso de l'ami est recopié dans ma bibliothèque.
     */
    public function testCopyBuildsALibraryWorkoutFromWhatWasDone(): void
    {
        $me = $this->createUser('me@example.com', 'Antonin');
        $lea = $this->createUser('lea@example.com', 'Léa Martin');
        $this->befriend($me, $lea);

        $bench = $this->createExercise(null, 'Développé couché barre');
        $curl = $this->createExercise(null, 'Curl incliné haltères');
        $dips = $this->createExercise(null, 'Dips');
        $rotations = $this->createExercise($lea, 'Rotations d\'épaules');
        $row = $this->createExercise($lea, 'Tirage poulie basse');

        // La séance prévue : développé 3 × 8 à 80, curl 3 × 12, dips 3 × 10.
        $workout = (new Workout())->setOwner($lea)->setTitle('Upper A')->setSlug('upper-a-'.uniqid());
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $prescribed = [];
        foreach ([[$bench, 8, 80.0], [$curl, 12, 12.0], [$dips, 10, null]] as $position => [$exercise, $reps, $weight]) {
            $pe = (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setPosition($position)
                ->setSets(3)
                ->setReps($reps)
                ->setWeightKg($weight);
            $block->addPrescribedExercise($pe);
            $prescribed[] = $pe;
        }
        $workout->addBlock($block);
        $this->em->persist($workout);
        $this->em->flush();

        // Faite : développé 4 × 6 à 92,5 (prévu), curl sauté, dips jamais
        // consignés, deux exercices perso ajoutés hors programme.
        $session = $this->logSession($lea, null, 'today', [
            [$bench, [[6, 92.5], [6, 92.5], [6, 92.5], [6, 92.5]], $prescribed[0]],
            [$curl, [], $prescribed[1], true],
            [$rotations, [[15, null], [15, null]]],
            [$row, [[10, 60.0], [10, 60.0]]],
        ], workout: $workout);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/friends/feed');
        $card = $this->card($crawler, 'Upper A');
        $dialog = $card->filter('dialog');
        self::assertSame('Upper A (Léa)', $dialog->filter('input[name="title"]')->attr('value'));
        self::assertStringContainsString('2 exos perso de Léa ajoutés à tes exercices', $dialog->text());

        $form = $dialog->selectButton('Copier')->form(['title' => 'Upper A de Léa']);
        $this->client->submit($form);
        self::assertResponseRedirects('/friends/feed#seance-'.$session->getId());

        $this->em->clear();
        $copy = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $me]);
        self::assertNotNull($copy);
        self::assertSame('Upper A de Léa', $copy->getTitle());
        self::assertSame($session->getId(), $copy->getCopiedFromSession()?->getId());
        self::assertFalse($copy->isPlanLocal());

        $rows = [];
        foreach ($copy->getBlocks() as $copyBlock) {
            foreach ($copyBlock->getPrescribedExercises() as $pe) {
                $exercise = $pe->getExercise();
                self::assertNotNull($exercise);
                $rows[(string) $exercise->getName()] = [$pe->getSets(), $pe->getReps(), $pe->getWeightKg(), $exercise->getOwner()?->getId()];
            }
        }

        self::assertSame([
            'Développé couché barre' => [4, 6, 92.5, null],
            'Rotations d\'épaules' => [2, 15, null, $me->getId()],
            'Tirage poulie basse' => [2, 10, 60.0, $me->getId()],
        ], $rows);

        // La carte le dit, et l'ouvre.
        $crawler = $this->client->request('GET', '/friends/feed');
        $card = $this->card($crawler, 'Upper A');
        self::assertStringContainsString('Copiée dans tes séances : Upper A de Léa', $card->filter('.kd-feedcard__copied')->text());
        self::assertCount(1, $card->filter('a[href="/workout/'.$copy->getId().'"]'));

        // Chez Léa : « Copiée par Antonin », dans le flux et sur la séance.
        $this->client->loginUser($this->mustFind(User::class, $lea->getId()));
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertStringContainsString('Copiée par Antonin le', $this->card($crawler, 'Upper A')->filter('.kd-feedcard__copiedby')->text());
        $crawler = $this->client->request('GET', '/schedule/'.$session->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Copiée par Antonin le', $crawler->filter('.kd-copiedby')->text());
    }

    /** Une séance libre (sans séance d'origine) se copie en un bloc principal. */
    public function testCopyOfAFreeSessionMakesOneMainBlock(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com', 'Léa');
        $this->befriend($me, $lea);
        $bench = $this->createExercise(null, 'Développé couché barre');
        $session = $this->logSession($lea, 'Séance libre', 'today', [[$bench, [[5, 80.0], [3, 90.0]]]]);

        $this->client->loginUser($me);
        $token = $this->tokenFrom('/friends/feed', '/friends/feed/'.$session->getId().'/copy');
        $this->client->request('POST', '/friends/feed/'.$session->getId().'/copy', ['title' => '', '_token' => $token]);
        self::assertResponseRedirects();

        $this->em->clear();
        $copy = $this->em->getRepository(Workout::class)->findOneBy(['owner' => $me]);
        self::assertNotNull($copy);
        self::assertSame('Séance libre (Léa)', $copy->getTitle());
        self::assertCount(1, $copy->getBlocks());
        $block = $copy->getBlocks()->first();
        self::assertNotFalse($block);
        self::assertSame(BlockRole::MAIN, $block->getRole());
        $pe = $block->getPrescribedExercises()->first();
        self::assertNotFalse($pe);
        // Séries différentes : mode détaillé, une ligne par série.
        self::assertCount(2, $pe->getDetailedSets());
    }

    /**
     * Par Turbo, copier, masquer et repartager remplacent la seule carte
     * touchée, à sa place, et le message arrive en toast : la page ne se
     * recharge pas et ne remonte pas en haut.
     */
    public function testFeedGesturesAnswerWithTheCardOnlyUnderTurbo(): void
    {
        $me = $this->createUser('me@example.com', 'Antonin');
        $lea = $this->createUser('lea@example.com', 'Léa');
        $this->befriend($me, $lea);
        $bench = $this->createExercise(null, 'Développé couché barre');
        $hers = $this->logSession($lea, 'Push', 'today', [[$bench, [[5, 80.0]]]]);
        $mine = $this->logSession($me, 'Pull', 'yesterday', [[$bench, [[5, 60.0]]]]);
        $stream = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

        $this->client->loginUser($me);
        $copyToken = $this->tokenFrom('/friends/feed', '/friends/feed/'.$hers->getId().'/copy');
        $hideToken = $this->tokenFrom('/friends/feed', '/friends/feed/'.$mine->getId().'/visibility');

        $crawler = $this->client->request('POST', '/friends/feed/'.$hers->getId().'/copy', ['title' => 'Push de Léa', 'ami' => '', 'page' => '2', '_token' => $copyToken], server: $stream);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $replace = $crawler->filter('turbo-stream[action="replace"][target="seance-'.$hers->getId().'"]');
        self::assertCount(1, $replace);
        self::assertStringContainsString('Copiée dans tes séances : <strong>Push de Léa</strong>', $replace->html());
        // Le message arrive en toast, dans la pile du coin.
        self::assertStringContainsString('« Push de Léa » est dans tes séances.', $crawler->filter('turbo-stream[targets=".kd-toasts"]')->html());
        self::assertSame(1, $this->em->getRepository(Workout::class)->count(['owner' => $me]));

        $crawler = $this->client->request('POST', '/friends/feed/'.$mine->getId().'/visibility', ['hidden' => '1', 'page' => '2', '_token' => $hideToken], server: $stream);
        self::assertResponseIsSuccessful();
        $replace = $crawler->filter('turbo-stream[target="seance-'.$mine->getId().'"]');
        self::assertStringContainsString('kd-feedcard--hidden', $replace->html());
        self::assertStringContainsString('name="page" value="2"', $replace->html());

        $crawler = $this->client->request('POST', '/friends/feed/'.$mine->getId().'/visibility', ['hidden' => '0', '_token' => $hideToken], server: $stream);
        $replace = $crawler->filter('turbo-stream[target="seance-'.$mine->getId().'"]');
        self::assertStringContainsString('Masquer pour mes amis', $replace->html());

        // Le message a été lu par le stream : il ne ressort pas à la page suivante.
        $crawler = $this->client->request('GET', '/friends/feed');
        self::assertCount(0, $crawler->filter('.kd-toasts .kd-flash'));
    }

    /** Ce qu'on ne voit pas ne se copie pas : pas ami, masquée, la sienne. */
    public function testCopyIsGuardedByTheFeed(): void
    {
        $me = $this->createUser('me@example.com');
        $lea = $this->createUser('lea@example.com');
        $stranger = $this->createUser('x@example.com');
        $this->befriend($me, $lea);
        $bench = $this->createExercise(null, 'Développé couché barre');

        $strangers = $this->logSession($stranger, 'A', 'today', [[$bench, [[5, 60.0]]]]);
        $hidden = $this->logSession($lea, 'B', 'today', [[$bench, [[5, 60.0]]]]);
        $hidden->setHiddenFromFriends(true);
        $this->em->flush();
        $mine = $this->logSession($me, 'C', 'today', [[$bench, [[5, 60.0]]]]);

        $this->client->loginUser($me);
        foreach ([$strangers, $hidden, $mine] as $session) {
            $this->client->request('POST', '/friends/feed/'.$session->getId().'/copy', ['title' => 'x', '_token' => 'x']);
            self::assertResponseStatusCodeSame(404);
        }

        self::assertSame(0, $this->em->getRepository(Workout::class)->count(['owner' => $me]));
    }

    // -------------------------------------------------------------- helpers

    private function card(Crawler $crawler, string $title): Crawler
    {
        $card = $crawler->filter('article.kd-feedcard')->reduce(
            static fn (Crawler $n): bool => $n->filter('.kd-feedcard__title')->count() > 0 && $n->filter('.kd-feedcard__title')->text() === $title,
        );
        self::assertCount(1, $card, sprintf('Carte « %s » introuvable dans le flux.', $title));

        return $card;
    }

    /** Le jeton CSRF du formulaire dont l'action finit par `$action`, lu sur `$url`. */
    private function tokenFrom(string $url, string $action): string
    {
        $crawler = $this->client->request('GET', $url);
        $input = $crawler->filter(sprintf('form[action$="%s"] input[name="_token"]', $action));
        self::assertGreaterThan(0, $input->count(), sprintf('Aucun formulaire « %s » sur %s.', $action, $url));

        return (string) $input->first()->attr('value');
    }

    /**
     * @param list<array{0: Exercise, 1: list<array{0: int, 1: float|null}>, 2?: PrescribedExercise, 3?: bool}> $exercises
     */
    private function logSession(User $owner, ?string $title, string $date, array $exercises, ScheduledStatus $status = ScheduledStatus::DONE, ?string $note = null, ?Workout $workout = null): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status)
            ->setCompletionNotes($note);

        $position = 0;
        foreach ($exercises as $entry) {
            [$exercise, $sets] = $entry;
            $logged = (new LoggedExercise())
                ->setExercise($exercise)
                ->setExerciseName((string) $exercise->getName())
                ->setSourcePrescribedExercise($entry[2] ?? null)
                ->setSkipped($entry[3] ?? false)
                ->setPosition(++$position);

            $setPosition = 0;
            foreach ($sets as [$reps, $weight]) {
                $logged->addLoggedSet((new LoggedSet())
                    ->setPosition(++$setPosition)
                    ->setSetType(SetType::NORMAL)
                    ->setReps($reps)
                    ->setWeightKg($weight));
            }

            $scheduled->addLoggedExercise($logged);
        }

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function befriend(User $requester, User $addressee, bool $accepted = true): Friendship
    {
        $friendship = (new Friendship())->setRequester($requester)->setAddressee($addressee);
        if ($accepted) {
            $friendship->accept();
        }
        $this->em->persist($friendship);
        $this->em->flush();

        return $friendship;
    }

    private function createExercise(?User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM)
            ->setTargetAreas([TargetArea::CHEST]);
        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    private function createUser(string $email, ?string $username = null): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setUsername($username);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
