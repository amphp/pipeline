<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class TakeWhileTest extends AsyncTestCase
{
    public function testAllValuesTrue(): void
    {
        $pipeline = Pipeline\fromIterable([1, 2])->takeWhile(fn ($value) => $value < 3);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testSomeValuesTrue(): void
    {
        $pipeline = Pipeline\fromIterable([1, 2, 3, 4, 5])->takeWhile(fn ($value) => $value < 3);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testSomeValuesTrueAfterFalse(): void
    {
        $pipeline = Pipeline\fromIterable([1, 2, 3, 4, 5, 4, 3, 2, 1])->takeWhile(fn ($value) => $value < 3);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->pipe()->takeWhile(fn ($value) => $value < 3)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPredicateThrows(): void
    {
        $exception = new TestException;

        $iterator = Pipeline\fromIterable([1, 2, 3])->takeWhile(fn ($value) => throw $exception)->getIterator();

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
