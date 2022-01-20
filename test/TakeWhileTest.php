<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class TakeWhileTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $expected = 2;
        $values = [1, 2, 3];
        $pipeline = Pipeline\fromIterable($values)->takeWhile(fn ($value) => $value < 3);

        $emitted = 0;
        while ($pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $pipeline->get());
        }

        self::assertSame($expected, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->pipe()->takeWhile(fn ($value) => $value < 3);

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPredicateThrows(): void
    {
        $exception = new TestException;

        $iterator = Pipeline\fromIterable([1, 2, 3])->takeWhile(fn ($value) => throw $exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
