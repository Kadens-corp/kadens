<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Service\WorkoutCloner;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #94 : l'édition rapide d'une case dit quelle case on édite (« S2 · mardi · … »)
 * et affiche toujours le nom de chaque exercice, séries détaillées comprises.
 */
final class PlanQuickEditHeadingTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $athlete;
    private PlanTemplate $plan;
    private Workout $cell;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->athlete = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->athlete);

        $bench = (new Exercise())->setName('Développé couché')->setActivity(ActivityType::GYM);
        $this->em->persist($bench);

        $prescribed = (new PrescribedExercise())
            ->setExercise($bench)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(3);
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(10)->setWeightKg(40.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(70.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(6)->setWeightKg(75.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(3)->setSetType(SetType::NORMAL)->setReps(4)->setWeightKg(80.0));

        $upper = (new Workout())
            ->setOwner($this->athlete)
            ->setTitle('Haut du corps')
            ->setSlug('haut-'.bin2hex(random_bytes(3)))
            ->addBlock((new Block())->setRole(BlockRole::MAIN)->setPosition(0)->addPrescribedExercise($prescribed));
        $this->em->persist($upper);

        $this->plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle('Force')
            ->setDurationWeeks(3)
            ->setSlug('force-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->plan);
        $this->em->flush();

        // Posée en semaine 2, le mardi, forkée comme le fait la pose.
        $this->cell = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($upper, $this->athlete, null, true);
        $item = (new PlanItem())->setWorkout($this->cell)->setWeekNumber(2)->setDayOfWeek(2);
        $this->plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();
    }

    public function testTheCellButtonCarriesItsWeekAndDayForTheModalTitle(): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/plan-template/'.$this->plan->getId().'/edit');
        self::assertResponseIsSuccessful();

        $button = $crawler->filter('.kd-planitem__link[data-panel-url$="/workout/'.$this->cell->getId().'/quick-panel"]');
        self::assertCount(1, $button);
        self::assertSame('S2 · mardi', $button->attr('data-cell-label'));
        self::assertSame('Haut du corps', trim($button->filter('.kd-planitem__title')->text()));

        // Le titre de la modale est un vrai titre, qui la nomme.
        self::assertSelectorExists('dialog.kd-modal--quick[aria-labelledby="quick-modal-title"] h2#quick-modal-title [data-plangrid-target="heading"]');
    }

    public function testADetailedSetsExerciseKeepsItsNameAboveItsSummary(): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/workout/'.$this->cell->getId().'/quick-panel');
        self::assertResponseIsSuccessful();

        $body = $crawler->filter('.kd-quickexo__sum .kd-quickexo__body');
        self::assertCount(1, $body);
        self::assertSame('Développé couché', trim($body->filter('.kd-quickexo__name')->text()));
        // Le résumé vit sous le nom, dans le même bloc, et se relit en entier au survol.
        $pill = $body->filter('.kd-cexo__pill');
        self::assertStringContainsString('10 reps @ 40 kg', $pill->text());
        self::assertSame(trim($pill->text()), $pill->attr('title'));
    }
}
