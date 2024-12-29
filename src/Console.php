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

    #[NoReturn] public static function error(string $format, ...$args): void
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
    #[NoReturn] public static function unreachable(string $file, int $line): void
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
        foreach ($lines as $line){
            if ($pos < strlen($line)){
                break;
            }
            $pos -= strlen($line) + 1;
        }

        $indent = sprintf("%s:%d: ", Console::$currentFilename, $lineNo);
        printf("%s%s".PHP_EOL, $indent, $lines[$lineNo - 1]);
        printf(str_repeat(" ", strlen($indent) + $pos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
    }

    #[NoReturn] public static function errorAt(int $pos, string $format, ...$args): void
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

    #[NoReturn] public static function errorTok(Token $tok, string $format, ...$args): void
    {
        self::vErrorAt($tok->lineNo, $tok->pos, $format, ...$args);
        exit(1);
    }

    public static function warnTok(Token $tok, string $format, ...$args): void
    {
        self::vErrorAt($tok->lineNo, $tok->pos, $format, ...$args);
    }

    public static function out(string $format, ...$args): void
    {
        fprintf(self::$outputFile, $format.PHP_EOL, ...$args);
    }
}