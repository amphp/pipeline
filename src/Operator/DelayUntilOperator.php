<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

final class DelayUntilOperator implements Operator
{
    public function __construct(
        private Pipeline $delay
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            while ($this->delay->continue() !== null) {
                $value = $pipeline->continue();
                if ($value === null) {
                    $this->delay->dispose();
                    return;
                }

                yield $value;
            }
        });
    }
}
