<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class TapTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $values = [1, 2, 3];

        $invoked = 0;
        $pipeline = Pipeline::fromIterable($values)->tap(function () use (&$invoked): void {
            $invoked++;
        });

        self::assertSame(0, $invoked);

        $pipeline->forEach(fn () => null);

        self::assertSame(3, $invoked);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $invoked = 0;
        $pipeline = $source->pipe()->tap(function () use (&$invoked): void {
            $invoked++;
        });

        $source->pushAsync(1)->ignore();
        $source->error($exception);

        try {
            $pipeline->forEach(fn () => null);
            $this->fail('Pipeline should have failed');
        } catch (TestException) {
            // Ignore TestException.
        }

        self::assertSame(1, $invoked);
    }

    public function testTapCallbackThrows(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $pipeline = $source->pipe()->tap(fn () => throw $exception);

        $source->pushAsync(1)->ignore();

        $this->expectExceptionObject($exception);

        $pipeline->forEach(fn () => null);
    }
}
