<?php
namespace Pcc;

use Pcc\Tokenizer\Token;

class Console
{
    public static string $currentInput = '';
    public static string $currentFilename = '';

    public static function errorAt(int $pos, string $format, ...$args): void
    {
        printf(Console::$currentInput.PHP_EOL);
        printf(str_repeat(" ", $pos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }

    public static function errorTok(Token $tok, string $format, ...$args): void
    {
        self::errorAt($tok->pos, $format, ...$args);
    }
    public static function error(string $format, ...$args): void
    {
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }
}