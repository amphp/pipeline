<?php

namespace Amp\Pipeline;

use Amp\Cancellation;

/**
 * A pipeline is an asynchronous set of ordered values.
 *
 * @template TValue
 * @template-implements \IteratorAggregate<int, TValue>
 */
final class Pipeline implements \IteratorAggregate
{
    /** @var Internal\EmitSource<TValue> */
    private Internal\EmitSource $source;

    /**
     * @internal Create a Pipeline using either {@see Emitter::pipe()} or {@see fromIterable()}.
     *
     * @param Internal\EmitSource $source
     */
    public function __construct(Internal\EmitSource $source)
    {
        $this->source = $source;
    }

    public function __destruct()
    {
        $this->source->dispose();
    }

    /**
     * Returns the emitted value if the pipeline has emitted a value or null if the pipeline has completed.
     * If the pipeline fails, the exception will be thrown from this method.
     *
     * This method exists primarily for async consumption of a single value within a coroutine. In general, a
     * pipeline may be consumed using foreach ($pipeline as $value) { ... }.
     *
     * @param Cancellation|null $cancellation Cancels waiting for the next emitted value. If cancelled, the next
     * emitted value is not lost, but will be sent to the next call to this method.
     *
     * @return TValue|null Returns null if the pipeline has completed.
     */
    public function continue(?Cancellation $cancellation = null): mixed
    {
        return $this->source->continue($cancellation);
    }

    /**
     * Disposes the pipeline, indicating the consumer is no longer interested in the pipeline output.
     *
     * @return void
     */
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

    /**
     * @template TResult
     *
     * @param \Closure(TValue):TResult $map
     *
     * @return self<TResult>
     */
    public function map(\Closure $map): self
    {
        return $this->pipe(map($map));
    }

    /**
     * @param \Closure(TValue):bool $filter
     *
     * @return self<TValue>
     *
     * @see filter()
     */
    public function filter(\Closure $filter): self
    {
        return $this->pipe(filter($filter));
    }

    /**
     * @return bool True if the pipeline has completed, either successfully or with an error.
     */
    public function isComplete(): bool
    {
        return $this->source->isConsumed();
    }

    /**
     * @return bool True if the pipeline was disposed.
     */
    public function isDisposed(): bool
    {
        return $this->source->isDisposed();
    }

    /**
     * @return \Traversable<int, TValue>
     */
    public function getIterator(): \Traversable
    {
        while (null !== $value = $this->source->continue()) {
            yield $value;
        }
    }
}
