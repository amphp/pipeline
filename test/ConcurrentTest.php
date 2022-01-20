<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use function Amp\delay;

class ConcurrentTest extends AsyncTestCase
{
    public function testNoValuesEmitted(): void
    {
        $source = new Emitter;

        $pipeline = $source->pipe()
            ->concurrent(3)
            ->map($this->createCallback(0));

        $source->complete();

        self::assertSame(0, $pipeline->count());
    }

    public function testConcurrency(): void
    {
        $range = \range(0, 100);

        $source = Pipeline\fromIterable($range);

        $results = $source->concurrent(3)
            ->tap(fn (int $value) => delay(\random_int(0, 10) / 1000))
            ->toArray();

        self::assertNotSame($range, $results); // Arrays should not match as values should be randomly ordered.

        foreach ($range as $value) {
            self::assertContains(needle: $value, haystack: $results);
        }
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $pipeline = $source->pipe()
            ->concurrent(3)
            ->tap($this->createCallback(1));

        $source->emit(1)->ignore();

        $source->error($exception);

        self::assertTrue($pipeline->continue());
        self::assertSame(1, $pipeline->get());

        $this->expectExceptionObject($exception);

        $pipeline->continue();
    }
}
