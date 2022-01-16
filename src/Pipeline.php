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
     * @internal Create a Pipeline using either {@see Emitter} or {@see fromIterable()}.
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
