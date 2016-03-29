<?php

namespace Phpab\Phpab;

use Phpab\Phpab\ParticipationStrategy\ParticipationStrategyInterface;
use Phpab\Phpab\VariantChooser\VariantChooserInterface;

interface TestInterface
{
    
    /**
     * Get all variants for this test
     *
     * @return VariantInterface[]
     */
    public function getVariants();

    /**
     * Get a single variant for this test
     *
     * @param string $variant The variants Identifier
     *
     * @return mixed
     */
    public function getVariant($variant);

    /**
     * Get all Options for the Test.
     *
     * Options can be used for data which is not mandatory and
     * can be Implementation specific. Like Google-Experiments ID
     *
     * @return array
     */
    public function getOptions();

    /**
     * Get the Participation Strategy
     *
     * @return ParticipationStrategyInterface
     */
    public function getParticipationStrategy();

    /**
     * Get the Variant Chooser.
     *
     * The Variant Chooser chooses the variant after the
     * Strategy allowed the user to participate in the test.
     *
     * @return VariantChooserInterface
     */
    public function getVariantChooser();

}
