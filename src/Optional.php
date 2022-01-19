<?php

namespace Amp\Pipeline;

/**
 * A container for an optional value, i.e. {@code null} or not {@code null}.
 *
 * This can be used with {@see Pipeline} for potentially {@code null} values, because {@code null} values aren't
 * directly permitted in {@see Pipeline}.
 *
 * This is a value-based class; use of identity-sensitive operations (such as reference equality {@code ===}) may have
 * unpredictable results and should be avoided, e.g. empty optionals may return the same instance.
 *
 * @template T
 */
final class Optional
{
    private static self $empty;

    /**
     * Wraps the value in an {@see Optional} instance.
     *
     * @param T|null $value Value to wrap.
     *
     * @return self<T>
     */
    public static function of(mixed $value): self
    {
        if ($value === null) {
            return self::empty();
        }

        return new self($value);
    }

    /**
     * Returns an instance wrapping the {@code null} value.
     *
     * @return self<null>
     */
    public static function empty(): self
    {
        return self::$empty ??= new self(null);
    }

    /** @var T|null */
    private mixed $value;

    private function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * Unwraps the value from the container.
     *
     * You can use the null coalesce operator to provide a fallback value or to throw an exception if the value is not
     * present:
     *
     * ```php
     * $optional->get() ?? 42;
     * $optional->get() ?? throw new \Exception('Missing value');
     * ```
     *
     * @return T|null Returns the wrapped value, which might be {@code null}.
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * Transforms the given value if present.
     *
     * @template R
     *
     * @param \Closure(T): R $map
     *
     * @return self<R>
     */
    public function map(\Closure $map): self
    {
        if ($this->value === null) {
            return $this;
        }

        return self::of($map($this->value));
    }

    /**
     * Returns the current instance of an empty instance if {@code $filter} returns {@code false}.
     *
     * @param \Closure(T): bool $filter
     *
     * @return self<T>
     */
    public function filter(\Closure $filter): self
    {
        if ($this->value === null || $filter($this->value)) {
            return $this;
        }

        return self::empty();
    }
}
