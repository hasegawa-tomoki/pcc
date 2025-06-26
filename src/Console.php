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
        printf(str_repeat(" ", strlen($indent) + $pos));
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
        printf(str_repeat(" ", strlen($indent) + $pos));
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
        printf(str_repeat(" ", strlen($indent) + $pos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
    }

    public static function out(string $format, ...$args): void
    {
        fprintf(self::$outputFile, $format.PHP_EOL, ...$args);
    }
}