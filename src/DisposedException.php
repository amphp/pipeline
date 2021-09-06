<?php

namespace Amp\Pipeline;

/**
 * Will be thrown from {@see Subject::emit()} or the emit callable provided by {@see AsyncGenerator} if the
 * associated pipeline is destroyed.
 */
final class DisposedException extends \Exception
{
    public function __construct(\Throwable $previous = null)
    {
        parent::__construct("The pipeline has been disposed", 0, $previous);
    }
}
