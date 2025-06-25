<?php
namespace Pcc\Ast;

class Align
{
    public static function alignTo(int $n, int $align): int
    {
        return intval(($n + $align - 1) / $align) * $align;
    }

    public static function alignDown(int $n, int $align): int
    {
        return self::alignTo($n - $align + 1, $align);
    }
}