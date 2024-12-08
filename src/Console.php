<?php
namespace Pcc;

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

    public static function error(string $format, ...$args): void
    {
        printf($format.PHP_EOL, ...$args);
        exit(1);
    }
}