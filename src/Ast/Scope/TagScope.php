<?php
namespace Pcc\Ast\Scope;

use Pcc\Ast\Type;

class TagScope
{
    public string $name;
    public int $depth;
    public Type $ty;
}