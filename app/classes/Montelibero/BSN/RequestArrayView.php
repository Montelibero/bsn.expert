<?php

namespace Montelibero\BSN;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Stable Twig-facing view over request superglobals.
 *
 * The object can live for the whole worker lifetime while being rebound to
 * fresh request arrays before every request.
 */
class RequestArrayView implements ArrayAccess, Countable, IteratorAggregate
{
    private array $data = [];

    public function bind(array &$data): void
    {
        $this->data = &$data;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
            return;
        }

        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        yield from $this->data;
    }
}
