<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use function Revolt\EventLoop\delay;

final class DelayOperator implements Operator
{
    public function __construct(
        private float $delay
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                delay($this->delay);
                yield $value;
            }
        });
    }
}
