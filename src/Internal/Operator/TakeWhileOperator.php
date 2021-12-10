<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use function Amp\Pipeline\fromIterable;

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
        return fromIterable(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                if (!($this->predicate)($value)) {
                    return;
                }

                yield $value;
            }
        });
    }
}
