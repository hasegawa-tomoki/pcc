<?php
namespace Pcc;

use Pcc\Ast\Node;
use Pcc\Ast\NodeKind;
use Pcc\Tokenizer\Tokenizer;

class Pcc
{
    public static function gen(Node $node): void
    {
        if ($node->kind == NodeKind::ND_NUM) {
            printf("  push %d\n", $node->val);
            return;
        }

        self::gen($node->lhs);
        self::gen($node->rhs);

        printf("  pop rdi\n");
        printf("  pop rax\n");

        switch ($node->kind){
            case NodeKind::ND_ADD:
                printf("  add rax, rdi\n");
                break;
            case NodeKind::ND_SUB:
                printf("  sub rax, rdi\n");
                break;
            case NodeKind::ND_MUL:
                printf("  imul rax, rdi\n");
                break;
            case NodeKind::ND_DIV:
                printf("  cqo\n");
                printf("  idiv rdi\n");
                break;
        }

        printf("  push rax\n");
    }

    public static function main(int $argc = null, array $argv = null): int
    {
        if ($argc != 2) {
            printf("引数の個数が正しくありません\n");
            return 1;
        }

        $tokenizer = new Tokenizer($argv[1]);
        $tokenizer->tokenize();
        $parser = new Ast\Parser($tokenizer);
        $node = $parser->expr();

        printf(".intel_syntax noprefix\n");
        printf(".globl main\n");
        printf("main:\n");

        self::gen($node);

        printf("  pop rax\n");
        printf("  ret\n");
        return 0;
    }
}