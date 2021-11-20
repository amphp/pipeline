<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;

class TakeWhileTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $expected = 2;
        $values = [1, 2, 3];
        $pipeline = Pipeline\fromIterable($values)->pipe(Pipeline\takeWhile(fn ($value) => $value < 3));

        $emitted = 0;
        while (null !== $value = $pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $value);
        }

        self::assertSame($expected, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->asPipeline()->pipe(Pipeline\takeWhile(fn ($value) => $value < 3));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPredicateThrows(): void
    {
        $exception = new TestException;

        $iterator = Pipeline\fromIterable([1, 2, 3])->pipe(Pipeline\takeWhile(fn ($value) => throw $exception));

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
