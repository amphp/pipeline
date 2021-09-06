<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 */
final class SkipWhileOperator implements Operator
{
    private $predicate;

    /**
     * @param callable(TValue):bool $predicate
     */
    public function __construct(callable $predicate) {
        $this->predicate = $predicate;
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
