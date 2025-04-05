<?php

namespace Pcc\Clib;

class Stdlib
{
    /**
     * @param string $str
     * @param int $base
     * @return array<\GMP, int>
     */
    public static function strtoul(string $str, int $base = 10): array
    {
        $trimmed = ltrim($str);
        $length = strlen($trimmed);
        $validChars = "0123456789abcdefghijklmnopqrstuvwxyz";

        $validSet = substr($validChars, 0, $base);

        $number = '';
        $i = 0;

        while ($i < $length && str_contains($validSet, strtolower($trimmed[$i]))){
            $number .= $trimmed[$i];
            $i++;
        }

        $end = $i;

        if ($number === '') {
            return [gmp_init(0), $end];
        }

        return [gmp_init($number, $base), $end];
    }

    /**
     * @param string $str
     * @return array<float, int>
     */
    public static function strtod(string $str): array
    {
        $trimmed = ltrim($str);
        $length = strlen($trimmed);
        $i = 0;
        $number = '';
        $isHex = false;

        if ($i < $length && ($trimmed[$i] === '+' || $trimmed[$i] === '-')) {
            $number .= $trimmed[$i];
            $i++;
        }

        if ($i + 1 < $length && $trimmed[$i] === '0' && ($trimmed[$i + 1] === 'x' || $trimmed[$i + 1] === 'X')) {
            $isHex = true;
            $number .= $trimmed[$i] . $trimmed[$i + 1];
            $i += 2;
        }

        $hasDot = false;
        $hasExponent = false;

        while ($i < $length) {
            $char = $trimmed[$i];
            if ($isHex && (ctype_xdigit($char))) {
                $number .= $char;
            } elseif (!$isHex && ctype_digit($char)) {
                $number .= $char;
            } elseif ($char === '.' && !$hasDot && !$hasExponent) {
                $number .= $char;
                $hasDot = true;
            } elseif (!$isHex && ($char === 'e' || $char === 'E') && !$hasExponent) {
                $number .= $char;
                $hasExponent = true;
                if ($i + 1 < $length && ($trimmed[$i + 1] === '+' || $trimmed[$i + 1] === '-')) {
                    $number .= $trimmed[$i + 1];
                    $i++;
                }
            } elseif ($isHex && ($char === 'p' || $char === 'P') && !$hasExponent) {
                $number .= $char;
                $hasExponent = true;
                if ($i + 1 < $length && ($trimmed[$i + 1] === '+' || $trimmed[$i + 1] === '-')) {
                    $number .= $trimmed[$i + 1];
                    $i++;
                }
            } elseif (in_array($char, ['d', 'D', 'l', 'L'])){
                $i++;
                break;
            } else {
                break;
            }
            $i++;
        }

        $end = $i;

        if ($number === '' || $number === '+' || $number === '-' || strtolower($number) === 'e' || strtolower($number) === 'p') {
            return [0.0, 0];
        }

        if ($isHex) {
            $floatVal = self::hexToFloat($number);
        } else {
            $floatVal = (float)$number;
        }

        return [$floatVal, $end];
    }

    private static function hexToFloat(string $hexStr): float
    {
        if (!preg_match('/^([+-]?0[xX])([0-9a-fA-F]*)(?:\.([0-9a-fA-F]*))?[pP]([+-]?[0-9]+)/', $hexStr, $matches)) {
            return 0.0;
        }

        $intPart = $matches[2] ?: '0';
        $fracPart = $matches[3] ?? '';
        $exponent = (int)$matches[4];

        $intVal = hexdec($intPart);

        $fracVal = 0.0;
        for ($i = 0; $i < strlen($fracPart); $i++) {
            $digit = hexdec($fracPart[$i]);
            $fracVal += $digit / pow(16, $i + 1);
        }

        $result = ($intVal + $fracVal) * pow(2, $exponent);

        return ($hexStr[0] === '-') ? -$result : $result;
    }
}