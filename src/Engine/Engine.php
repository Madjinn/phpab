<?php
/**
 * This file is part of phpab/phpab. (https://github.com/phpab/phpab)
 *
 * @link https://github.com/phpab/phpab for the canonical source repository
 * @copyright Copyright (c) 2015-2016 phpab. (https://github.com/phpab/)
 * @license https://raw.githubusercontent.com/phpab/phpab/master/LICENSE.md MIT
 */

namespace PhpAb\Engine;

use PhpAb\Event\Dispatcher;
use PhpAb\Event\DispatcherInterface;
use PhpAb\Exception\EngineLockedException;
use PhpAb\Exception\TestCollisionException;
use PhpAb\Exception\TestNotFoundException;
use PhpAb\Participation\Filter\FilterInterface;
use PhpAb\Participation\ManagerInterface;
use PhpAb\Test\Bag;
use PhpAb\Test\TestInterface;
use PhpAb\Variant\Chooser\ChooserInterface;
use PhpAb\Variant\VariantInterface;

/**
 * The engine used to start tests.
 *
 * @package PhpAb
 */
class Engine extends Dispatcher implements EngineInterface, DispatcherInterface
{
    /**
     * A list with test bags.
     *
     * @var Bag[]
     */
    public $tests = [];

    /**
     * The participation manager used to check if a user participates.
     *
     * @var ManagerInterface
     */
    private $participationManager;

    /**
     * Locks the engine for further manipulaton
     *
     * @var boolean
     */
    private $locked = false;

    /**
     * Initializes a new instance of this class.
     *
     * @param ManagerInterface $participationManager Handles the Participation state
     */
    public function __construct(
        ManagerInterface $participationManager
    ) {
        $this->participationManager = $participationManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getTests()
    {
        $tests = [];
        foreach ($this->tests as $bag) {
            $tests[] = $bag->getTest();
        }

        return $tests;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $test The identifier of the test
     */
    public function getTest($test)
    {
        if (! isset($this->tests[$test])) {
            throw new TestNotFoundException('No test with identifier '.$test.' found');
        }

        return $this->tests[$test]->getTest();
    }

    /**
     * {@inheritDoc}
     *
     * @param TestInterface $test
     * @param FilterInterface $filter
     * @param ChooserInterface $chooser
     * @param array $options
     */
    public function addTest(
        TestInterface $test,
        FilterInterface $filter,
        ChooserInterface $chooser,
        $options = []
    ) {

        if ($this->locked) {
            throw new EngineLockedException('The engine has been processed already. You cannot add other tests.');
        }

        if (isset($this->tests[$test->getIdentifier()])) {
            throw new TestCollisionException('Duplicate test for identifier '.$test->getIdentifier());
        }

        $this->tests[$test->getIdentifier()] = new Bag($test, $filter, $chooser, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function start()
    {
        // Check if already locked
        if ($this->locked) {
            throw new EngineLockedException('The engine is already locked and could not be started once again.');
        }

        // Lock the engine for further manipulation
        $this->locked = true;

        foreach ($this->tests as $testBag) {
            $this->handleTestBag($testBag);
        }
    }

    /**
     * Process the test bag
     *
     * @param Bag $bag
     *
     * @return bool true if the variant got executed, false otherwise
     */
    private function handleTestBag(Bag $bag)
    {
        $test = $bag->getTest();

        $isParticipating = $this->participationManager->participates($test->getIdentifier());
        $testParticipation = $this->participationManager->getParticipatingVariant($test->getIdentifier());

        // Check if the user is marked as "do not participate".
        if ($isParticipating && null === $testParticipation) {
            $this->dispatch('phpab.participation.blocked', [$this, $bag]);
            return;
        }

        // When the user does not participate at the test, let him participate.
        if (!$isParticipating && !$bag->getParticipationFilter()->shouldParticipate()) {
            // The user should not participate so let's set participation
            // to null so he will not participate in the future, too.
            $this->dispatch('phpab.participation.block', [$this, $bag]);

            $this->participationManager->participate($test->getIdentifier(), null);
            return;
        }

        // Let's try to recover a previously stored Variant
        if ($isParticipating && $testParticipation !== null) {
            $variant = $bag->getTest()->getVariant($testParticipation);

            // If we managed to identify a Variant by a previously stored participation, do its magic again.
            if ($variant instanceof VariantInterface) {
                $this->activateVariant($bag, $variant);
                return;
            }
        }

        // Choose a variant for later usage. If the user should participate this one will be used
        $chosen = $bag->getVariantChooser()->chooseVariant($test->getVariants());

        // Check if user participation should be blocked. Or maybe the variant does not exists anymore?
        if (null === $chosen || !$test->getVariant($chosen->getIdentifier())) {
            $this->dispatch('phpab.participation.variant_missing', [$this, $bag]);

            $this->participationManager->participate($test->getIdentifier(), null);
            return;
        }

        // Store the chosen variant so he will not switch between different states
        $this->participationManager->participate($test->getIdentifier(), $chosen->getIdentifier());

        $this->activateVariant($bag, $chosen);
    }

    /**
     * Runs the Variant and dispatches subscriptions
     *
     * @param Bag $bag
     * @param VariantInterface $variant
     */
    private function activateVariant(Bag $bag, VariantInterface $variant)
    {
        $this->dispatch('phpab.participation.variant_run', [$this, $bag, $variant]);

        $variant->run();
    }
}
