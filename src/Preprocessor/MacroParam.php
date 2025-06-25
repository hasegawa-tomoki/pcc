<?php
namespace Pcc\Preprocessor;

class MacroParam
{
    public ?MacroParam $next = null;
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}