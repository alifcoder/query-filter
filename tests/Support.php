<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Use BaseEBFilter in this test fixture.
use Alif\QueryFilter\Abstracts\BaseQBFilter; // Use BaseQBFilter in this test fixture.
use Alif\QueryFilter\FilterLimits; // Use FilterLimits in this test fixture.

/** Test fixture that supplies explicit model fields for isolated compiler scenarios. */
class TestModelFilter extends BaseEBFilter // Provide the test model filter fixture.
{
    /** Keep fixture field maps outside the production constructor contract. */
    public function __construct( // Supply fixture dependencies while preserving the production filter contract.
        array $parameters = [], // Prepare parameters for this regression scenario.
        private array $allowedFields = [], // Retain allowed fields for this test fixture.
        ?FilterLimits $limits = null, // Prepare limits for this regression scenario.
    ) {
        parent::__construct($parameters, $limits); // Initialize request parameters and limits through the production base.
    }

    /** Expose only fields chosen by the current test case. */
    protected function fields(): array // Declare the public fields available to this fixture filter.
    {
        return $this->allowedFields; // Return the explicit field map owned by this test fixture.
    }
}

/** Query Builder counterpart for exercising the same compiler with fixture columns. */
class TestQueryFilter extends BaseQBFilter // Provide the test query filter fixture.
{
    /** Keep fixture field maps outside the production constructor contract. */
    public function __construct( // Supply fixture dependencies while preserving the production filter contract.
        array $parameters = [], // Prepare parameters for this regression scenario.
        private array $allowedFields = [], // Retain allowed fields for this test fixture.
        ?FilterLimits $limits = null, // Prepare limits for this regression scenario.
    ) {
        parent::__construct($parameters, $limits); // Initialize request parameters and limits through the production base.
    }

    /** Expose only columns chosen by the current test case. */
    protected function fields(): array // Declare the public fields available to this fixture filter.
    {
        return $this->allowedFields; // Return the explicit field map owned by this test fixture.
    }
}
