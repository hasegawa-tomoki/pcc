<?php
namespace Pcc\Ast;

class Align
{
    public static function alignTo(int $n, int $align): int
    {
        return intval(($n + $align - 1) / $align) * $align;
    }
}