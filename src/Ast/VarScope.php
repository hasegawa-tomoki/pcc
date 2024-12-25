<?php
namespace Pcc\Ast;

class VarScope
{
    public string $name;
    public int $depth;
    public Obj $var;
}