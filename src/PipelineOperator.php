<?php

namespace Amp\Pipeline;

/**
 * @template TValue
 * @template TResult
 */
interface PipelineOperator
{
    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TResult>
     */
    public function pipe(Pipeline $pipeline): Pipeline;
}
