<?php

declare(strict_types=1);

namespace Pcc\HashMap;

class HashEntry
{
    public ?string $key = null;
    public int $keylen = 0;
    public mixed $val = null;
    public bool $isTombstone = false;

    public function __construct(?string $key = null, int $keylen = 0, mixed $val = null)
    {
        $this->key = $key;
        $this->keylen = $keylen;
        $this->val = $val;
        $this->isTombstone = false;
    }

    public function markAsTombstone(): void
    {
        $this->isTombstone = true;
    }

    public function isNull(): bool
    {
        return $this->key === null and !$this->isTombstone;
    }

    public function isEmpty(): bool
    {
        return $this->key === null;
    }
}