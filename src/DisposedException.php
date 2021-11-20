<?php

namespace Amp\Pipeline;

/**
 * Will be thrown from {@see Emitter::yield()}, used to fail the future returned from {@see Emitter::emit()}, or
 * thrown from `yield` in the generator provided to {@see AsyncGenerator} if the associated pipeline is destroyed.
 */
final class DisposedException extends \Exception
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("The pipeline has been disposed", 0, $previous);
    }
}
