<?php
namespace Pcc\Ast\Scope;

use Pcc\Ast\Obj;
use Pcc\Ast\Type;

class VarScope
{
    public string $name;
    public int $depth;
    public ?Obj $var;
    public ?Type $typeDef = null;
}