<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class DelayTest extends AsyncTestCase
{
    public function testDelayAppliedToEachValueEmitted(): void
    {
        $this->setMinimumRuntime(0.05);
        $pipeline = Pipeline::fromIterable(\range(1, 5));
        $pipeline->delay(0.01)->toArray();
    }

    /**
     * @depends testDelayAppliedToEachValueEmitted
     */
    public function testConcurrentDelay(): void
    {
        $this->setMinimumRuntime(0.05);
        $this->setTimeout(0.1);
        $pipeline = Pipeline::fromIterable(\range(1, 50));
        $pipeline->concurrent(10)->delay(0.01)->toArray();

    }
}
