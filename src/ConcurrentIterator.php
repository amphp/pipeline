<?php

namespace Amp\Pipeline;

use Amp\Cancellation;

/**
 * @template T
 */
interface ConcurrentIterator extends \Traversable
{
    /**
     * Advances the iterator to the next value, returning {@code true} if the iterator has emitted a value or
     * {@code false} if the iterator has completed. If the iterator errors, the exception will be thrown from this
     * method.
     *
     * @param Cancellation|null $cancellation Cancels waiting for the next emitted value. If cancelled, the next
     * emitted value is not lost, but will be sent to the next call to this method.
     *
     * @return bool {@code true} if a value is available, {@code false} if the pipeline has completed.
     */
    public function continue(?Cancellation $cancellation = null): bool;

    /**
     * Returns the current value emitted by the iterator for the current fiber.
     *
     * Advance the iterator to the next value using {@see continue()}, which must be called before this method may be
     * called for each value.
     *
     * @return T The current value emitted by the iterator. If the iterator has completed or {@see continue()} has
     * not been called, an {@see \Error} will be thrown.
     */
    public function getValue(): mixed;

    /**
     * Returns the current position of the iterator for the current fiber.
     *
     * Advance the iterator to the next position using {@see continue()}, which must be called before this method may be
     * called for each position.
     *
     * @return T The current position of the iterator. If the iterator has completed or {@see continue()} has
     * not been called, an {@see \Error} will be thrown.
     */
    public function getPosition(): int;

    /**
     * Disposes the iterator, indicating the consumer is no longer interested in the iterator output.
     */
    public function dispose(): void;
}
