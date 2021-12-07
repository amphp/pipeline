<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;

/**
 * @template TValue
 * @template TMapped
 * @template-implements PipelineOperator<TValue, TMapped>
 *
 * @internal
 */
final class MapOperator implements PipelineOperator
{
    /**
     * @param \Closure(TValue):TMapped $map
     */
    public function __construct(private \Closure $map)
    {
    }

    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TMapped>
     */
    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                yield ($this->map)($value);
            }
        });
    }
}
