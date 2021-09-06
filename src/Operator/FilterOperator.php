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
final class FilterOperator implements Operator
{
    private $filter;

    /**
     * @param callable(TValue):bool $filter
     */
    public function __construct(callable $filter)
    {
        $this->filter = $filter;
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
