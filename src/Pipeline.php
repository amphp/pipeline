<?php

namespace Amp\Pipeline;

use Amp\Cancellation;

/**
 * A pipeline is an asynchronous set of ordered values.
 *
 * @template TValue
 * @template-extends \Traversable<int, TValue>
 */
interface Pipeline extends \Traversable
{
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
     * @return mixed Returns null if the pipeline has completed.
     *
     * @psalm-return TValue|null
     */
    public function continue(?Cancellation $cancellation = null): mixed;

    /**
     * Disposes the pipeline, indicating the consumer is no longer interested in the pipeline output.
     *
     * @return void
     */
    public function dispose(): void;

    /**
     * @template TResult
     *
     * @param Operator ...$operators
     * @return Pipeline<TResult>
     */
    public function pipe(Operator ...$operators): Pipeline;

    /**
     * @return bool True if the pipeline has completed, either successfully or with an error.
     */
    public function isComplete(): bool;

    /**
     * @return bool True if the pipeline was disposed.
     */
    public function isDisposed(): bool;
}
