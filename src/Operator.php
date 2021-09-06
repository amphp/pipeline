<?php

namespace Amp\Pipeline;

/**
 * @template TValue
 * @template TResult
 */
interface Operator
{
    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TResult>
     */
    public function pipe(Pipeline $pipeline): Pipeline;
}
