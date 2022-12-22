<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterator;

/** @internal */
final class SortOperation implements IntermediateOperation
{
    private readonly \Closure $compare;

    public function __construct(\Closure $compare)
    {
        $this->compare = $compare;
    }

    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        $values = \iterator_to_array($source, false);
        \usort($values, $this->compare);

        return new ConcurrentArrayIterator($values);
    }
}
