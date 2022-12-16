<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class SkipTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];
        $count = \count($values);
        $pipeline = Pipeline::fromIterable($values)->skip(1)->getIterator();

        \array_shift($values); // Shift off the first value that should be skipped.

        $emitted = 0;
        while ($pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $pipeline->getValue());
        }

        self::assertSame($count - 1, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $iterator = $source->pipe()->skip(1)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
