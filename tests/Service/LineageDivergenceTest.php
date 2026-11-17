<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Service\LineageDivergence;
use PHPUnit\Framework\TestCase;

/**
 * L'empreinte du contenu prescrit, et le rappel de renommer une case dont le
 * titre chiffré ne suit plus le contenu (#93). Le repère « = S11 » de la grille,
 * qui a besoin d'identifiants, est couvert par PlanCellDivergenceTest.
 */
final class LineageDivergenceTest extends TestCase
{
    private Exercise $run;

    protected function setUp(): void
    {
        $this->run = (new Exercise())->setName('Course')->setActivity(ActivityType::RUNNING);
    }

    public function testTheSameContentHasTheSameFingerprint(): void
    {
        self::assertSame(
            LineageDivergence::fingerprint($this->intervals(6, 400)),
            LineageDivergence::fingerprint($this->intervals(6, 400)->setTitle('Autre titre')->setDescription('Récup trot')),
            'Titre et description ne comptent pas.'
        );
    }

    public function testAnyPrescribedValueChangesTheFingerprint(): void
    {
        $base = LineageDivergence::fingerprint($this->intervals(6, 400));

        self::assertNotSame($base, LineageDivergence::fingerprint($this->intervals(8, 400)), '8 × 400 au lieu de 6 × 400.');
        self::assertNotSame($base, LineageDivergence::fingerprint($this->intervals(6, 600)), '6 × 600 au lieu de 6 × 400.');
    }

    /** Les positions se comparent par l'ordre qu'elles donnent, pas par leur valeur. */
    public function testRenumberedPositionsInTheSameOrderDoNotCount(): void
    {
        $renumbered = $this->intervals(6, 400);
        foreach ($renumbered->getBlocks() as $block) {
            $block->setPosition((int) $block->getPosition() + 10);
        }

        self::assertSame(LineageDivergence::fingerprint($this->intervals(6, 400)), LineageDivergence::fingerprint($renumbered));
    }

    public function testADetailedSetChangesTheFingerprint(): void
    {
        $detailed = $this->intervals(6, 400);
        $block = $detailed->getBlocks()->last();
        self::assertInstanceOf(Block::class, $block);
        $prescribed = $block->getPrescribedExercises()->first();
        self::assertInstanceOf(PrescribedExercise::class, $prescribed);
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::NORMAL)->setReps(5)->setWeightKg(82.5));

        self::assertNotSame(LineageDivergence::fingerprint($this->intervals(6, 400)), LineageDivergence::fingerprint($detailed));
    }

    public function testAChangedCellWithTheOriginsNumberedTitleMayBeStale(): void
    {
        $root = $this->intervals(6, 400);
        $divergence = new LineageDivergence();

        self::assertTrue($divergence->titleMayBeStale($this->copyOf($root, 8, 400)));
        self::assertFalse($divergence->titleMayBeStale($this->copyOf($root, 6, 400)), 'Contenu identique : le titre est juste.');
        self::assertFalse($divergence->titleMayBeStale($this->copyOf($root, 8, 400)->setTitle('Fractionné 8 x 400 m')), 'Déjà renommée.');
    }

    /** « Upper A » reste vrai quand la charge progresse : pas de rappel. */
    public function testATitleWithoutANumberNeverAsksForARename(): void
    {
        $root = $this->intervals(6, 400)->setTitle('Séance qualité');

        self::assertFalse((new LineageDivergence())->titleMayBeStale($this->copyOf($root, 8, 400)->setTitle('Séance qualité')));
    }

    /** Une séance de bibliothèque, ou une case dont la racine a disparu, n'a rien à quoi se comparer. */
    public function testWithoutARootThereIsNothingToCompare(): void
    {
        $divergence = new LineageDivergence();

        self::assertFalse($divergence->titleMayBeStale($this->intervals(6, 400)));
        self::assertFalse($divergence->titleMayBeStale($this->intervals(8, 400)->setPlanLocal(true)));
    }

    private function intervals(int $rounds, int $meters): Workout
    {
        return (new Workout())
            ->setTitle('Fractionné 6 x 400 m')
            ->addBlock((new Block())->setRole(BlockRole::WARMUP)->setPosition(0)->addPrescribedExercise(
                (new PrescribedExercise())->setExercise($this->run)->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::DURATION)->setDurationSeconds(900)
            ))
            ->addBlock((new Block())->setRole(BlockRole::MAIN)->setRounds($rounds)->setPosition(1)->addPrescribedExercise(
                (new PrescribedExercise())->setExercise($this->run)->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)->setDistanceMeters($meters)->setRestSeconds(90)
            ));
    }

    private function copyOf(Workout $root, int $rounds, int $meters): Workout
    {
        return $this->intervals($rounds, $meters)->setTitle((string) $root->getTitle())->setPlanLocal(true)->setOrigin($root);
    }
}
