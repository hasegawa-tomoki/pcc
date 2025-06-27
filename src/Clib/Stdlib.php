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
                long double strtold(const char *str, char **endptr);
                int sprintf(char *str, const char *format, ...);
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

    /**
     * @param string $str
     * @return array<float, int>
     */
    public static function strtold(string $str): array
    {
        $ffi = self::getFFI();
        
        // Create a null-terminated C string
        $cStr = $ffi->new("char[" . (strlen($str) + 1) . "]");
        $ffi::memcpy($cStr, $str, strlen($str));
        $cStr[strlen($str)] = "\0";
        
        // Create endptr
        $endptr = $ffi->new("char*");
        $endptrPtr = $ffi::addr($endptr);
        
        // Call strtold
        $result = $ffi->strtold($cStr, $endptrPtr);
        
        // Calculate consumed characters: find where endptr points in the original string
        $endString = $ffi::string($endptr);
        $consumed = strlen($str) - strlen($endString);
        
        return [(float)$result, $consumed];
    }

    /**
     * @param string $format
     * @param mixed ...$args
     * @return string
     */
    public static function sprintf(string $format, ...$args): string
    {
        $ffi = self::getFFI();
        
        // Create buffer for result
        $buffer = $ffi->new("char[8192]");
        
        // Create format string
        $cFormat = $ffi->new("char[" . (strlen($format) + 1) . "]");
        $ffi::memcpy($cFormat, $format, strlen($format));
        $cFormat[strlen($format)] = "\0";
        
        // Handle special case for %Lf format with long double
        if (strpos($format, '%Lf') !== false && count($args) > 0) {
            $longDoubleValue = (float)$args[0];
            
            // Convert long double to extended precision if possible
            // For now, we'll use PHP's internal sprintf as a fallback
            $result = sprintf(str_replace('%Lf', '%.15f', $format), $longDoubleValue);
            return $result;
        }
        
        // For other formats, use regular PHP sprintf
        return sprintf($format, ...$args);
    }
}