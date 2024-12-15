<?php

namespace Pcc\CodeGenerator;

use Pcc\Ast\Obj;
use Pcc\Ast\Node;
use Pcc\Ast\NodeKind;
use Pcc\Ast\Type;
use Pcc\Ast\TypeKind;
use Pcc\Console;

class CodeGenerator
{
    public int $depth = 0;
    /** @var string[]  */
    public array $argreg = ['%rdi', '%rsi', '%rdx', '%rcx', '%r8', '%r9'];
    public Obj $currentFn;

    public function cnt(): int
    {
        static $i = 1;
        return $i++;
    }

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
        return intval(($n + $align - 1) / $align) * $align;
    }
    public function genAddr(Node $node): void
    {
        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind){
            case NodeKind::ND_VAR:
                printf("  lea %d(%%rbp), %%rax\n", $node->var->offset);
                return;
            case NodeKind::ND_DEREF:
                $this->genExpr($node->lhs);
                return;
        }

        Console::errorTok($node->tok, 'not an lvalue');
    }

    // Load a value from where %rax is pointing to.
    public function load(Type $ty): void
    {
        if ($ty->kind === TypeKind::TY_ARRAY){
            return;
        }
        printf("  mov (%%rax), %%rax\n");
    }

    public function store(): void
    {
        $this->pop('%rdi');
        printf("  mov %%rax, (%%rdi)\n");
    }

    public function genExpr(Node $node): void
    {
        /** @noinspection PhpUncoveredEnumCasesInspection */
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
                $this->load($node->ty);
                return;
            case NodeKind::ND_DEREF:
                $this->genExpr($node->lhs);
                $this->load($node->ty);
                return;
            case NodeKind::ND_ADDR:
                $this->genAddr($node->lhs);
                return;
            case NodeKind::ND_ASSIGN:
                $this->genAddr($node->lhs);
                $this->push();
                $this->genExpr($node->rhs);
                $this->store();
                return;
            case NodeKind::ND_FUNCALL:
                foreach ($node->args as $arg){
                    $this->genExpr($arg);
                    $this->push();
                }
                for ($i = count($node->args) - 1; $i >= 0; $i--){
                    $this->pop($this->argreg[$i]);
                }

                printf("  mov $0, %%rax\n");
                printf("  call %s\n", $node->funcname);
                return;
        }

        $this->genExpr($node->rhs);
        $this->push();
        $this->genExpr($node->lhs);
        $this->pop('%rdi');

        /** @noinspection PhpUncoveredEnumCasesInspection */
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

        Console::errorTok($node->tok, 'invalid expression');
    }

    public function genStmt(Node $node): void
    {
        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind){
            case NodeKind::ND_IF: {
                $c = $this->cnt();
                $this->genExpr($node->cond);
                printf("  cmp \$0, %%rax\n");
                printf("  je  .L.else.%d\n", $c);
                $this->genStmt($node->then);
                printf("  jmp .L.end.%d\n", $c);
                printf(".L.else.%d:\n", $c);
                if ($node->els) {
                    $this->genStmt($node->els);
                }
                printf(".L.end.%d:\n", $c);
                return;
            }
            case NodeKind::ND_FOR: {
                $c = $this->cnt();
                if ($node->init){
                    $this->genStmt($node->init);
                }
                printf(".L.begin.%d:\n", $c);
                if ($node->cond) {
                    $this->genExpr($node->cond);
                    printf("  cmp \$0, %%rax\n");
                    printf("  je  .L.end.%d\n", $c);
                }
                $this->genStmt($node->then);
                if ($node->inc) {
                    $this->genExpr($node->inc);
                }
                printf("  jmp .L.begin.%d\n", $c);
                printf(".L.end.%d:\n", $c);
                return;
            }
            case NodeKind::ND_BLOCK:
                foreach ($node->body as $n){
                    $this->genStmt($n);
                }
                return;
            case NodeKind::ND_RETURN:
                $this->genExpr($node->lhs);
                printf("  jmp .L.return.%s\n", $this->currentFn->name);
                return;
            case NodeKind::ND_EXPR_STMT:
                $this->genExpr($node->lhs);
                return;
        }

        Console::errorTok($node->tok, 'invalid statement');
    }

    /**
     * @param Obj[] $funcs
     * @return Obj[]
     */
    public function assignLVarOffsets(array $funcs): array
    {
        foreach  ($funcs as $fn){
            if (! $fn->isFunction){
                continue;
            }

            $offset = 0;
            foreach (array_reverse($fn->locals) as $var){
                $offset += $var->ty->size;
                $var->offset = -1 * $offset;
            }
            $fn->stackSize = $this->alignTo($offset, 16);
        }

        return $funcs;
    }

    /**
     * @param Obj[] $funcs
     * @return void
     */
    public function gen(array $funcs): void
    {
        $funcs = $this->assignLVarOffsets($funcs);

        foreach ($funcs as $fn){
            if (! $fn->isFunction){
                continue;
            }

            printf("  .globl %s\n", $fn->name);
            printf("  .text\n");
            printf("%s:\n", $fn->name);
            $this->currentFn = $fn;

            // Prologue
            printf("  push %%rbp\n");
            printf("  mov %%rsp, %%rbp\n");
            printf("  sub \$%d, %%rsp\n", $fn->stackSize);

            // Save passed-by-register arguments to the stack
            $idx = 0;
            foreach ($fn->params as $param){
                if ($idx < count($this->argreg)){
                    printf("  mov %s, %d(%%rbp)\n", $this->argreg[$idx], $param->offset);
                }
                $idx++;
            }

            // Emit code
            foreach ($fn->body as $node){
                $this->genStmt($node);
            }
            assert($this->depth == 0);

            // Epilogue
            printf(".L.return.%s:\n", $fn->name);
            printf("  mov %%rbp, %%rsp\n");
            printf("  pop %%rbp\n");
            printf("  ret\n");
        }
    }
}