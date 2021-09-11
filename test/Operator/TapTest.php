<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Subject;

class TapTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];

        $invoked = 0;
        $pipeline = Pipeline\fromIterable($values)->pipe(Pipeline\tap(function () use (&$invoked): void {
            $invoked++;
        }));

        Pipeline\discard($pipeline);

        self::assertSame(3, $invoked);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Subject;

        $invoked = 0;
        $pipeline = $source->asPipeline()->pipe(Pipeline\tap(function () use (&$invoked): void {
            $invoked++;
        }));

        $source->emit(1);
        $source->error($exception);

        try {
            Pipeline\discard($pipeline);
            $this->fail('Pipeline should have failed');
        } catch (TestException $exception) {
            // Ignore TestException.
        }

        self::assertSame(1, $invoked);
    }

    public function testTapCallbackThrows(): void
    {
        $exception = new TestException;
        $source = new Subject;

        $pipeline = $source->asPipeline()->pipe(Pipeline\tap(fn() => throw $exception));

        $source->emit(1);

        $this->expectExceptionObject($exception);

        Pipeline\discard($pipeline);
    }
}
