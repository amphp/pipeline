<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;

/**
 * Wraps an EmitSource instance that has public methods to emit, complete, and fail into an object that only allows
 * access to the public API methods and automatically calls EmitSource::destroy() when the object is destroyed.
 *
 * @internal
 *
 * @template TValue
 * @template-implements Pipeline<TValue>
 * @template-implements \IteratorAggregate<int, TValue>
 */
final class AutoDisposingPipeline implements Pipeline, \IteratorAggregate
{
    /** @var EmitSource<TValue> */
    private EmitSource $source;

    public function __construct(EmitSource $source)
    {
        $this->source = $source;
    }

    public function __destruct()
    {
        $this->source->dispose();
    }

    public function continue(?Cancellation $cancellation = null): mixed
    {
        return $this->source->continue($cancellation);
    }

    public function dispose(): void
    {
        $this->source->dispose();
    }

    /**
     * @template TResult
     *
     * @param PipelineOperator ...$operators
     *
     * @return Pipeline<TResult>
     */
    public function pipe(PipelineOperator ...$operators): Pipeline
    {
        $pipeline = $this;

        foreach ($operators as $operator) {
            $pipeline = $operator->pipe($pipeline);
        }

        /** @var Pipeline<TResult> $pipeline */
        return $pipeline;
    }

    public function isComplete(): bool
    {
        return $this->source->isConsumed();
    }

    public function isDisposed(): bool
    {
        return $this->source->isDisposed();
    }

    /**
     * @psalm-return \Traversable<int, TValue>
     */
    public function getIterator(): \Traversable
    {
        while (null !== $value = $this->source->continue()) {
            yield $value;
        }
    }
}
