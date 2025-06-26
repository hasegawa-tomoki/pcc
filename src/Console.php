<?php
namespace Pcc;

use JetBrains\PhpStorm\NoReturn;
use Pcc\Tokenizer\Token;

class Console
{
    public static string $currentInput = '';
    public static string $currentFilename = '';
    /** @var resource */
    public static $outputFile;

    /**
     * Returns the number of columns needed to display a given
     * character in a fixed-width font.
     * Based on chibicc's char_width function.
     */
    private static function charWidth(int $c): int
    {
        // Characters that take 0 columns (combining characters, control chars, etc.)
        static $range1 = [
            [0x0000, 0x001F], [0x007f, 0x00a0], [0x0300, 0x036F], [0x0483, 0x0486],
            [0x0488, 0x0489], [0x0591, 0x05BD], [0x05BF, 0x05BF], [0x05C1, 0x05C2],
            [0x05C4, 0x05C5], [0x05C7, 0x05C7], [0x0600, 0x0603], [0x0610, 0x0615],
            [0x064B, 0x065E], [0x0670, 0x0670], [0x06D6, 0x06E4], [0x06E7, 0x06E8],
            [0x06EA, 0x06ED], [0x070F, 0x070F], [0x0711, 0x0711], [0x0730, 0x074A],
            [0x07A6, 0x07B0], [0x07EB, 0x07F3], [0x0901, 0x0902], [0x093C, 0x093C],
            [0x0941, 0x0948], [0x094D, 0x094D], [0x0951, 0x0954], [0x0962, 0x0963],
            [0x0981, 0x0981], [0x09BC, 0x09BC], [0x09C1, 0x09C4], [0x09CD, 0x09CD],
            [0x09E2, 0x09E3], [0x0A01, 0x0A02], [0x0A3C, 0x0A3C], [0x0A41, 0x0A42],
            [0x0A47, 0x0A48], [0x0A4B, 0x0A4D], [0x0A70, 0x0A71], [0x0A81, 0x0A82],
            [0x0ABC, 0x0ABC], [0x0AC1, 0x0AC5], [0x0AC7, 0x0AC8], [0x0ACD, 0x0ACD],
            [0x0AE2, 0x0AE3], [0x0B01, 0x0B01], [0x0B3C, 0x0B3C], [0x0B3F, 0x0B3F],
            [0x0B41, 0x0B43], [0x0B4D, 0x0B4D], [0x0B56, 0x0B56], [0x0B82, 0x0B82],
            [0x0BC0, 0x0BC0], [0x0BCD, 0x0BCD], [0x0C3E, 0x0C40], [0x0C46, 0x0C48],
            [0x0C4A, 0x0C4D], [0x0C55, 0x0C56], [0x0CBC, 0x0CBC], [0x0CBF, 0x0CBF],
            [0x0CC6, 0x0CC6], [0x0CCC, 0x0CCD], [0x0CE2, 0x0CE3], [0x0D41, 0x0D43],
            [0x0D4D, 0x0D4D], [0x0DCA, 0x0DCA], [0x0DD2, 0x0DD4], [0x0DD6, 0x0DD6],
            [0x0E31, 0x0E31], [0x0E34, 0x0E3A], [0x0E47, 0x0E4E], [0x0EB1, 0x0EB1],
            [0x0EB4, 0x0EB9], [0x0EBB, 0x0EBC], [0x0EC8, 0x0ECD], [0x0F18, 0x0F19],
            [0x0F35, 0x0F35], [0x0F37, 0x0F37], [0x0F39, 0x0F39], [0x0F71, 0x0F7E],
            [0x0F80, 0x0F84], [0x0F86, 0x0F87], [0x0F90, 0x0F97], [0x0F99, 0x0FBC],
            [0x0FC6, 0x0FC6], [0x102D, 0x1030], [0x1032, 0x1032], [0x1036, 0x1037],
            [0x1039, 0x1039], [0x1058, 0x1059], [0x1160, 0x11FF], [0x135F, 0x135F],
            [0x1712, 0x1714], [0x1732, 0x1734], [0x1752, 0x1753], [0x1772, 0x1773],
            [0x17B4, 0x17B5], [0x17B7, 0x17BD], [0x17C6, 0x17C6], [0x17C9, 0x17D3],
            [0x17DD, 0x17DD], [0x180B, 0x180D], [0x18A9, 0x18A9], [0x1920, 0x1922],
            [0x1927, 0x1928], [0x1932, 0x1932], [0x1939, 0x193B], [0x1A17, 0x1A18],
            [0x1B00, 0x1B03], [0x1B34, 0x1B34], [0x1B36, 0x1B3A], [0x1B3C, 0x1B3C],
            [0x1B42, 0x1B42], [0x1B6B, 0x1B73], [0x1DC0, 0x1DCA], [0x1DFE, 0x1DFF],
            [0x200B, 0x200F], [0x202A, 0x202E], [0x2060, 0x2063], [0x206A, 0x206F],
            [0x20D0, 0x20EF], [0x302A, 0x302F], [0x3099, 0x309A], [0xA806, 0xA806],
            [0xA80B, 0xA80B], [0xA825, 0xA826], [0xFB1E, 0xFB1E], [0xFE00, 0xFE0F],
            [0xFE20, 0xFE23], [0xFEFF, 0xFEFF], [0xFFF9, 0xFFFB]
        ];

        foreach ($range1 as [$start, $end]) {
            if ($c >= $start && $c <= $end) {
                return 0;
            }
        }

        // Characters that take 2 columns (wide characters like CJK)
        static $range2 = [
            [0x1100, 0x115F], [0x2329, 0x2329], [0x232A, 0x232A], [0x2E80, 0x303E],
            [0x3040, 0xA4CF], [0xAC00, 0xD7A3], [0xF900, 0xFAFF], [0xFE10, 0xFE19],
            [0xFE30, 0xFE6F], [0xFF00, 0xFF60], [0xFFE0, 0xFFE6], [0x1F000, 0x1F644],
            [0x20000, 0x2FFFD], [0x30000, 0x3FFFD]
        ];

        foreach ($range2 as [$start, $end]) {
            if ($c >= $start && $c <= $end) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * Returns the number of columns needed to display a given
     * string in a fixed-width font.
     */
    private static function displayWidth(string $str, int $len): int
    {
        $width = 0;
        $bytePos = 0;
        
        while ($bytePos < $len && $bytePos < strlen($str)) {
            // Get the next UTF-8 character
            $charBytes = 1;
            $byte = ord($str[$bytePos]);
            
            if ($byte >= 0xC0) {
                if ($byte >= 0xF0) {
                    $charBytes = 4;
                } elseif ($byte >= 0xE0) {
                    $charBytes = 3;
                } else {
                    $charBytes = 2;
                }
            }
            
            // Ensure we don't go beyond the string length
            if ($bytePos + $charBytes > strlen($str)) {
                break;
            }
            
            $char = substr($str, $bytePos, $charBytes);
            $codePoint = mb_ord($char, 'UTF-8');
            $width += self::charWidth($codePoint);
            $bytePos += $charBytes;
        }
        
        return $width;
    }

    #[NoReturn] public static function error(string $format, ...$args): never
    {
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }

    /**
     * Console::unreachable(__FILE__, __LINE__);
     *
     * @param string $file
     * @param int $line
     * @return void
     */
    #[NoReturn] public static function unreachable(string $file, int $line): never
    {
        self::error('internal error at %s:%d', $file, $line);
    }

    /**
     * Reports an error message in the following format.
     *
     * foo.c:10: x = y + 1;
     *               ^ <error message here>
     *
     * @param int $lineNo
     * @param int $pos
     * @param string $format
     * @param ...$args
     * @return void
     */
    public static function vErrorAt(int $lineNo, int $pos, string $format, ...$args): void
    {
        $lines = explode("\n", Console::$currentInput);
        $line = '';
        foreach ($lines as $l){
            if ($pos < strlen($l)){
                $line = $l;
                break;
            }
            $pos -= strlen($l) + 1;
        }

        $indent = sprintf("%s:%d: ", Console::$currentFilename, $lineNo);
        printf("%s%s".PHP_EOL, $indent, $line);
        $displayPos = self::displayWidth($line, $pos) + strlen($indent);
        printf(str_repeat(" ", $displayPos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
    }

    #[NoReturn] public static function errorAt(int $pos, string $format, ...$args): never
    {
        $lines = explode("\n", Console::$currentInput);
        $lineNo = 1;
        $idx = $pos;
        foreach ($lines as $line){
            if ($idx < strlen($line)){
                break;
            }
            $idx -= strlen($line) + 1;
            $lineNo++;
        }
        self::vErrorAt($lineNo, $pos, $format, ...$args);
        exit(1);
    }

    #[NoReturn] public static function errorTok(Token $tok, string $format, ...$args): never
    {
        // Use file-specific information from the token
        $filename = $tok->file->name;
        $input = $tok->file->contents;
        $lineNo = $tok->lineNo;
        
        // Calculate position in the specific file
        $lines = explode("\n", $input);
        $pos = $tok->pos;
        $line = '';
        foreach ($lines as $idx => $l) {
            if ($idx + 1 >= $lineNo) {
                $line = $l;
                break;
            }
            $pos -= strlen($l) + 1;
        }
        
        $indent = sprintf("%s:%d: ", $filename, $lineNo);
        printf("%s%s".PHP_EOL, $indent, $line);
        $displayPos = self::displayWidth($line, $pos) + strlen($indent);
        printf(str_repeat(" ", $displayPos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }

    public static function warnTok(Token $tok, string $format, ...$args): void
    {
        if ($tok->file === null) {
            return;
        }
        
        // Use file-specific information from the token
        $filename = $tok->file->name;
        $input = $tok->file->contents;
        $lineNo = $tok->lineNo;
        
        // Calculate position in the specific file
        $lines = explode("\n", $input);
        $pos = $tok->pos;
        $line = '';
        foreach ($lines as $idx => $l) {
            if ($idx + 1 >= $lineNo) {
                $line = $l;
                break;
            }
            $pos -= strlen($l) + 1;
        }
        
        $indent = sprintf("%s:%d: ", $filename, $lineNo);
        printf("%s%s".PHP_EOL, $indent, $line);
        $displayPos = self::displayWidth($line, $pos) + strlen($indent);
        printf(str_repeat(" ", $displayPos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
    }

    public static function out(string $format, ...$args): void
    {
        fprintf(self::$outputFile, $format.PHP_EOL, ...$args);
    }
}