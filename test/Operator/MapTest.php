<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline;
use Amp\Pipeline\Subject;

class MapTest extends AsyncTestCase
{
    public function testNoValuesEmitted(): void
    {
        $source = new Subject;

        /** @noinspection PhpUnusedLocalVariableInspection */
        $pipeline = $source->asPipeline()->pipe(Pipeline\map($this->createCallback(0)));

        $source->complete();
    }

    public function testValuesEmitted(): void
    {
        $count = 0;
        $values = [1, 2, 3];
        $generator = new AsyncGenerator(static function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->pipe(Pipeline\map(static function ($value) use (&$count) {
            ++$count;

            return $value + 1;
        }));

        while (null !== $value = $pipeline->continue()) {
            self::assertSame(\array_shift($values) + 1, $value);
        }

        self::assertSame(3, $count);
    }

    /**
     * @depends testValuesEmitted
     */
    public function testOnNextCallbackThrows(): void
    {
        $values = [1, 2, 3];
        $exception = new TestException;

        $generator = new AsyncGenerator(static function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->pipe(Pipeline\map(static function () use ($exception) {
            throw $exception;
        }));

        $this->expectExceptionObject($exception);

        $pipeline->continue();
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Subject;

        $iterator = $source->asPipeline()->pipe(Pipeline\map($this->createCallback(0)));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
