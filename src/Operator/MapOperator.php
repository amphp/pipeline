<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template TMapped
 *
 * @template-implements Operator<TValue, TMapped>
 */
final class MapOperator implements Operator
{
    /**
     * @param \Closure(TValue):TMapped $onEmit
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
