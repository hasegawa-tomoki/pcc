<?php

namespace Pcc\CodeGenerator;

use Pcc\Ast\Align;
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
    /** @var string[] */
    public array $argreg16 = ['%di', '%si', '%dx', '%cx', '%r8w', '%r9w'];
    /** @var string[] */
    public array $argreg32 = ['%edi', '%esi', '%edx', '%ecx', '%r8d', '%r9d'];
    /** @var string[]  */
    public array $argreg64 = ['%rdi', '%rsi', '%rdx', '%rcx', '%r8', '%r9'];

    public string $i32i8 = "movsbl %al, %eax";
    public string $i32i16 = "movswl %ax, %eax";
    public string $i32i64 = "movsxd %eax, %rax";
    public array $castTable = [];

    public Obj $currentFn;

    public function __construct()
    {
        $this->castTable = [
            [null,          null,           null, $this->i32i64,    ], // I8
            [$this->i32i8,  null,           null, $this->i32i64,    ], // I16
            [$this->i32i8,  $this->i32i16,  null, $this->i32i64,    ], // I32
            [$this->i32i8,  $this->i32i16,  null, null,             ], // I64
        ];
    }

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
            case NodeKind::ND_COMMA:
                $this->genExpr($node->lhs);
                $this->genAddr($node->rhs);
                return;
            case NodeKind::ND_MEMBER:
                $this->genAddr($node->lhs);
                Console::out("  add $%d, %%rax", $node->member->offset);
                return;
        }

        Console::errorTok($node->tok, 'not an lvalue');
    }

    // Load a value from where %rax is pointing to.
    public function load(Type $ty): void
    {
        if ($ty->kind === TypeKind::TY_ARRAY || $ty->kind === TypeKind::TY_STRUCT || $ty->kind === TypeKind::TY_UNION){
            return;
        }

        if ($ty->size === 1) {
            Console::out("  movsbl (%%rax), %%eax");
        } elseif ($ty->size === 2) {
            Console::out("  movswl (%%rax), %%eax");
        } elseif ($ty->size === 4) {
            Console::out("  movsxd (%%rax), %%rax");
        } else {
            Console::out("  mov (%%rax), %%rax");
        }
    }

    public function store(Type $ty): void
    {
        $this->pop('%rdi');

        if ($ty->kind === TypeKind::TY_STRUCT || $ty->kind === TypeKind::TY_UNION){
            for ($i = 0; $i < $ty->size; $i++){
                Console::out("  mov %d(%%rax), %%r8b", $i);
                Console::out("  mov %%r8b, %d(%%rdi)", $i);
            }
            return;
        }

        if ($ty->size === 1){
            Console::out("  mov %%al, (%%rdi)");
        } elseif ($ty->size === 2){
            Console::out("  mov %%ax, (%%rdi)");
        } elseif ($ty->size === 4){
            Console::out("  mov %%eax, (%%rdi)");
        } else {
            Console::out("  mov %%rax, (%%rdi)");
        }
    }

    public function cmpZero(Type $ty): void
    {
        if ($ty->isInteger() and $ty->size <= 4){
            Console::out("  cmp \$0, %%eax");
        } else {
            Console::out("  cmp \$0, %%rax");
        }
    }

    public function getTypeId(Type $ty): int
    {
        return match ($ty->kind) {
            TypeKind::TY_CHAR => TypeId::I8->value,
            TypeKind::TY_SHORT => TypeId::I16->value,
            TypeKind::TY_INT => TypeId::I32->value,
            default => TypeId::I64->value,
        };
    }

    public function cast(Type $from, Type $to): void
    {
        if ($to->kind === TypeKind::TY_VOID){
            return;
        }

        if ($to->kind === TypeKind::TY_BOOL){
            $this->cmpZero($from);
            Console::out("  setne %%al");
            Console::out("  movzx %%al, %%eax");
            return;
        }

        $t1 = $this->getTypeId($from);
        $t2 = $this->getTypeId($to);
        if (isset($this->castTable[$t1][$t2]) and $this->castTable[$t1][$t2]){
            Console::out("  %s", $this->castTable[$t1][$t2]);
        }
    }

    public function genExpr(Node $node): void
    {
        Console::out("  .loc 1 %d", $node->tok->lineNo);

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind) {
            case NodeKind::ND_NUM:
                Console::out("  mov \$%ld, %%rax", $node->val);
                return;
            case NodeKind::ND_VAR:
            case NodeKind::ND_MEMBER:
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
            case NodeKind::ND_COMMA:
                $this->genExpr($node->lhs);
                $this->genExpr($node->rhs);
                return;
            case NodeKind::ND_CAST:
                $this->genExpr($node->lhs);
                $this->cast($node->lhs->ty, $node->ty);
                return;
            case NodeKind::ND_NOT:
                $this->genExpr($node->lhs);
                Console::out("  cmp \$0, %%rax");
                Console::out("  sete %%al");
                Console::out("  movzx %%al, %%rax");
                return;
            case NodeKind::ND_BITNOT:
                $this->genExpr($node->lhs);
                Console::out("  not %%rax");
                return;
            case NodeKind::ND_LOGAND:
                $c = $this->cnt();
                $this->genExpr($node->lhs);
                Console::out("  cmp \$0, %%rax");
                Console::out("  je .L.false.%d", $c);
                $this->genExpr($node->rhs);
                Console::out("  cmp \$0, %%rax");
                Console::out("  je .L.false.%d", $c);
                Console::out("  mov \$1, %%rax");
                Console::out("  jmp .L.end.%d", $c);
                Console::out(".L.false.%d:", $c);
                Console::out("  mov \$0, %%rax");
                Console::out(".L.end.%d:", $c);
                return;
            case NodeKind::ND_LOGOR:
                $c = $this->cnt();
                $this->genExpr($node->lhs);
                Console::out("  cmp \$0, %%rax");
                Console::out("  jne .L.true.%d", $c);
                $this->genExpr($node->rhs);
                Console::out("  cmp \$0, %%rax");
                Console::out("  jne .L.true.%d", $c);
                Console::out("  mov \$0, %%rax");
                Console::out("  jmp .L.end.%d", $c);
                Console::out(".L.true.%d:", $c);
                Console::out("  mov \$1, %%rax");
                Console::out(".L.end.%d:", $c);
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

        if ($node->lhs->ty->kind === TypeKind::TY_LONG or $node->lhs->ty->base){
            $ax = '%rax';
            $di = '%rdi';
        } else {
            $ax = '%eax';
            $di = '%edi';
        }

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind) {
            case NodeKind::ND_ADD:
                Console::out("  add %s, %s", $di, $ax);
                return;
            case NodeKind::ND_SUB:
                Console::out("  sub %s, %s", $di, $ax);
                return;
            case NodeKind::ND_MUL:
                Console::out("  imul %s, %s", $di, $ax);
                return;
            case NodeKind::ND_DIV:
            case NodeKind::ND_MOD:
                if ($node->lhs->ty->size === 8){
                    Console::out("  cqo");
                } else {
                    Console::out("  cdq");
                }
                Console::out("  idiv %s", $di);

                if ($node->kind === NodeKind::ND_MOD){
                    Console::out("  mov %%rdx, %%rax");
                }
                return;
            case NodeKind::ND_BITAND:
                Console::out("  and %%rdi, %%rax");
                return;
            case NodeKind::ND_BITOR:
                Console::out("  or %%rdi, %%rax");
                return;
            case NodeKind::ND_BITXOR:
                Console::out("  xor %%rdi, %%rax");
                return;
            case NodeKind::ND_EQ:
            case NodeKind::ND_NE:
            case NodeKind::ND_LT:
            case NodeKind::ND_LE:
                Console::out("  cmp %s, %s", $di, $ax);

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
                    Console::out("  je  %s", $node->brkLabel);
                }
                $this->genStmt($node->then);
                if ($node->inc) {
                    $this->genExpr($node->inc);
                }
                Console::out("  jmp .L.begin.%d", $c);
                Console::out("%s:", $node->brkLabel);
                return;
            }
            case NodeKind::ND_BLOCK:
                foreach ($node->body as $n){
                    $this->genStmt($n);
                }
                return;
            case NodeKind::ND_GOTO:
                Console::out("  jmp %s", $node->uniqueLabel);
                return;
            case NodeKind::ND_LABEL:
                Console::out("%s:", $node->uniqueLabel);
                $this->genStmt($node->lhs);
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
                $offset = Align::alignTo($offset, $var->ty->align);
                $var->offset = -1 * $offset;
            }
            $fn->stackSize = Align::alignTo($offset, 16);
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

    public function storeGp(int $r, int $offset, int $sz): void
    {
        switch($sz){
            case 1:
                Console::out("  mov %s, %d(%%rbp)", $this->argreg8[$r], $offset);
                return;
            case 2:
                Console::out("  mov %s, %d(%%rbp)", $this->argreg16[$r], $offset);
                return;
            case 4:
                Console::out("  mov %s, %d(%%rbp)", $this->argreg32[$r], $offset);
                return;
            case 8:
                Console::out("  mov %s, %d(%%rbp)", $this->argreg64[$r], $offset);
                return;
        }
        Console::unreachable(__FILE__, __LINE__);
    }

    /**
     * @param Obj[] $prog
     * @return void
     */
    public function emitText(array $prog): void
    {
        foreach ($prog as $fn){
            if ((! $fn->isFunction) or (! $fn->isDefinition)){
                continue;
            }

            if ($fn->isStatic){
                Console::out("  .local %s", $fn->name);
            } else {
                Console::out("  .globl %s", $fn->name);
            }

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
                $this->storeGp($idx, $param->offset, $param->ty->size);
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