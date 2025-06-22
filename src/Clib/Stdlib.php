<?php

namespace Pcc\Clib;

use FFI;

class Stdlib
{
    private static ?FFI $ffi = null;

    private static function getFFI(): FFI
    {
        if (self::$ffi === null) {
            self::$ffi = FFI::cdef("
                unsigned long strtoul(const char *str, char **endptr, int base);
                double strtod(const char *str, char **endptr);
            ", "libc.so.6");
        }
        return self::$ffi;
    }

    /**
     * @param string $str
     * @param int $base
     * @return array<\GMP, int>
     */
    public static function strtoul(string $str, int $base = 10): array
    {
        $ffi = self::getFFI();
        
        // Create a null-terminated C string
        $cStr = $ffi->new("char[" . (strlen($str) + 1) . "]");
        $ffi::memcpy($cStr, $str, strlen($str));
        $cStr[strlen($str)] = "\0";
        
        // Create endptr
        $endptr = $ffi->new("char*");
        $endptrPtr = $ffi::addr($endptr);
        
        // Call strtoul
        $result = $ffi->strtoul($cStr, $endptrPtr, $base);
        
        // Calculate consumed characters: find where endptr points in the original string
        $endString = $ffi::string($endptr);
        $consumed = strlen($str) - strlen($endString);
        
        return [gmp_init($result), $consumed];
    }

    /**
     * @param string $str
     * @return array<float, int>
     */
    public static function strtod(string $str): array
    {
        $ffi = self::getFFI();
        
        // Create a null-terminated C string
        $cStr = $ffi->new("char[" . (strlen($str) + 1) . "]");
        $ffi::memcpy($cStr, $str, strlen($str));
        $cStr[strlen($str)] = "\0";
        
        // Create endptr
        $endptr = $ffi->new("char*");
        $endptrPtr = $ffi::addr($endptr);
        
        // Call strtod
        $result = $ffi->strtod($cStr, $endptrPtr);
        
        // Calculate consumed characters: find where endptr points in the original string
        $endString = $ffi::string($endptr);
        $consumed = strlen($str) - strlen($endString);
        
        return [$result, $consumed];
    }
}