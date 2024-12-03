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
    printf("  mov rax, %d\n", intval($argv[1]));
    printf("  ret\n");
    return 0;
}