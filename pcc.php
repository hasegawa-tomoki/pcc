<?php

return main($argc, $argv);

function main(int $argc = null, array $argv = null): int
{
    if ($argc != 2) {
        printf("引数の個数が正しくありません\n");
        return 1;
    }

    printf(".intel_syntax noprefix\n");
    printf(".globl main\n");
    printf("main:\n");

    [$number, $string] = extractLeadingNumber($argv[1]);
    printf("  mov rax, %d\n", $number);

    while(strlen($string)){
        if (str_starts_with($string, '+')){
            $string = substr($string, 1);
            [$number, $string] = extractLeadingNumber($string);
            printf("  add rax, %d\n", $number);
            continue;
        }
        if (str_starts_with($string, '-')){
            $string = substr($string, 1);
            [$number, $string] = extractLeadingNumber($string);
            printf("  sub rax, %d\n", $number);
            continue;
        }
        fprintf(STDERR, "予期しない文字です: %s\n", $string);
        return 1;
    }

    printf("  ret\n");
    return 0;
}


function extractLeadingNumber($string): array
{
    if (preg_match('/^\d+/', $string, $matches)) {
        $number = $matches[0];
        $string = substr($string, strlen($number));
    } else {
        $number = null;
    }

    return [
        intval($number),
        $string,
    ];
}
