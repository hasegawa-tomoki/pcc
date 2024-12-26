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
    /** @var string[] */
    public array $argreg8 = ['%dil', '%sil', '%dl', '%cl', '%r8b', '%r9b'];
    /** @var string[]  */
    public array $argreg64 = ['%rdi', '%rsi', '%rdx', '%rcx', '%r8', '%r9'];
    public Obj $currentFn;

    public function cnt(): int
    {
        static $i = 1;
        return $i++;
    }

    public function push(): void
    {
        Console::out("  push %%rax");
        $this->depth++;
    }

    public function pop(string $arg): void
    {
        Console::out("  pop %s", $arg);
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
                if ($node->var->isLocal){
                    // Local variable
                    Console::out("  lea %d(%%rbp), %%rax", $node->var->offset);
                    return;
                } else {
                    // Global variable
                    Console::out("  lea %s(%%rip), %%rax", $node->var->name);
                    return;
                }
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

        if ($ty->size == 1){
            Console::out("  movsbq (%%rax), %%rax");
        } else {
            Console::out("  mov (%%rax), %%rax");
        }
    }

    public function store(Type $ty): void
    {
        $this->pop('%rdi');

        if ($ty->size == 1){
            Console::out("  mov %%al, (%%rdi)");
        } else {
            Console::out("  mov %%rax, (%%rdi)");
        }
    }

    public function genExpr(Node $node): void
    {
        Console::out("  .loc 1 %d", $node->tok->lineNo);

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind) {
            case NodeKind::ND_NUM:
                Console::out("  mov \$%d, %%rax", $node->val);
                return;
            case NodeKind::ND_NEG:
                $this->genExpr($node->lhs);
                Console::out("  neg %%rax");
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
                $this->store($node->ty);
                return;
            case NodeKind::ND_STMT_EXPR:
                foreach ($node->body as $node){
                    $this->genStmt($node);
                }
                return;
            case NodeKind::ND_FUNCALL:
                foreach ($node->args as $arg){
                    $this->genExpr($arg);
                    $this->push();
                }
                for ($i = count($node->args) - 1; $i >= 0; $i--){
                    $this->pop($this->argreg64[$i]);
                }

                Console::out("  mov $0, %%rax");
                Console::out("  call %s", $node->funcname);
                return;
        }

        $this->genExpr($node->rhs);
        $this->push();
        $this->genExpr($node->lhs);
        $this->pop('%rdi');

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind) {
            case NodeKind::ND_ADD:
                Console::out("  add %%rdi, %%rax");
                return;
            case NodeKind::ND_SUB:
                Console::out("  sub %%rdi, %%rax");
                return;
            case NodeKind::ND_MUL:
                Console::out("  imul %%rdi, %%rax");
                return;
            case NodeKind::ND_DIV:
                Console::out("  cqo");
                Console::out("  idiv %%rdi");
                return;
            case NodeKind::ND_EQ:
            case NodeKind::ND_NE:
            case NodeKind::ND_LT:
            case NodeKind::ND_LE:
                Console::out("  cmp %%rdi, %%rax");
                if ($node->kind == NodeKind::ND_EQ) {
                    Console::out("  sete %%al");
                } elseif ($node->kind == NodeKind::ND_NE) {
                    Console::out("  setne %%al");
                } elseif ($node->kind == NodeKind::ND_LT) {
                    Console::out("  setl %%al");
                } elseif ($node->kind == NodeKind::ND_LE) {
                    Console::out("  setle %%al");
                }
                Console::out("  movzb %%al, %%rax");
                return;
        }

        Console::errorTok($node->tok, 'invalid expression');
    }

    public function genStmt(Node $node): void
    {
        Console::out("  .loc 1 %d", $node->tok->lineNo);

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind){
            case NodeKind::ND_IF: {
                $c = $this->cnt();
                $this->genExpr($node->cond);
                Console::out("  cmp \$0, %%rax");
                Console::out("  je  .L.else.%d", $c);
                $this->genStmt($node->then);
                Console::out("  jmp .L.end.%d", $c);
                Console::out(".L.else.%d:", $c);
                if ($node->els) {
                    $this->genStmt($node->els);
                }
                Console::out(".L.end.%d:", $c);
                return;
            }
            case NodeKind::ND_FOR: {
                $c = $this->cnt();
                if ($node->init){
                    $this->genStmt($node->init);
                }
                Console::out(".L.begin.%d:", $c);
                if ($node->cond) {
                    $this->genExpr($node->cond);
                    Console::out("  cmp \$0, %%rax");
                    Console::out("  je  .L.end.%d", $c);
                }
                $this->genStmt($node->then);
                if ($node->inc) {
                    $this->genExpr($node->inc);
                }
                Console::out("  jmp .L.begin.%d", $c);
                Console::out(".L.end.%d:", $c);
                return;
            }
            case NodeKind::ND_BLOCK:
                foreach ($node->body as $n){
                    $this->genStmt($n);
                }
                return;
            case NodeKind::ND_RETURN:
                $this->genExpr($node->lhs);
                Console::out("  jmp .L.return.%s", $this->currentFn->name);
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
     * @param Obj[] $prog
     * @return void
     */
    public function emitData(array $prog): void
    {
        foreach (array_reverse($prog) as $var){
            if ($var->isFunction){
                continue;
            }

            Console::out("  .data");
            Console::out("  .globl %s", $var->name);
            Console::out("%s:", $var->name);

            if (! is_null($var->initData)){
                for ($i = 0; $i < strlen($var->initData); $i++){
                    Console::out("  .byte %d", ord($var->initData[$i]));
                }
                Console::out("  .byte 0");
            } else {
                Console::out("  .zero %d", $var->ty->size);
            }
        }
    }

    /**
     * @param Obj[] $prog
     * @return void
     */
    public function emitText(array $prog): void
    {
        foreach ($prog as $fn){
            if (! $fn->isFunction){
                continue;
            }

            Console::out("  .globl %s", $fn->name);
            Console::out("  .text");
            Console::out("%s:", $fn->name);
            $this->currentFn = $fn;

            // Prologue
            Console::out("  push %%rbp");
            Console::out("  mov %%rsp, %%rbp");
            Console::out("  sub \$%d, %%rsp", $fn->stackSize);

            // Save passed-by-register arguments to the stack
            $idx = 0;
            foreach ($fn->params as $param){
                if ($idx < count($this->argreg64)){
                    if ($param->ty->size === 1){
                        Console::out("  mov %s, %d(%%rbp)", $this->argreg8[$idx], $param->offset);
                    } else {
                        Console::out("  mov %s, %d(%%rbp)", $this->argreg64[$idx], $param->offset);
                    }
                }
                $idx++;
            }

            // Emit code
            foreach ($fn->body as $node){
                $this->genStmt($node);
            }
            assert($this->depth == 0);

            // Epilogue
            Console::out(".L.return.%s:", $fn->name);
            Console::out("  mov %%rbp, %%rsp");
            Console::out("  pop %%rbp");
            Console::out("  ret");
        }
    }

    /**
     * @param Obj[] $funcs
     * @return void
     */
    public function gen(array $funcs): void
    {
        $funcs = $this->assignLVarOffsets($funcs);
        $this->emitData($funcs);
        $this->emitText($funcs);
    }
}