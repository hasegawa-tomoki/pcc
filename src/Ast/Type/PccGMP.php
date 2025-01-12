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

    public static function shiftRLogical(\GMP $a, int $shift): GMP
    {
        assert(gmp_sign($a) >= 0);
        return gmp_div($a, gmp_pow(2, $shift));
    }

    public static function toTwoComplementString(\GMP $a, $bitWidth = 64): string
    {
        if ($a >= 0) {
            return str_pad(decbin(gmp_intval($a)), $bitWidth, "0", STR_PAD_LEFT);
        } else {
            $maxValue = gmp_pow(2, $bitWidth);
            $twoComplement = gmp_add($maxValue, $a);
            return gmp_strval($twoComplement, 2);
        }
    }

    public static function fromTwoComplementString(string $binaryString): \GMP
    {
        $bitWidth = strlen($binaryString);
        $isNegative = str_starts_with($binaryString, '1');
        $value = gmp_init($binaryString, 2);

        if ($isNegative){
            $maxValue = gmp_pow(2, $bitWidth);
            return gmp_sub($value, $maxValue);
        } else {
            return $value;
        }
    }
    
    public static function shiftRArithmetic(\GMP $a, int $shift, $bitWidth = 64): GMP
    {
        $isNegative = gmp_sign($a) < 0;

        if ($isNegative) {
            $twosComplement = self::toTwoComplementString($a, $bitWidth);
            $sign = substr($twosComplement, 0, 1);
            $value = substr($twosComplement, 1);
            $shifted = gmp_div(gmp_init($value, 2), gmp_pow(2, $shift));
            $resultStr = $sign.str_pad(gmp_strval($shifted, 2), $bitWidth - 1, '0', STR_PAD_LEFT);
            $result = self::fromTwoComplementString($resultStr);
        } else {
            $result = gmp_div($a, gmp_pow(2, $shift));
        }

        return $result;
    }

    public static function toSignedInt(GMP $a, int $bit = 64): GMP
    {
        $modValue = gmp_mod($a, gmp_pow(2, $bit));
        if (gmp_cmp($modValue, gmp_pow(2, $bit - 1)) >= 0){
            $modValue = gmp_sub($modValue, gmp_pow(2, $bit));
        }
        return $modValue;
    }

    public static function toUnsignedInt(GMP $a, int $bit = 64): GMP
    {
        $mask = gmp_sub(gmp_pow(2, $bit), 1);
        return gmp_and($a, $mask);
    }

    public static function toInt8t(GMP $a): GMP
    {
        return self::toSignedInt($a, 8);
    }

    public static function toInt16t(GMP $a): GMP
    {
        return self::toSignedInt($a, 16);
    }

    public static function toInt32t(GMP $a): GMP
    {
        return self::toSignedInt($a, 32);
    }

    public static function toInt64t(GMP $a): GMP
    {
        return self::toSignedInt($a, 64);
    }

    public static function toUint8t(GMP $a): GMP
    {
        return self::toUnsignedInt($a, 8);
    }

    public static function toUint16t(GMP $a): GMP
    {
        return self::toUnsignedInt($a, 16);
    }

    public static function toUint32t(GMP $a): GMP
    {
        return self::toUnsignedInt($a, 32);
    }

    public static function toUint64t(GMP $a): GMP
    {
        return self::toUnsignedInt($a, 64);
    }

    public static function toCInt(GMP $a): GMP
    {
        return self::toSignedInt($a, 32);
    }

    public static function toPHPInt(GMP $a, int $bit = 64): int
    {
        $modValue = self::toSignedInt($a, $bit);
        return gmp_intval($modValue);
    }
}