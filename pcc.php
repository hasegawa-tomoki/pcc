<?php
require __DIR__ . '/vendor/autoload.php';

return main($argc, $argv);

function main(int $argc = null, array $argv = null): int
{
    if ($argc != 2) {
        printf("引数の個数が正しくありません\n");
        return 1;
    }

    $tokenizer = new Pcc\Tokenizer\Tokenizer($argv[1]);
    $tokenizer->tokenize();

    printf(".intel_syntax noprefix\n");
    printf(".globl main\n");
    printf("main:\n");

    printf("  mov rax, %d\n", $tokenizer->expectNumber());

    while(! $tokenizer->atEof()){
        if ($tokenizer->consume('+')) {
            printf("  add rax, %d\n", $tokenizer->expectNumber());
            continue;
        }

        $tokenizer->expect('-');
        printf("  sub rax, %d\n", $tokenizer->expectNumber());
    }

    printf("  ret\n");
    return 0;
}
