<?php
namespace Pcc\Ast\Scope;

use Pcc\Ast\Obj;
use Pcc\Ast\Type;

class VarScope
{
    public ?Obj $var = null;
    public ?Type $typeDef = null;
    public ?Type $enumTy = null;
    public int $enumVal = 0;
}