<?php

namespace Amp\Pipeline;

/**
 * Will be thrown from {@see Emitter::yield()} or used to fail the future returned from {@see Emitter::emit()}
 * if the associated pipeline is destroyed.
 */
final class DisposedException extends \Exception
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("The pipeline has been disposed", 0, $previous);
    }
}
