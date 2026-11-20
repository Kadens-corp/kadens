<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\WorkoutCloner;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #110 : faire progresser un exercice sur tout un plan en un geste. « +2,5 kg par
 * semaine au développé militaire d'Upper A, de S1 à S4 » au lieu de quatre
 * éditions de case, avec un aperçu avant d'appliquer.
 */
final class PlanProgressionTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $athlete;
    private Exercise $press;
    private Exercise $pullUp;
    private Workout $upperA;
    private PlanTemplate $plan;

    /** @var array<int, Workout> copie locale d'Upper A par semaine */
    private array $mondays = [];

    /** @var array<int, Workout> copie locale d'Upper B par semaine */
    private array $thursdays = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->athlete = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->athlete);

        $this->press = (new Exercise())->setName('Développé militaire')->setActivity(ActivityType::GYM);
        $this->pullUp = (new Exercise())->setName('Traction')->setActivity(ActivityType::GYM);
        $this->em->persist($this->press);
        $this->em->persist($this->pullUp);

        $this->upperA = $this->workout('Upper A', [[$this->press, 60.0], [$this->pullUp, null]]);
        $upperB = $this->workout('Upper B', [[$this->press, 40.0]]);

        $this->plan = (new PlanTemplate())
            ->setOwner($this->athlete)
            ->setTitle('Force 4 semaines')
            ->setDurationWeeks(4)
            ->setSlug('force-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->plan);
        $this->em->flush();

        for ($week = 1; $week <= 4; ++$week) {
            $this->mondays[$week] = $this->cell($this->upperA, $week, 1);
            $this->thursdays[$week] = $this->cell($upperB, $week, 4);
        }
    }

    public function testThePreviewShowsTheRampAndWritesNothing(): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', $this->url(), $this->query());
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('.kd-progtable tbody tr')->each(static fn ($tr): string => trim(preg_replace('/\s+/', ' ', $tr->text()) ?? ''));
        self::assertSame([
            'S1 · lundi 60 kg Inchangée',
            'S2 · lundi 60 kg 62,5 kg',
            'S3 · lundi 60 kg 65 kg',
            'S4 · lundi 60 kg 67,5 kg',
        ], $rows);
        self::assertSelectorTextContains('.kd-progtool__apply button', 'Appliquer à 3 cases');

        $this->em->clear();
        self::assertSame(60.0, $this->pressOf($this->mondays[4])->getWeightKg(), 'L\'aperçu n\'écrit rien.');
    }

    public function testApplyingRaisesEveryWeekOfThatSessionOnly(): void
    {
        $this->applyFromPreview($this->query());
        self::assertResponseRedirects('/plan-template/'.$this->plan->getId().'/edit');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-flash', 'Développé militaire (Upper A) : 3 cases mises à jour, de 60 kg en S1 à 67,5 kg en S4.');

        $this->em->clear();
        self::assertSame([60.0, 62.5, 65.0, 67.5], array_map(fn (Workout $w): ?float => $this->pressOf($w)->getWeightKg(), array_values($this->mondays)));
        // Le même exercice dans Upper B a sa propre progression.
        self::assertSame([40.0, 40.0, 40.0, 40.0], array_map(fn (Workout $w): ?float => $this->pressOf($w)->getWeightKg(), array_values($this->thursdays)));
        // Ni la séance de bibliothèque, ni les autres valeurs de la case.
        self::assertSame(60.0, $this->pressOf($this->upperA)->getWeightKg());
        self::assertSame(3, $this->pressOf($this->mondays[4])->getSets());
        self::assertSame(8, $this->pressOf($this->mondays[4])->getReps());
    }

    public function testTheToolIsARampSoApplyingItTwiceChangesNothing(): void
    {
        $this->applyFromPreview($this->query());

        $crawler = $this->client->request('GET', $this->url(), $this->query());
        self::assertSelectorTextContains('.kd-editsection__hint', 'Rien à changer');
        self::assertCount(0, $crawler->filter('.kd-progtool__apply'));

        // Et un autre pas remplace la rampe au lieu de s'y ajouter.
        $this->applyFromPreview($this->query(['step' => '5']));
        $this->em->clear();
        self::assertSame([60.0, 65.0, 70.0, 75.0], array_map(fn (Workout $w): ?float => $this->pressOf($w)->getWeightKg(), array_values($this->mondays)));
    }

    public function testDetailedSetsShiftTogetherAndTheWarmupStays(): void
    {
        // S2 en séries détaillées : échauffement, série lourde, série allégée.
        $prescribed = $this->pressOf($this->mondays[2]);
        foreach ([[SetType::WARMUP, 40.0], [SetType::NORMAL, 60.0], [SetType::DEGRESSIVE, 55.0]] as $position => [$type, $kg]) {
            $set = (new PrescribedSet())->setPosition($position)->setSetType($type)->setReps(8)->setWeightKg($kg);
            $prescribed->addDetailedSet($set);
            $this->em->persist($set);
        }
        $prescribed->setSets(2);
        $this->em->flush();

        $this->applyFromPreview($this->query(['from' => '1', 'to' => '2']));

        $this->em->clear();
        $prescribed = $this->pressOf($this->mondays[2]);
        $sets = $prescribed->getDetailedSets()->toArray();
        usort($sets, static fn (PrescribedSet $a, PrescribedSet $b): int => $a->getPosition() <=> $b->getPosition());
        self::assertSame([40.0, 62.5, 57.5], array_map(static fn (PrescribedSet $s): ?float => $s->getWeightKg(), $sets));
        self::assertSame(2, $prescribed->getSets(), 'Le nombre de séries de travail ne bouge pas.');
    }

    public function testACellAlreadyDoneKeepsWhatWasPrescribed(): void
    {
        $this->em->persist(
            (new ScheduledWorkout())
                ->setOwner($this->athlete)
                ->setWorkout($this->mondays[2])
                ->setScheduledDate(new \DateTimeImmutable('2026-11-09'))
                ->setStatus(ScheduledStatus::DONE)
        );
        $this->em->flush();

        $this->client->loginUser($this->athlete);
        $this->client->request('GET', $this->url(), $this->query());
        self::assertSelectorTextContains('.kd-progtable__row--done', 'Déjà faite le 09/11');
        self::assertSelectorTextContains('.kd-progtool__apply button', 'Appliquer à 2 cases');

        $this->applyFromPreview($this->query());
        $this->em->clear();
        self::assertSame([60.0, 60.0, 65.0, 67.5], array_map(fn (Workout $w): ?float => $this->pressOf($w)->getWeightKg(), array_values($this->mondays)));
    }

    public function testRepsProgressToo(): void
    {
        $this->applyFromPreview($this->query(['target' => $this->target($this->pullUp), 'field' => 'reps', 'step' => '1']));

        $this->em->clear();
        self::assertSame([8, 9, 10, 11], array_map(fn (Workout $w): ?int => $this->prescribedOf($w, $this->pullUp)->getReps(), array_values($this->mondays)));
    }

    public function testTheFirstVisitPreviewsTheFirstExercise(): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', '/plan-template/'.$this->plan->getId().'/edit');
        $link = $crawler->selectLink('Faire progresser un exercice');
        self::assertCount(1, $link);

        $crawler = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSame($this->target($this->press), $crawler->filter('#prog-target option[selected]')->attr('value'));
        // Upper A puis Upper B, chacune avec ses exercices.
        self::assertSame(['Upper A', 'Upper B'], $crawler->filter('#prog-target optgroup')->each(static fn ($g): string => (string) $g->attr('label')));
        self::assertSelectorTextContains('.kd-progtool__apply button', 'Appliquer à 3 cases');
        // Le retour ramène à l'éditeur.
        self::assertStringContainsString('/plan-template/'.$this->plan->getId().'/edit', (string) $crawler->filter('.kd-backlink')->attr('href'));
    }

    public function testABadValueIsExplainedAndNothingIsApplied(): void
    {
        $this->client->loginUser($this->athlete);
        $this->client->request('GET', $this->url(), $this->query(['field' => 'reps', 'step' => '1,5']));
        self::assertSelectorTextContains('.kd-flash--error', 'Les répétitions se comptent en nombres entiers.');
        self::assertSelectorNotExists('.kd-progtool__apply');
    }

    public function testAnExpiredTokenAppliesNothing(): void
    {
        $this->client->loginUser($this->athlete);
        $this->client->request('POST', $this->url(), $this->query() + ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        self::assertSame(60.0, $this->pressOf($this->mondays[4])->getWeightKg());
    }

    public function testSomeoneElseCannotOpenTheTool(): void
    {
        $stranger = (new User())->setEmail('stranger@example.com')->setPassword('x');
        $this->em->persist($stranger);
        $this->em->flush();

        $this->client->loginUser($stranger);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string> $query */
    private function applyFromPreview(array $query): void
    {
        $this->client->loginUser($this->athlete);
        $crawler = $this->client->request('GET', $this->url(), $query);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.kd-progtool__apply')->form());
    }

    private function url(): string
    {
        return '/plan-template/'.$this->plan->getId().'/progression';
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function query(array $overrides = []): array
    {
        return $overrides + [
            'target' => $this->target($this->press),
            'field' => 'weight',
            'from' => '1',
            'to' => '4',
            'step' => '2,5',
            'start' => '',
        ];
    }

    private function target(Exercise $exercise): string
    {
        return $this->upperA->getId().'-'.$exercise->getId();
    }

    /**
     * @param list<array{Exercise, float|null}> $exercises
     */
    private function workout(string $title, array $exercises): Workout
    {
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        foreach ($exercises as $position => [$exercise, $kg]) {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition($position)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)
                    ->setReps(8)
                    ->setWeightKg($kg)
            );
        }
        $workout = (new Workout())
            ->setOwner($this->athlete)
            ->setTitle($title)
            ->setSlug('w-'.bin2hex(random_bytes(3)))
            ->addBlock($block);
        $this->em->persist($workout);

        return $workout;
    }

    private function cell(Workout $source, int $week, int $day): Workout
    {
        $copy = static::getContainer()->get(WorkoutCloner::class)->cloneWorkout($source, $this->athlete, null, true);
        $item = (new PlanItem())->setWorkout($copy)->setWeekNumber($week)->setDayOfWeek($day);
        $this->plan->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $copy;
    }

    private function pressOf(Workout $workout): PrescribedExercise
    {
        return $this->prescribedOf($workout, $this->press);
    }

    private function prescribedOf(Workout $workout, Exercise $exercise): PrescribedExercise
    {
        $fresh = $this->mustFind(Workout::class, $workout->getId());
        foreach ($fresh->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                if ($prescribed->getExercise()?->getId() === $exercise->getId()) {
                    return $prescribed;
                }
            }
        }

        self::fail('Exercice absent de la séance.');
    }
}
