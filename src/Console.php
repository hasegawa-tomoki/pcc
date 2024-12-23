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
     * Reports an error message in the following format and exit.
     *
     * foo.c:10: x = y + 1;
     *               ^ <error message here>
     *
     * @param int $pos
     * @param string $format
     * @param ...$args
     * @return void
     */
    #[NoReturn] public static function errorAt(int $pos, string $format, ...$args): void
    {
        $lines = explode("\n", Console::$currentInput);
        $lineNo = 1;
        foreach ($lines as $line){
            if ($pos < strlen($line)){
                break;
            }
            $pos -= strlen($line) + 1;
            $lineNo++;
        }

        $indent = sprintf("%s:%d: ", Console::$currentFilename, $lineNo);
        printf("%s %s".PHP_EOL, $indent, $lines[$lineNo - 1]);
        printf(str_repeat(" ", strlen($indent) + $pos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }

    #[NoReturn] public static function errorTok(Token $tok, string $format, ...$args): void
    {
        self::errorAt($tok->pos, $format, ...$args);
    }
    #[NoReturn] public static function error(string $format, ...$args): void
    {
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }

    public static function out(string $format, ...$args): void
    {
        fprintf(self::$outputFile, $format.PHP_EOL, ...$args);
    }
}