<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\PipelineOperator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 *
 * @internal
 */
final class TapOperator implements PipelineOperator
{
    /**
     * @param \Closure(TValue):void $tap
     */
    public function __construct(private \Closure $tap)
    {
    }

    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TValue>
     */
    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                ($this->tap)($value);
                yield $value;
            }
        });
    }
}
