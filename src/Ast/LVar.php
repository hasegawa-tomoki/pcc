<?php
namespace Pcc\Ast;
class LVar
{
    public string $name;
    public Type $ty;
    public int $offset;
    public int $len {
        get {
            return strlen($this->name);
        }
    }

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}