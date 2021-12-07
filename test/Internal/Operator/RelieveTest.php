<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Emitter;
use function Amp\async;
use function Amp\delay;

class RelieveTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $this->expectOutputString('Done123');

        $pipeline = new AsyncGenerator(function () {
            yield 1;
            yield 2;
            yield 3;
            echo 'Done';
        });

        foreach ($pipeline->pipe(Pipeline\relieve()) as $value) {
            delay(0.01);
            echo $value;
        }
    }

    public function testPipelineFails(): void
    {
        $this->expectOutputString('1');

        $exception = new TestException;
        $source = new Emitter;

        $pipeline = $source->pipe()->pipe(Pipeline\relieve());

        $source->emit(1);
        $source->error($exception);

        try {
            foreach ($pipeline as $value) {
                echo $value;
            }
            $this->fail('Pipeline should have failed');
        } catch (TestException $exception) {
            // Ignore TestException.
        }
    }

    public function testDisposedPipeline(): void
    {
        $source = new Emitter;

        $pipeline = $source->pipe()->pipe(Pipeline\relieve());

        $future = async(fn () => $pipeline->continue());

        $source->yield(1);

        self::assertSame(1, $future->await());

        unset($pipeline);

        $this->expectException(DisposedException::class);

        $source->yield(2);
    }
}
