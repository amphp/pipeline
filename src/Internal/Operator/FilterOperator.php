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
final class FilterOperator implements PipelineOperator
{
    /**
     * @param \Closure(TValue):bool $filter
     */
    public function __construct(private \Closure $filter)
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
                if (($this->filter)($value)) {
                    yield $value;
                }
            }
        });
    }
}
