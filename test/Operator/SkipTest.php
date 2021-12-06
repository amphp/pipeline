<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;

class SkipTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];
        $count = \count($values);
        $pipeline = Pipeline\fromIterable($values)->pipe(Pipeline\skip(1));

        \array_shift($values); // Shift off the first value that should be skipped.

        $emitted = 0;
        while (null !== $value = $pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $value);
        }

        self::assertSame($count - 1, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->pipe()->pipe(Pipeline\skip(1));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
