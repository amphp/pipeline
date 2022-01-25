<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class SkipWhileTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2, 3])
            ->skipWhile(fn ($value) => $value < 2);

        self::assertSame([2, 3], $pipeline->toArray());
    }

    public function testPredicateInvokedAsNeeded(): void
    {
        $invoked = 0;

        $pipeline = Pipeline::fromIterable([1, 2, 3])
            ->skipWhile(function ($value) use (&$invoked) {
                $invoked++;

                return $value < 2;
            });

        self::assertSame([2, 3], $pipeline->toArray());
        self::assertSame(2, $invoked);
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

        $iterator = Pipeline::fromIterable([1, 2, 3])->skipWhile(fn ($value) => throw $exception)->getIterator();

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
