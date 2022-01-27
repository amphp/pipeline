<?php

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterator;

/** @internal */
interface IntermediateOperation
{
    public function __invoke(ConcurrentIterator $source): ConcurrentIterator;
}
