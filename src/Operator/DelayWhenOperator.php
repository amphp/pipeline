<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

final class DelayWhenOperator implements Operator
{
    public function __construct(
        private Pipeline $delay
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                if ($this->delay->continue() === null) {
                    return;
                }

                yield $value;
            }
        });
    }
}
