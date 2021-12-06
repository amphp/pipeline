<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;

class FinalizeTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];

        $invoked = 0;
        $pipeline = Pipeline\fromIterable($values)->pipe(Pipeline\finalize(function () use (&$invoked): void {
            $invoked++;
        }));

        Pipeline\discard($pipeline);

        self::assertSame(1, $invoked);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $invoked = 0;
        $pipeline = $source->pipe()->pipe(Pipeline\finalize(function () use (&$invoked): void {
            $invoked++;
        }));

        $source->error($exception);

        try {
            Pipeline\discard($pipeline);
            $this->fail('Pipeline should have failed');
        } catch (TestException $exception) {
            // Ignore TestException.
        }

        self::assertSame(1, $invoked);
    }

    public function testFinalizeCallbackThrows(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $pipeline = $source->pipe()->pipe(Pipeline\finalize(fn () => throw $exception));

        $source->complete();

        $this->expectExceptionObject($exception);

        Pipeline\discard($pipeline);
    }
}
