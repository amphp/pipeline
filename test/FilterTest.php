<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class FilterTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::markTestSkipped('Filter should be lazy');
    }

    public function testNoValuesEmitted(): void
    {
        $source = new Emitter;

        $pipeline = $source->pipe()->filter($this->createCallback(0));

        $source->complete();

        self::assertSame(0, $pipeline->count());
    }

    public function testValuesEmitted(): void
    {
        $count = 0;
        $values = [1, 2, 3];
        $expected = [1, 3];
        $generator = Pipeline\fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->filter(function ($value) use (&$count): bool {
            ++$count;
            return (bool) ($value & 1);
        });

        while ($pipeline->continue()) {
            self::assertSame(\array_shift($expected), $pipeline->get());
        }

        self::assertSame(3, $count);
    }

    /**
     * @depends testValuesEmitted
     */
    public function testCallbackThrows(): void
    {
        $values = [1, 2, 3];
        $exception = new TestException;
        $generator = Pipeline\fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->filter(fn () => throw $exception);

        $this->expectExceptionObject($exception);

        $pipeline->continue();
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $pipeline = $source->pipe()->filter($this->createCallback(0));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $pipeline->continue();
    }
}
