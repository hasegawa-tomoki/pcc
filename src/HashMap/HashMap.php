<?php

declare(strict_types=1);

namespace Pcc\HashMap;

class HashMap
{
    // Initial hash bucket size
    private const INIT_SIZE = 16;

    // Rehash if the usage exceeds 70%.
    private const HIGH_WATERMARK = 70;

    // We'll keep the usage below 50% after rehashing.
    private const LOW_WATERMARK = 50;

    /** @var HashEntry[] */
    private array $buckets = [];
    private int $capacity = 0;
    private int $used = 0;

    public function __construct()
    {
        // Initialize with empty buckets
    }

    private function fnvHash(string $s, int $len): int
    {
        // Use a simpler hash function to avoid floating point precision issues
        $hash = 5381;
        for ($i = 0; $i < $len; $i++) {
            $hash = (($hash << 5) + $hash + ord($s[$i])) & 0x7FFFFFFF;
        }
        return $hash;
    }

    private function rehash(): void
    {
        // Compute the size of the new hashmap.
        $nkeys = 0;
        for ($i = 0; $i < $this->capacity; $i++) {
            if ($this->buckets[$i]->key !== null and !$this->buckets[$i]->isTombstone) {
                $nkeys++;
            }
        }

        $cap = $this->capacity;
        while (($nkeys * 100) / $cap >= self::LOW_WATERMARK) {
            $cap = $cap * 2;
        }

        // Create a new hashmap and copy all key-values.
        $newBuckets = [];
        for ($i = 0; $i < $cap; $i++) {
            $newBuckets[$i] = new HashEntry();
        }

        $oldBuckets = $this->buckets;
        $oldCapacity = $this->capacity;

        $this->buckets = $newBuckets;
        $this->capacity = $cap;
        $this->used = 0;

        for ($i = 0; $i < $oldCapacity; $i++) {
            $ent = $oldBuckets[$i];
            if ($ent->key !== null and !$ent->isTombstone) {
                $this->put2($ent->key, $ent->keylen, $ent->val);
            }
        }
    }

    private function match(HashEntry $ent, string $key, int $keylen): bool
    {
        return $ent->key !== null and !$ent->isTombstone and
            $ent->keylen === $keylen and substr($ent->key, 0, $keylen) === substr($key, 0, $keylen);
    }

    private function getEntry(string $key, int $keylen): ?HashEntry
    {
        if (empty($this->buckets)) {
            return null;
        }

        $hash = $this->fnvHash($key, $keylen);

        for ($i = 0; $i < $this->capacity; $i++) {
            $ent = $this->buckets[($hash + $i) % $this->capacity];
            if ($this->match($ent, $key, $keylen)) {
                return $ent;
            }
            if ($ent->isNull()) {
                return null;
            }
        }
        
        throw new \RuntimeException('unreachable');
    }

    private function getOrInsertEntry(string $key, int $keylen): HashEntry
    {
        if (empty($this->buckets)) {
            for ($i = 0; $i < self::INIT_SIZE; $i++) {
                $this->buckets[$i] = new HashEntry();
            }
            $this->capacity = self::INIT_SIZE;
        } elseif (($this->used * 100) / $this->capacity >= self::HIGH_WATERMARK) {
            $this->rehash();
        }

        $hash = $this->fnvHash($key, $keylen);

        for ($i = 0; $i < $this->capacity; $i++) {
            $ent = $this->buckets[($hash + $i) % $this->capacity];

            if ($this->match($ent, $key, $keylen)) {
                return $ent;
            }

            if ($ent->isTombstone) {
                $ent->key = $key;
                $ent->keylen = $keylen;
                $ent->isTombstone = false;
                return $ent;
            }

            if ($ent->isNull()) {
                $ent->key = $key;
                $ent->keylen = $keylen;
                $this->used++;
                return $ent;
            }
        }
        
        throw new \RuntimeException('unreachable');
    }

    public function get(string $key): mixed
    {
        return $this->get2($key, strlen($key));
    }

    public function get2(string $key, int $keylen): mixed
    {
        $ent = $this->getEntry($key, $keylen);
        return $ent ? $ent->val : null;
    }

    public function put(string $key, mixed $val): void
    {
        $this->put2($key, strlen($key), $val);
    }

    public function put2(string $key, int $keylen, mixed $val): void
    {
        $ent = $this->getOrInsertEntry($key, $keylen);
        $ent->val = $val;
    }

    public function delete(string $key): void
    {
        $this->delete2($key, strlen($key));
    }

    public function delete2(string $key, int $keylen): void
    {
        $ent = $this->getEntry($key, $keylen);
        if ($ent) {
            $ent->markAsTombstone();
        }
    }

    public static function test(): void
    {
        $map = new HashMap();

        for ($i = 0; $i < 5000; $i++) {
            $map->put("key $i", $i);
        }
        for ($i = 1000; $i < 2000; $i++) {
            $map->delete("key $i");
        }
        for ($i = 1500; $i < 1600; $i++) {
            $map->put("key $i", $i);
        }
        for ($i = 6000; $i < 7000; $i++) {
            $map->put("key $i", $i);
        }

        for ($i = 0; $i < 1000; $i++) {
            assert($map->get("key $i") === $i);
        }
        for ($i = 1000; $i < 1500; $i++) {
            assert($map->get("no such key") === null);
        }
        for ($i = 1500; $i < 1600; $i++) {
            assert($map->get("key $i") === $i);
        }
        for ($i = 1600; $i < 2000; $i++) {
            assert($map->get("no such key") === null);
        }
        for ($i = 2000; $i < 5000; $i++) {
            assert($map->get("key $i") === $i);
        }
        for ($i = 5000; $i < 6000; $i++) {
            assert($map->get("no such key") === null);
        }
        for ($i = 6000; $i < 7000; $i++) {
            $map->put("key $i", $i);
        }

        assert($map->get("no such key") === null);
        echo "OK\n";
    }
}