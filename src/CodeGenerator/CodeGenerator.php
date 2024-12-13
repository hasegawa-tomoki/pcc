<?php

namespace Pcc\CodeGenerator;

use Pcc\Ast\Func;
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

    public function alignTo(int $n, int $align): int
    {
        return ($n + $align - 1) / $align * $align;
    }
    public function genAddr(Node $node): void
    {
        if ($node->kind == NodeKind::ND_VAR){
            printf("  lea %d(%%rbp), %%rax\n", $node->var->offset);
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
        switch ($node->kind){
            case NodeKind::ND_RETURN:
                $this->genExpr($node->lhs);
                printf("  jmp .L.return\n");
                return;
            case NodeKind::ND_EXPR_STMT:
                $this->genExpr($node->lhs);
                return;
        }

        Console::error('invalid statement');
    }

    public function assignLVarOffsets(Func $prog): Func
    {
        $offset = 0;
        foreach ($prog->locals as $var){
            $offset += 8;
            $var->offset = -1 * $offset;
        }
        $prog->stackSize = $this->alignTo($offset, 16);
        return $prog;
    }

    public function gen(Func $prog): void
    {
        $prog = $this->assignLVarOffsets($prog);

        printf("  .globl main\n");
        printf("main:\n");

        // Prologue
        printf("  push %%rbp\n");
        printf("  mov %%rsp, %%rbp\n");
        printf("  sub \$%d, %%rsp\n", $prog->stackSize);

        foreach ($prog->body as $node){
            $this->genStmt($node);
            assert($this->depth == 0);
        }

        printf(".L.return:\n");
        printf("  mov %%rbp, %%rsp\n");
        printf("  pop %%rbp\n");
        printf("  ret\n");
    }
}