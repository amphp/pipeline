<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class SkipWhileTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];
        $count = \count($values);
        $pipeline = Pipeline\fromIterable($values)->skipWhile(fn ($value) => $value < 2);

        \array_shift($values); // Shift off the first value that should be skipped.

        $emitted = 0;
        while ($pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $pipeline->get());
        }

        self::assertSame($count - 1, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->pipe()->skipWhile(fn ($value) => $value < 2)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPredicateThrows(): void
    {
        $exception = new TestException;

        $iterator = Pipeline\fromIterable([1, 2, 3])->skipWhile(fn ($value) => throw $exception)->getIterator();

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
