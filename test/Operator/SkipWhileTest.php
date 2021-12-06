<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;

class SkipWhileTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];
        $count = \count($values);
        $pipeline = Pipeline\fromIterable($values)->pipe(Pipeline\skipWhile(fn ($value) => $value < 2));

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

        $iterator = $source->pipe()->pipe(Pipeline\skipWhile(fn ($value) => $value < 2));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPredicateThrows(): void
    {
        $exception = new TestException;

        $iterator = Pipeline\fromIterable([1, 2, 3])->pipe(Pipeline\skipWhile(fn ($value) => throw $exception));

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
