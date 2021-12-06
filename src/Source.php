<?php

namespace Amp\Pipeline;

/**
 * @template TValue
 */
interface Source
{
    /**
     * @return Pipeline<TValue>
     */
    public function pipe(): Pipeline;
}
