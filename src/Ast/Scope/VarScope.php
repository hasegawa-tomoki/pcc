<?php
namespace Pcc\Ast\Scope;

use Pcc\Ast\Obj;

class VarScope
{
    public string $name;
    public int $depth;
    public Obj $var;
}