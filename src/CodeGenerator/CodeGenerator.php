<?php

namespace Pcc\CodeGenerator;

use Pcc\Ast\Node;
use Pcc\Ast\NodeKind;
use Pcc\Console;

class CodeGenerator
{
    public int $depth = 0;
    public function push(): void
    {
        printf("  push %%rax\n");
        $this->depth++;
    }

    public function pop(string $arg): void
    {
        printf("  pop %s\n", $arg);
        $this->depth--;
    }

    public function genExpr(Node $node): void
    {
        switch ($node->kind) {
            case NodeKind::ND_NUM:
                printf("  mov \$%d, %%rax\n", $node->val);
                return;
            case NodeKind::ND_NEG:
                $this->genExpr($node->lhs);
                printf("  neg %%rax\n");
                return;
        }

        $this->genExpr($node->rhs);
        $this->push();
        $this->genExpr($node->lhs);
        $this->pop('%rdi');

        switch ($node->kind) {
            case NodeKind::ND_ADD:
                printf("  add %%rdi, %%rax\n");
                return;
            case NodeKind::ND_SUB:
                printf("  sub %%rdi, %%rax\n");
                return;
            case NodeKind::ND_MUL:
                printf("  imul %%rdi, %%rax\n");
                return;
            case NodeKind::ND_DIV:
                printf("  cqo\n");
                printf("  idiv %%rdi\n");
                return;
            case NodeKind::ND_EQ:
            case NodeKind::ND_NE:
            case NodeKind::ND_LT:
            case NodeKind::ND_LE:
                printf("  cmp %%rdi, %%rax\n");
                if ($node->kind == NodeKind::ND_EQ) {
                    printf("  sete %%al\n");
                } elseif ($node->kind == NodeKind::ND_NE) {
                    printf("  setne %%al\n");
                } elseif ($node->kind == NodeKind::ND_LT) {
                    printf("  setl %%al\n");
                } elseif ($node->kind == NodeKind::ND_LE) {
                    printf("  setle %%al\n");
                }
                printf("  movzb %%al, %%rax\n");
                return;
        }

        Console::error('invalid expression');
    }
}