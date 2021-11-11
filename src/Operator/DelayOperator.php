<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use function Amp\delay;

final class DelayOperator implements Operator
{
    public function __construct(
        private float $delay
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            while (true) {
                delay($this->delay);

                $value = $pipeline->continue(); // Consume value after delay.
                if ($value === null) {
                    return;
                }

                yield $value;
            }
        });
    }
}
