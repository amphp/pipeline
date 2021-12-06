<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 */
final class TakeOperator implements Operator
{
    public function __construct(
        private int $count
    ) {
        if ($count < 0) {
            throw new \Error('Number of items to take must be a non-negative integer');
        }
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            $taken = 0;
            while ($taken++ < $this->count) {
                if (null !== $value = $pipeline->continue()) {
                    yield $value;
                }
            }
        });
    }
}
