<?php
namespace Pcc\Preprocessor;

class Hideset
{
    public ?Hideset $next = null;
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}