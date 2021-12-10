<?php

namespace Amp\Pipeline;

use Amp\Future;

/**
 * Emitter is a container for a Pipeline that can emit values using the emit() method and completed using the
 * complete() and fail() methods. The contained Pipeline may be accessed using the pipeline() method. This object should
 * not be returned as part of a public API, but used internally to create and emit values to a Pipeline.
 *
 * @template TValue
 * @template-implements Source<TValue>
 */
final class Emitter implements Source
{
    /** @var Internal\EmitSource<TValue> Has public emit, complete, and fail methods. */
    private Internal\EmitSource $source;

    private bool $used = false;

    /**
     * @param int $bufferSize Allowed number of items to internally buffer before awaiting backpressure from the
     * consumer of the pipeline.
     */
    public function __construct(int $bufferSize = 0)
    {
        $this->source = new Internal\EmitSource($bufferSize);
    }

    public function __destruct()
    {
        if (!$this->source->isComplete() && !$this->source->isDisposed()) {
            $this->source->error(new \Error('Pipeline source destroyed without completing the pipeline'));
        }
    }

    /**
     * Returns a Pipeline that can be given to an API consumer. This method may be called only once!
     * Use {@see share()} to create a source that can create any number of identical pipelines.
     *
     * @return Pipeline<TValue>
     *
     * @throws \Error If this method is called more than once.
     */
    public function pipe(): Pipeline
    {
        if ($this->used) {
            throw new \Error(
                'A pipeline may be started only once; ' .
                'Use share($emitter->pipe()) to create multiple pipelines using this emitter as the source'
            );
        }

        $this->used = true;

        return new Internal\AutoDisposingPipeline($this->source);
    }

    /**
     * Emits a value to the pipeline, returning a promise that is resolved once the emitted value is consumed.
     * Use {@see yield()} to wait until the value is consumed or use {@see await()} on the promise returned
     * to wait at a later time.
     *
     * @param TValue $value
     *
     * @return Future<null> Resolves with null when the emitted value has been consumed or fails with
     *                       {@see DisposedException} if the pipeline has been disposed.
     */
    public function emit(mixed $value): Future
    {
        return $this->source->emit($value);
    }

    /**
     * Emits a value to the pipeline and does not return until the emitted value is consumed.
     * Use {@see emit()} to emit a value without waiting for the value to be consumed.
     *
     * @param TValue $value
     *
     * @throws DisposedException Thrown if the pipeline is disposed.
     */
    public function yield(mixed $value): void
    {
        $this->source->yield($value);
    }

    /**
     * @return bool True if the pipeline has been completed or failed.
     */
    public function isComplete(): bool
    {
        return $this->source->isComplete();
    }

    /**
     * @return bool True if the pipeline has been disposed.
     */
    public function isDisposed(): bool
    {
        return $this->source->isDisposed();
    }

    /**
     * Completes the pipeline.
     *
     * @return void
     */
    public function complete(): void
    {
        $this->source->complete();
    }

    /**
     * Fails the pipeline with the given reason.
     *
     * @param \Throwable $reason
     *
     * @return void
     */
    public function error(\Throwable $reason): void
    {
        $this->source->error($reason);
    }
}
