<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 *
 * @internal
 */
final class SkipWhileOperator implements Operator
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
            $skipping = true;
            foreach ($pipeline as $value) {
                if ($skipping && ($skipping = ($this->predicate)($value))) {
                    continue;
                }

                yield $value;
            }
        });
    }
}
