<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\PipelineOperator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class TakeWhileOperator implements PipelineOperator
{
    /**
     * @param \Closure(TValue):bool $predicate
     */
    public function __construct(private \Closure $predicate)
    {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                if (!($this->predicate)($value)) {
                    return;
                }

                yield $value;
            }
        });
    }
}
