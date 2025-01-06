<?php
namespace Pcc\Ast\Type;

use GMP;

class PccGMP
{
    public static function logicalAnd(\GMP $a, \GMP $b): GMP
    {
        return gmp_init((gmp_cmp($a, 0) !== 0 && gmp_cmp($b, 0) !== 0) ? 1 : 0);
    }

    public static function logicalOr(\GMP $a, \GMP $b): GMP
    {
        return gmp_init((gmp_cmp($a, 0) !== 0 || gmp_cmp($b, 0) !== 0) ? 1 : 0);
    }

    public static function isTrue(\GMP $a): bool
    {
        return gmp_cmp($a, 0) !== 0;
    }

    public static function shiftL(\GMP $a, int $shift, int $width = 64): GMP
    {
        $bitMask = gmp_sub(gmp_pow(2, $width + 1), 1);
        return gmp_and(gmp_mul($a, gmp_pow(2, $shift)), $bitMask);
    }

    public static function shiftR(\GMP $a, int $shift): GMP
    {
        return gmp_div($a, gmp_pow(2, $shift));
    }

    public static function overFlow(GMP $a, int $bit = 64): GMP
    {
        $modValue = gmp_mod($a, gmp_pow(2, $bit));
        if (gmp_cmp($modValue, gmp_pow(2, $bit - 1)) >= 0){
            $modValue = gmp_sub($modValue, gmp_pow(2, $bit));
        }
        return $modValue;
    }
    public static function toSignedInt(GMP $a, int $bit = 64): int
    {
        $modValue = self::overFlow($a, $bit);
        return gmp_intval($modValue);
    }
}