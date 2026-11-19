<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\TargetArea;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un compteur s'accorde au nombre (#123) : « 1 exo », pas « 1 exos » ;
 * « 2 séances supprimées », pas « séance(s) supprimée(s) ». Le singulier vaut
 * jusqu'à 1, comme partout ailleurs dans l'app (`n > 1 ? 's'`).
 */
final class CountAgreementTest extends WebTestCase
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

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    public function testWorkoutCardOfTheAddModalSaysOneExo(): void
    {
        $owner = $this->createUser();
        $this->createWorkout($owner);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/calendar/week/2026-03-11');

        self::assertResponseIsSuccessful();
        $meta = $crawler->filter('[data-caladd-target="workoutCard"] .kd-palettecard__meta')->text();
        self::assertStringContainsString('1 exo', $meta);
        self::assertStringNotContainsString('1 exos', $meta);
    }

    public function testClearingAPlanAgreesWithTheCount(): void
    {
        $owner = $this->createUser();
        $template = $this->createPlan($owner, 'Plan solo', 1);
        $pair = $this->createPlan($owner, 'Plan duo', 2);
        $this->client->loginUser($owner);

        $this->clearPlan($template);
        self::assertSelectorTextContains('body', 'Plan « Plan solo » retiré du planning : 1 séance supprimée.');

        $this->clearPlan($pair);
        self::assertSelectorTextContains('body', 'Plan « Plan duo » retiré du planning : 2 séances supprimées.');
    }

    private function clearPlan(PlanTemplate $template): void
    {
        $crawler = $this->client->request('GET', '/calendar/2026/3');
        $form = $crawler->filter('form[action$="/schedule/plan/clear"]')->form(['planId' => (string) $template->getId()]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
    }

    /** Un plan déjà au calendrier : `$count` séances datées en mars 2026. */
    private function createPlan(User $owner, string $title, int $count): PlanTemplate
    {
        $template = (new PlanTemplate())->setOwner($owner)->setTitle($title)->setDurationWeeks(1)
            ->setSlug('plan-'.bin2hex(random_bytes(4)));
        $this->em->persist($template);
        for ($i = 0; $i < $count; ++$i) {
            $this->em->persist((new ScheduledWorkout())
                ->setOwner($owner)
                ->setTitle('Séance '.$i)
                ->setScheduledDate(new \DateTimeImmutable('2026-03-1'.$i))
                ->setStatus(ScheduledStatus::PLANNED)
                ->setSourcePlanTemplate($template));
        }
        $this->em->flush();

        return $template;
    }

    private function createUser(): User
    {
        $user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Une séance d'un seul exercice. */
    private function createWorkout(User $owner): Workout
    {
        $exercise = (new Exercise())->setOwner($owner)->setName('Développé couché')
            ->setActivity(ActivityType::GYM)->setTargetAreas([TargetArea::CHEST]);
        $this->em->persist($exercise);

        $workout = (new Workout())->setOwner($owner)->setTitle('Haut du corps')->setSlug('haut-du-corps');
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise((new PrescribedExercise())->setExercise($exercise)->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)->setSets(3)->setReps(8));
        $workout->addBlock($block);
        $this->em->persist($workout);
        $this->em->persist($block);
        $this->em->flush();

        return $workout;
    }
}
