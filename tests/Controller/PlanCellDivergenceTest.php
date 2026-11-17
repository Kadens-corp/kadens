<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Service\WorkoutCloner;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * #93 : le coach fait progresser un plan case par case. La grille signale celles
 * qui répètent encore la fois d'avant (« = S1 »), et l'édition rapide propose de
 * renommer une case dont le titre chiffré (« 6 x 400 m ») ne suit plus le contenu.
 */
final class PlanCellDivergenceTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $coach;
    private User $athlete;
    private Workout $intervals;
    private PlanTemplate $plan;
    private Workout $week1;
    private Workout $week2;
    private Workout $week3;
    private Workout $week3Thursday;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->coach = $this->user('coach@example.com', ['ROLE_COACH']);
        $this->athlete = $this->user('athlete@example.com')->setUsername('Trailer38');
        $this->em->persist(
            (new Coaching())
                ->setCoach($this->coach)
                ->setAthlete($this->athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($this->coach)
                ->setRespondedAt(new \DateTimeImmutable())
        );

        $run = (new Exercise())->setName('Course')->setActivity(ActivityType::RUNNING);
        $this->em->persist($run);

        $this->intervals = (new Workout())
            ->setOwner($this->athlete)
            ->setTitle('Fractionné 6 x 400 m')
            ->setSlug('fractionne-'.bin2hex(random_bytes(3)))
            ->addBlock(
                (new Block())->setRole(BlockRole::MAIN)->setRounds(6)->setPosition(0)
                    ->addPrescribedExercise(
                        (new PrescribedExercise())
                            ->setExercise($run)
                            ->setPosition(0)
                            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                            ->setDistanceMeters(400)
                    )
            );
        $this->em->persist($this->intervals);

        $this->plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle('Prépa trail')
            ->setDurationWeeks(3)
            ->setSlug('prepa-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->plan);
        $this->em->flush();

        // La même séance posée chaque mardi, et deux fois en semaine 3, forkée
        // comme le fait la pose : toutes identiques au départ.
        $this->week1 = $this->cell(1, 2);
        $this->week2 = $this->cell(2, 2);
        $this->week3 = $this->cell(3, 2);
        $this->week3Thursday = $this->cell(3, 4);
    }

    public function testACellThatRepeatsTheTimeBeforeIsMarkedAndAProgressedOneIsNot(): void
    {
        $this->distanceOf($this->week3, 600);
        $this->distanceOf($this->week3Thursday, 600);

        $this->client->loginUser($this->coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$this->plan->getId().'/edit');
        self::assertResponseIsSuccessful();

        self::assertSame([
            self::id($this->week2) => '= S1',          // identique à S1 : pas encore ajustée
            self::id($this->week3Thursday) => '= mar.', // même semaine : le jour suffit
        ], $this->marks($crawler));
        // S1 n'a pas de fois d'avant, S3 mardi est passée à 600 m : pas de repère.
    }

    public function testAdjustingTheCellClearsItsMark(): void
    {
        $this->client->loginUser($this->coach);
        $crawler = $this->client->request('GET', '/plan-template/'.$this->plan->getId().'/edit');
        self::assertArrayHasKey(self::id($this->week2), $this->marks($crawler));

        $this->roundsOf($this->week2, 7);
        $marks = $this->marks($this->client->request('GET', '/plan-template/'.$this->plan->getId().'/edit'));
        self::assertArrayNotHasKey(self::id($this->week2), $marks, 'Passée à 7 tours : plus de repère.');
        self::assertArrayNotHasKey(self::id($this->week3), $marks, 'S3 se compare à S2, qui a changé : elle n\'est plus « la même que la fois d\'avant ».');
    }

    public function testTheQuickPanelOffersToRenameAndSaysWhoReadsTheTitle(): void
    {
        $this->client->loginUser($this->coach);

        // Contenu inchangé : le titre est éditable, sans rappel.
        $crawler = $this->client->request('GET', '/workout/'.$this->week2->getId().'/quick-panel');
        self::assertResponseIsSuccessful();
        self::assertSame('Fractionné 6 x 400 m', trim($crawler->filter('.kd-quicktitle__value')->text()));
        self::assertSame('title', $crawler->filter('.kd-quicktitle__value')->attr('data-inline-edit-field-value'));
        self::assertCount(0, $crawler->filter('.kd-quicktitle__hint'));

        // La case passe à 8 tours : le rappel paraît, et nomme l'athlète, qui
        // lira ce titre.
        $this->roundsOf($this->week2, 8);
        $crawler = $this->client->request('GET', '/workout/'.$this->week2->getId().'/quick-panel');
        self::assertSelectorTextContains('.kd-quicktitle__hint--todo', 'Trailer38 lira dans son agenda');
    }

    public function testSavingAValueFromTheQuickPanelRefreshesTheTitleHint(): void
    {
        $this->distanceOf($this->week2, 600);
        $prescribedId = $this->prescribedOf($this->week2)->getId();

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->week2->getId().'/quick-panel');
        $form = $crawler->filter('form[name="prescribed_'.$prescribedId.'"]');

        // L'athlète remet 400 m (le champ se saisit en km) : la case redevient
        // fidèle, le rappel s'efface.
        $values = $form->form()->getPhpValues();
        $values['prescribed_'.$prescribedId]['distanceMeters'] = '0.4';
        $this->client->request('POST', (string) $form->attr('action'), $values, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="quick-title-hint">', $content);
        self::assertStringNotContainsString('kd-quicktitle__hint', $content);

        // Et à 800 m il reparaît, au tutoiement : c'est son propre agenda.
        $values['prescribed_'.$prescribedId]['distanceMeters'] = '0.8';
        $this->client->request('POST', (string) $form->attr('action'), $values, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertStringContainsString('tu liras dans ton agenda', (string) $this->client->getResponse()->getContent());
    }

    public function testRenamingTheCellTouchesNeitherTheLibraryNorTheOtherCell(): void
    {
        $this->roundsOf($this->week2, 8);

        $this->client->loginUser($this->coach);
        $crawler = $this->client->request('GET', '/workout/'.$this->week2->getId().'/quick-panel');
        $title = $crawler->filter('.kd-quicktitle__value');
        $this->client->request('POST', (string) $title->attr('data-inline-edit-url-value'), [
            '_token' => $title->attr('data-inline-edit-token-value'),
            'field' => 'title',
            'value' => 'Fractionné 8 x 400 m',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame('Fractionné 8 x 400 m', $this->mustFind(Workout::class, $this->week2->getId())->getTitle());
        self::assertSame('Fractionné 6 x 400 m', $this->mustFind(Workout::class, $this->week1->getId())->getTitle());
        self::assertSame('Fractionné 6 x 400 m', $this->mustFind(Workout::class, $this->intervals->getId())->getTitle());

        // Renommée, la case rappelle d'où elle vient au lieu de le demander.
        $this->client->request('GET', '/workout/'.$this->week2->getId().'/quick-panel');
        self::assertSelectorTextContains('.kd-quicktitle__hint', 'Séance d\'origine : « Fractionné 6 x 400 m »');
        self::assertSelectorNotExists('.kd-quicktitle__hint--todo');

        // La grille re-rendue montre le nouveau titre.
        $this->client->request('GET', '/plan-template/'.$this->plan->getId().'/grid', [], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertStringContainsString('Fractionné 8 x 400 m', (string) $this->client->getResponse()->getContent());
    }

    private function cell(int $week, int $day): Workout
    {
        $cell = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($this->intervals, $this->athlete, null, true);
        $item = (new PlanItem())->setWorkout($cell)->setWeekNumber($week)->setDayOfWeek($day);
        $this->plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $cell;
    }

    private function prescribedOf(Workout $workout): PrescribedExercise
    {
        $block = $workout->getBlocks()->first();
        self::assertInstanceOf(Block::class, $block);
        $prescribed = $block->getPrescribedExercises()->first();
        self::assertInstanceOf(PrescribedExercise::class, $prescribed);

        return $prescribed;
    }

    private function distanceOf(Workout $workout, int $meters): void
    {
        $this->prescribedOf($workout)->setDistanceMeters($meters);
        $this->em->flush();
    }

    private function roundsOf(Workout $workout, int $rounds): void
    {
        $block = $workout->getBlocks()->first();
        self::assertInstanceOf(Block::class, $block);
        $block->setRounds($rounds);
        $this->em->flush();
    }

    /**
     * Les repères de la grille, par id de copie locale.
     *
     * @return array<int, string>
     */
    private function marks(Crawler $crawler): array
    {
        $marks = [];
        $crawler->filter('.kd-planentry')->each(static function (Crawler $entry) use (&$marks): void {
            $mark = $entry->filter('.kd-planitem__same');
            if (0 === $mark->count()) {
                return;
            }
            self::assertSame(1, preg_match('#/workout/(\d+)/quick-panel#', (string) $entry->filter('.kd-planitem__link')->attr('data-panel-url'), $m));
            $marks[(int) $m[1]] = trim($mark->text());
        });

        return $marks;
    }

    /** L'id de la copie locale, posé par la persistance. */
    private static function id(Workout $workout): int
    {
        $id = $workout->getId();
        self::assertNotNull($id);

        return $id;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($roles);
        $this->em->persist($user);

        return $user;
    }
}
