<?php
namespace Pcc;

class StringArray
{
    private array $data = [];

    public function push(string $s): void
    {
        $this->data[] = $s;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getLength(): int
    {
        return count($this->data);
    }
}