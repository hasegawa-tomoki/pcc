<?php
namespace Pcc;

use Pcc\Tokenizer\Token;

class Console
{
    public static function errorAt(string $userInput, int $pos, string $format, ...$args): void
    {
        printf($userInput.PHP_EOL);
        printf(str_repeat(" ", $pos));
        printf("^ ");
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }

    public static function errorTok(string $userInput, Token $tok, string $format, ...$args): void
    {
        self::errorAt($userInput, $tok->pos, $format, ...$args);
    }
    public static function error(string $format, ...$args): void
    {
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }
}