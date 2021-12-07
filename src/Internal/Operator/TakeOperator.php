<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class TakeOperator implements PipelineOperator
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
