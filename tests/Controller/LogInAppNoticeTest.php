<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Repository\ScheduledWorkoutRepository;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Les charges se saisissent dans l'app » (#63).
 *
 * Le réalisé de muscu se logue sur le téléphone (CLAUDE.md §3). Le web ne le
 * disait nulle part où un chiffre de salle restait à zéro, et une séance faite
 * à « 0 kg » passait pour un bug. Ce qui se joue ici :
 *
 * 1. **Le compte est juste.** Une séance de force (au moins un exercice `gym`,
 *    la définition de `TrackableSchedule`), cochée faite, sans aucune ligne
 *    consignée. Ni une séance planifiée, ni une sortie de cardio, ni une séance
 *    consignée, ni la séance d'un autre.
 * 2. **La phrase apparaît là où le chiffre manque**, avec un lien vers `/app` :
 *    vue d'ensemble des statistiques (avec « assiduité, pas en volume »),
 *    journal, page et modale de la séance datée, fiche coach.
 * 3. **Côté coach, à la troisième personne** : « ses charges ».
 * 4. **Rien n'est comblé** : le tonnage reste à zéro.
 */
final class LogInAppNoticeTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testTheCountOnlyTakesDoneStrengthSessionsWithoutALog(): void
    {
        $me = $this->createUser('moi@example.com');
        $other = $this->createUser('autre@example.com');

        // Comptées : deux séances de force faites sans réalisé, dont une mixte
        // (force + cardio), qui ne compte qu'une fois.
        $this->createSession($me, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE);
        $this->createSession($me, [ActivityType::GYM, ActivityType::RUNNING], '2026-03-03', ScheduledStatus::DONE);
        // Écartées : consignée, planifiée, manquée, cardio pur, séance d'un autre.
        $this->createSession($me, [ActivityType::GYM], '2026-03-04', ScheduledStatus::DONE, logged: true);
        $this->createSession($me, [ActivityType::GYM], '2026-03-05', ScheduledStatus::PLANNED);
        $this->createSession($me, [ActivityType::GYM], '2026-03-06', ScheduledStatus::MISSED);
        $this->createSession($me, [ActivityType::RUNNING], '2026-03-07', ScheduledStatus::DONE);
        $this->createSession($other, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE);

        $repository = static::getContainer()->get(ScheduledWorkoutRepository::class);

        self::assertSame(2, $repository->countDoneStrengthWithoutLogForOwner($me, null, null));
        // La fenêtre borne le compte, bornes comprises.
        self::assertSame(1, $repository->countDoneStrengthWithoutLogForOwner(
            $me,
            new \DateTimeImmutable('2026-03-03'),
            new \DateTimeImmutable('2026-03-31'),
        ));
    }

    public function testTheStatsOverviewExplainsTheZeroTonnage(): void
    {
        $me = $this->createUser('moi@example.com');
        $this->createSession($me, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/profile/stats?range=all');

        self::assertResponseIsSuccessful();
        $notice = $crawler->filter('.kd-applog')->text();
        self::assertStringContainsString('1 séance de force faite sans détail : les charges se saisissent dans l\'app Kadens', $notice);
        self::assertStringContainsString('Elle compte en assiduité, pas en volume.', $notice);
        self::assertSelectorExists('.kd-applog a[href="/app"]');
        // Le tonnage n'est pas comblé par le prescrit.
        self::assertStringContainsString('0 kg', $crawler->filter('.kd-grid--4')->text());
    }

    public function testNoNoticeWhenEveryStrengthSessionIsLogged(): void
    {
        $me = $this->createUser('moi@example.com');
        $this->createSession($me, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE, logged: true);

        $this->client->loginUser($me);
        $this->client->request('GET', '/profile/stats?range=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-applog');
    }

    public function testTheJournalSaysWhatItDoesNotList(): void
    {
        $me = $this->createUser('moi@example.com');
        $this->createSession($me, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE);
        $this->createSession($me, [ActivityType::GYM], '2026-03-09', ScheduledStatus::DONE);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/profile/log?range=all');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-applog'));
        self::assertStringContainsString('2 séances de force faites sans détail', $crawler->filter('.kd-applog')->text());
    }

    public function testAnEmptyJournalPointsToTheApp(): void
    {
        $this->client->loginUser($this->createUser('neuf@example.com'));
        $crawler = $this->client->request('GET', '/profile/log');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('remonte du téléphone', $crawler->filter('.kd-empty')->text());
        self::assertSelectorExists('.kd-empty .kd-applog a[href="/app"]');
    }

    public function testTheDatedSessionSaysThereIsNoDetail(): void
    {
        $me = $this->createUser('moi@example.com');
        $done = $this->createSession($me, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE);
        $logged = $this->createSession($me, [ActivityType::GYM], '2026-03-03', ScheduledStatus::DONE, logged: true);
        $planned = $this->createSession($me, [ActivityType::GYM], '2026-03-04', ScheduledStatus::PLANNED);
        $run = $this->createSession($me, [ActivityType::RUNNING], '2026-03-05', ScheduledStatus::DONE);

        $this->client->loginUser($me);

        $crawler = $this->client->request('GET', '/schedule/'.$done->getId());
        self::assertStringContainsString('Pas de détail : les charges se saisissent', $crawler->filter('#schedule-summary .kd-applog')->text());

        // Consignée : le bilan dit ce qui l'est, la phrase sur l'app n'a plus
        // rien à expliquer (#91 : « le réalisé, ou la phrase sur l'app »).
        $this->client->request('GET', '/schedule/'.$logged->getId());
        self::assertSelectorNotExists('#schedule-summary .kd-applog');
        self::assertSelectorTextContains('#schedule-summary', 'de travail');

        // À venir : on dit où saisir, pas qu'il manque quelque chose.
        $crawler = $this->client->request('GET', '/schedule/'.$planned->getId());
        self::assertStringStartsWith('Les charges se saisissent', $crawler->filter('#schedule-summary .kd-applog')->text());

        // Une sortie ne se logue pas : aucune phrase.
        $this->client->request('GET', '/schedule/'.$run->getId());
        self::assertSelectorNotExists('.kd-applog');
    }

    /** Marquer faite depuis la page re-rend le bilan avec la phrase à jour. */
    public function testTheStatusStreamCarriesTheNotice(): void
    {
        $me = $this->createUser('moi@example.com');
        $planned = $this->createSession($me, [ActivityType::GYM], '2026-03-04', ScheduledStatus::PLANNED);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/schedule/'.$planned->getId());
        $token = $crawler->filter('#schedule-done input[name="_token"]')->attr('value');

        $this->client->request('POST', '/schedule/'.$planned->getId().'/status', [
            '_token' => $token,
            'return' => 'schedule',
            'status' => 'done',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pas de détail : les charges se saisissent', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCalendarModalOfAStrengthSessionPointsToTheApp(): void
    {
        $me = $this->createUser('moi@example.com');
        $gym = $this->createSession($me, [ActivityType::GYM], '2026-03-02', ScheduledStatus::PLANNED);
        $run = $this->createSession($me, [ActivityType::RUNNING], '2026-03-03', ScheduledStatus::PLANNED);

        $this->client->loginUser($me);
        $this->client->request('GET', '/calendar/2026/3');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#cal-event-'.$gym->getId().' dialog .kd-applog a[href="/app"]');
        self::assertSelectorNotExists('#cal-event-'.$run->getId().' .kd-applog');
    }

    public function testTheCoachReadsItInTheThirdPerson(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->accept($coach, $athlete);
        $done = $this->createSession($athlete, [ActivityType::GYM], '2026-03-02', ScheduledStatus::DONE);

        $this->client->loginUser($coach);

        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 séance de force faite sans détail : ses charges', $crawler->filter('.kd-applog')->text());
        self::assertStringNotContainsString('remonte de son téléphone', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/log?range=all');
        self::assertStringContainsString('ses charges', $crawler->filter('.kd-applog')->text());

        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId().'/stats?range=all');
        self::assertStringContainsString('ses charges', $crawler->filter('.kd-applog')->text());

        $crawler = $this->client->request('GET', '/schedule/'.$done->getId());
        self::assertStringContainsString('Pas de détail : ses charges', $crawler->filter('#schedule-summary .kd-applog')->text());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Une séance datée dont le prescrit compte un exercice par activité donnée.
     *
     * @param list<ActivityType> $activities
     */
    private function createSession(User $owner, array $activities, string $date, ScheduledStatus $status, bool $logged = false): ScheduledWorkout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date)
            ->setSlug('seance-'.bin2hex(random_bytes(4)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $first = null;
        foreach ($activities as $position => $activity) {
            $exercise = (new Exercise())
                ->setOwner($owner)
                ->setName($activity->value.' '.$position)
                ->setActivity($activity);
            $this->em->persist($exercise);

            $prescribed = (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition($position)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(8);
            $block->addPrescribedExercise($prescribed);
            $first ??= [$exercise, $prescribed];
        }
        $workout->addBlock($block);
        $this->em->persist($block);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);

        if ($logged && null !== $first) {
            $scheduled->addLoggedExercise(
                (new LoggedExercise())
                    ->setExercise($first[0])
                    ->setExerciseName((string) $first[0]->getName())
                    ->setSourcePrescribedExercise($first[1])
                    ->setPosition(0)
                    ->addLoggedSet((new LoggedSet())->setPosition(0)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(80.0)),
            );
        }

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function accept(User $coach, User $athlete): void
    {
        $this->em->persist(
            (new Coaching())
                ->setCoach($coach)
                ->setAthlete($athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($coach)
                ->setRespondedAt(new \DateTimeImmutable()),
        );
        $this->em->flush();
    }
}
