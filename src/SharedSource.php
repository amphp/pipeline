<?php

namespace Amp\Pipeline;

use Amp\Future;
use Revolt\EventLoop;

/**
 * @internal
 *
 * @template TValue
 */
final class SharedSource
{
    /** @var Emitter[] */
    private array $emitters = [];

    /**
     * @internal Use {@see share()} instead of directly creating an instance of this class.
     */
    public function __construct(
        private Pipeline $pipeline,
    ) {
    }

    private function disperse(): void
    {
        $emitters = &$this->emitters;
        $pipeline = $this->pipeline;

        EventLoop::queue(static function () use (&$emitters, $pipeline): void {
            try {
                foreach ($pipeline as $item) {
                    Future\all(\array_map(static fn (Emitter $emitter) => $emitter->emit($item)->catch(
                        static function () use (&$emitters, $emitter, $pipeline): void {
                            foreach ($emitters as $index => $active) {
                                if ($active === $emitter) {
                                    unset($emitters[$index]);
                                    break;
                                }
                            }

                            if (empty($emitters)) {
                                $pipeline->dispose();
                            }
                        }
                    ), $emitters));
                }

                foreach ($emitters as $emitter) {
                    $emitter->complete();
                }
            } catch (\Throwable $exception) {
                foreach ($emitters as $emitter) {
                    $emitter->error($exception);
                }
            } finally {
                $emitters = [];
            }
        });
    }

    /**
     * @return Pipeline<TValue>
     */
    public function pipe(): Pipeline
    {
        $disperse = empty($this->emitters);
        $this->emitters[] = $emitter = new Emitter();

        if ($disperse) {
            $this->disperse();
        }

        return $emitter->pipe();
    }
}
