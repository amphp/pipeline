<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 *
 * @template-implements Operator<TValue, TValue>
 */
final class TapOperator implements Operator
{
    private $tap;

    /**
     * @param callable(TValue):void $tap
     */
    public function __construct(callable $tap)
    {
        $this->tap = $tap;
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
