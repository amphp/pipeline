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
