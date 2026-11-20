<?php

namespace App\Tests\Controller;

use App\Entity\LoggedExercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Service\WorkoutCloner;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * #116 : la suppression d'une séance s'atteint depuis le menu « … » de la vue,
 * et sa confirmation dit ce qui reste. Les annonces sont vérifiées contre ce
 * que la base fait vraiment (SET NULL des séances datées et de la lignée).
 */
final class WorkoutDeleteTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $athlete;
    private Workout $upperA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->athlete = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->athlete);

        $this->upperA = $this->workout('Upper A');
        $this->em->flush();
    }

    public function testTheMenuOffersTheDeletionAndSaysWhatStays(): void
    {
        $this->cell($this->plan('Force'), $this->upperA, 1);
        $this->scheduled($this->upperA, ScheduledStatus::PLANNED);
        $this->scheduled($this->upperA, ScheduledStatus::DONE);
        $this->scheduled($this->upperA, ScheduledStatus::DONE);
        $this->upperA->setShareToken(bin2hex(random_bytes(16)));
        $this->em->flush();

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upperA->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('.kd-kebab__panel form[action="/workout/'.$this->upperA->getId().'/delete"]');
        self::assertCount(1, $form);
        self::assertStringContainsString('Supprimer la séance', $form->filter('button.kd-kebab__item--danger')->text());
        self::assertSame(
            "Supprimer « Upper A » ? C'est définitif.\n"
            ."Les 3 séances déjà à l'agenda restent, sans le détail prévu. Le réalisé des 2 déjà faites est gardé.\n"
            ."Le plan « Force » garde sa copie.\n"
            .'Son lien de partage ne marchera plus.',
            $this->confirmMessage($form),
        );
    }

    public function testTheComposerWritesTheConsequencesUnderTheButton(): void
    {
        $this->cell($this->plan('Force'), $this->upperA, 1);
        $this->cell($this->plan('Prépa trail'), $this->upperA, 1);
        $this->scheduled($this->upperA, ScheduledStatus::DONE);

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upperA->getId().'/edit');

        $zone = $crawler->filter('.kd-editsection')->last();
        self::assertSame(
            "La séance déjà à l'agenda reste, sans le détail prévu. Son réalisé est gardé. Les 2 plans qui l'utilisent gardent leur copie.",
            $this->squash($zone->filter('.kd-editsection__hint')->text()),
        );
    }

    public function testAnUnusedWorkoutOnlySaysItIsFinal(): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upperA->getId().'/edit');

        $zone = $crawler->filter('.kd-editsection')->last();
        self::assertCount(0, $zone->filter('.kd-editsection__hint'));
        self::assertSame("Supprimer « Upper A » ? C'est définitif.", $this->confirmMessage($zone->filter('form')));
    }

    /** Ce que la confirmation promet, la base le tient. */
    public function testDeletingKeepsTheCalendarAndThePlanCopies(): void
    {
        $copy = $this->cell($this->plan('Force'), $this->upperA, 1);
        $planned = $this->scheduled($this->upperA, ScheduledStatus::PLANNED);
        $done = $this->scheduled($this->upperA, ScheduledStatus::DONE);
        $done->addLoggedExercise((new LoggedExercise())->setExerciseName('Développé couché')->setPosition(0));
        $this->em->flush();
        $ids = [$planned->getId(), $done->getId(), $copy->getId()];

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->upperA->getId());
        $this->client->submit($crawler->filter('.kd-kebab__panel form[action$="/delete"]')->form());
        self::assertResponseRedirects('/workout');

        $this->em->clear();
        self::assertNull($this->em->find(Workout::class, $this->upperA->getId()));
        foreach ([$ids[0], $ids[1]] as $id) {
            $scheduled = $this->mustFind(ScheduledWorkout::class, $id);
            self::assertNull($scheduled->getWorkout());
            self::assertSame('Upper A', $scheduled->getDisplayTitle());
        }
        self::assertCount(1, $this->mustFind(ScheduledWorkout::class, $ids[1])->getLoggedExercises());
        $survivor = $this->mustFind(Workout::class, $ids[2]);
        self::assertNull($survivor->getOrigin());
        self::assertCount(1, $survivor->getPlanItems());
    }

    /**
     * Une copie de case se retire depuis la grille : la supprimer d'ici
     * laisserait ses séances prévues vides au calendrier.
     */
    public function testAPlanCellIsRemovedFromItsPlanNotFromHere(): void
    {
        $plan = $this->plan('Force');
        $copy = $this->cell($plan, $this->upperA, 1);

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$copy->getId());
        self::assertCount(0, $crawler->filter('.kd-kebab__panel form[action$="/delete"]'));

        $crawler = $this->client->request('GET', '/workout/'.$copy->getId().'/edit');
        $zone = $crawler->filter('.kd-editsection')->last();
        self::assertCount(0, $zone->filter('form'));
        self::assertStringContainsString('case du plan « Force »', $zone->text());
        self::assertSame('/plan-template/'.$plan->getId().'/edit', $zone->filter('a')->attr('href'));

        // La garde passe avant le jeton : elle ne touche à rien.
        $this->client->request('POST', '/workout/'.$copy->getId().'/delete', ['_token' => 'peu-importe']);
        self::assertResponseRedirects('/plan-template/'.$plan->getId().'/edit');
        $this->em->clear();
        self::assertNotNull($this->em->find(Workout::class, $copy->getId()));
    }

    public function testAGlobalWorkoutOffersNoDeletionToAReader(): void
    {
        $global = (new Workout())->setTitle('Gainage')->setSlug('w-global');
        $this->em->persist($global);
        $this->em->flush();

        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$global->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.kd-kebab__panel form[action$="/delete"]'));
    }

    /**
     * Le message de `confirm()` tel que le navigateur le lira : la chaîne JS
     * échappée par `e('js')`, décodée.
     */
    private function confirmMessage(Crawler $form): string
    {
        self::assertSame(1, preg_match("/^return confirm\\('(.*)'\\);$/s", (string) $form->attr('onsubmit'), $m));
        $json = (string) preg_replace('/\\\\x([0-9A-Fa-f]{2})/', '\\u00$1', $m[1]);

        return (string) json_decode('"'.$json.'"');
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function workout(string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($this->athlete)
            ->setTitle($title)
            ->setSlug('w-'.bin2hex(random_bytes(3)));
        $this->em->persist($workout);

        return $workout;
    }

    private function plan(string $title): PlanTemplate
    {
        $plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle($title)
            ->setDurationWeeks(4)
            ->setSlug('p-'.bin2hex(random_bytes(3)));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /** Pose comme le fait l'éditeur de plan : une copie locale par case. */
    private function cell(PlanTemplate $plan, Workout $source, int $week): Workout
    {
        $copy = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($source, $this->athlete, null, true);
        $item = (new PlanItem())->setWorkout($copy)->setWeekNumber($week)->setDayOfWeek(1);
        $plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $copy;
    }

    private function scheduled(Workout $workout, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->athlete)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-12-04'))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
