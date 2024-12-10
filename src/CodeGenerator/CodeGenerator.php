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

    public function genAddr(Node $node): void
    {
        if ($node->kind == NodeKind::ND_VAR){
            $offset = (ord($node->name) - ord('a') + 1) * 8;
            printf("  lea %d(%%rbp), %%rax\n", -1 * $offset);
            return;
        }

        Console::error('not an lvalue');
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
            case NodeKind::ND_VAR:
                $this->genAddr($node);
                printf("  mov (%%rax), %%rax\n");
                return;
            case NodeKind::ND_ASSIGN:
                $this->genAddr($node->lhs);
                $this->push();
                $this->genExpr($node->rhs);
                $this->pop('%rdi');
                printf("  mov %%rax, (%%rdi)\n");
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

    public function genStmt(Node $node): void
    {
        if ($node->kind == NodeKind::ND_EXPR_STMT) {
            $this->genExpr($node->lhs);
            return;
        }

        Console::error('invalid statement');
    }

    /**
     * @param Node[] $nodes
     * @return void
     */
    public function gen(array $nodes): void
    {
        printf("  .globl main\n");
        printf("main:\n");

        printf("  push %%rbp\n");
        printf("  mov %%rsp, %%rbp\n");
        printf("  sub \$208, %%rsp\n");

        foreach ($nodes as $node){
            $this->genStmt($node);
            assert($this->depth == 0);
        }

        printf("  mov %%rbp, %%rsp\n");
        printf("  pop %%rbp\n");
        printf("  ret\n");
    }
}