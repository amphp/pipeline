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
final class FinalizeOperator implements PipelineOperator
{
    /**
     * @param \Closure():void $onComplete
     */
    public function __construct(private \Closure $finalize)
    {
    }

    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TValue>
     */
    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            try {
                yield from $pipeline;
            } finally {
                ($this->finalize)();
            }
        });
    }
}