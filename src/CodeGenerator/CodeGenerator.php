<?php

namespace Pcc\CodeGenerator;

use Pcc\Ast\Align;
use Pcc\Ast\Obj;
use Pcc\Ast\Node;
use Pcc\Ast\NodeKind;
use Pcc\Ast\Type;
use Pcc\Ast\Type\PccGMP;
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
    public string $i32u8 = "movzbl %al, %eax";
    public string $i32i16 = "movswl %ax, %eax";
    public string $i32u16 = "movzwl %ax, %eax";
    public string $i32i64 = "movsxd %eax, %rax";
    public string $u32i64 = "mov %eax, %eax";
    public array $castTable = [];

    public Obj $currentFn;

    public function __construct()
    {
        $this->castTable = [
             // i8          i16             i32   i64               u8              u16             u32   u64
            [null,          null,           null, $this->i32i64,    $this->i32u8,   $this->i32u16,  null, $this->i32i64],   // i8
            [$this->i32i8,  null,           null, $this->i32i64,    $this->i32u8,   $this->i32u16,  null, $this->i32i64],   // i16
            [$this->i32i8,  $this->i32i16,  null, $this->i32i64,    $this->i32u8,   $this->i32u16,  null, $this->i32i64],   // i32
            [$this->i32i8,  $this->i32i16,  null, null,             $this->i32u8,   $this->i32u16,  null, null],            // i64
            [$this->i32i8,  null,           null, $this->i32i64,    null,           null,           null, $this->i32i64],   // u8
            [$this->i32i8,  $this->i32i16,  null, $this->i32i64,    $this->i32u8,   null,           null, $this->i32i64],   // u16
            [$this->i32i8,  $this->i32i16,  null, $this->u32i64,    $this->i32u8,   $this->i32u16,  null, $this->u32i64],   // u32
            [$this->i32i8,  $this->i32i16,  null, null,             $this->i32u8,   $this->i32u16,  null, null],            // u64
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
                Console::out("  add $%d, %%rax", $node->members[0]->offset);
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

        $insn = $ty->isUnsigned? 'movz' : 'movs';

        if ($ty->size === 1) {
            Console::out("  %sbl (%%rax), %%eax", $insn);
        } elseif ($ty->size === 2) {
            Console::out("  %swl (%%rax), %%eax", $insn);
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
            TypeKind::TY_CHAR   => $ty->isUnsigned? TypeId::U8->value:  TypeId::I8->value,
            TypeKind::TY_SHORT  => $ty->isUnsigned? TypeId::U16->value: TypeId::I16->value,
            TypeKind::TY_INT    => $ty->isUnsigned? TypeId::U32->value: TypeId::I32->value,
            TypeKind::TY_LONG   => $ty->isUnsigned? TypeId::U64->value: TypeId::I64->value,
            default             => TypeId::U64->value,
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
            case NodeKind::ND_NULL_EXPR:
                return;
            case NodeKind::ND_NUM:
                Console::out("  mov \$%ld, %%rax", PccGMP::toSignedInt($node->gmpVal));
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
            case NodeKind::ND_MEMZERO:
                Console::out("  mov $%d, %%rcx", $node->var->ty->size);
                Console::out("  lea %d(%%rbp), %%rdi", $node->var->offset);
                Console::out("  mov \$0, %%al");
                Console::out("  rep stosb");
                return;
            case NodeKind::ND_COND:
                $c = $this->cnt();
                $this->genExpr($node->cond);
                Console::out("  cmp \$0, %%rax");
                Console::out("  je .L.else.%d", $c);
                $this->genExpr($node->then);
                Console::out("  jmp .L.end.%d", $c);
                Console::out(".L.else.%d:", $c);
                $this->genExpr($node->els);
                Console::out(".L.end.%d:", $c);
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

                if ($this->depth %2 === 0){
                    Console::out("  call %s", $node->funcname);
                } else {
                    Console::out("  sub \$8, %%rsp");
                    Console::out("  call %s", $node->funcname);
                    Console::out("  add \$8, %%rsp");
                }

                // It looks like the most significant 48 or 56 bits in RAX may
                // contain garbage if a function return type is short or bool/char,
                // respectively. We clear the upper bits here.
                switch ($node->ty->kind){
                    case TypeKind::TY_BOOL:
                        Console::out("  movzx %%al, %%eax");
                        return;
                    case TypeKind::TY_CHAR:
                        if ($node->ty->isUnsigned){
                            Console::out("  movzbl %%al, %%eax");
                        } else {
                            Console::out("  movsbl %%al, %%eax");
                        }
                        return;
                    case TypeKind::TY_SHORT:
                        if ($node->ty->isUnsigned){
                            Console::out("  movzwl %%ax, %%eax");
                        } else {
                            Console::out("  movswl %%ax, %%eax");
                        }
                        return;
                }

                return;
        }

        $this->genExpr($node->rhs);
        $this->push();
        $this->genExpr($node->lhs);
        $this->pop('%rdi');

        if ($node->lhs->ty->kind === TypeKind::TY_LONG or $node->lhs->ty->base){
            $ax = '%rax';
            $di = '%rdi';
            $dx = "%rdx";
        } else {
            $ax = '%eax';
            $di = '%edi';
            $dx = "%edx";
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
                if ($node->ty->isUnsigned){
                    Console::out("  mov \$0, %s", $dx);
                    Console::out("  div %s", $di);
                } else {
                    if ($node->lhs->ty->size === 8){
                        Console::out("  cqo");
                    } else {
                        Console::out("  cdq");
                    }
                    Console::out("  idiv %s", $di);
                }

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
                } elseif ($node->kind == NodeKind::ND_NE){
                    Console::out("  setne %%al");
                } elseif ($node->kind == NodeKind::ND_LT){
                    if ($node->lhs->ty->isUnsigned){
                        Console::out("  setb %%al");
                    } else {
                        Console::out("  setl %%al");
                    }
                } elseif ($node->kind == NodeKind::ND_LE){
                    if ($node->lhs->ty->isUnsigned){
                        Console::out("  setbe %%al");
                    } else {
                        Console::out("  setle %%al");
                    }
                }
                Console::out("  movzb %%al, %%rax");
                return;
            case NodeKind::ND_SHL:
                Console::out("  mov %%rdi, %%rcx");
                Console::out("  shl %%cl, %s", $ax);
                return;
            case NodeKind::ND_SHR:
                Console::out("  mov %%rdi, %%rcx");
                if ($node->lhs->ty->isUnsigned){
                    Console::out("  shr %%cl, %s", $ax);
                } else {
                    Console::out("  sar %%cl, %s", $ax);
                }
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
                Console::out("%s:", $node->contLabel);
                if ($node->inc) {
                    $this->genExpr($node->inc);
                }
                Console::out("  jmp .L.begin.%d", $c);
                Console::out("%s:", $node->brkLabel);
                return;
            }
            case NodeKind::ND_DO:
                $c = $this->cnt();
                Console::out(".L.begin.%d:", $c);
                $this->genStmt($node->then);
                Console::out("%s:", $node->contLabel);
                $this->genExpr($node->cond);
                Console::out("  cmp \$0, %%rax");
                Console::out("  jne .L.begin.%d", $c);
                Console::out("%s:", $node->brkLabel);
                return;
            case NodeKind::ND_SWITCH:
                $this->genExpr($node->cond);

                foreach ($node->cases as $n){
                    $reg = ($node->cond->ty->size == 8) ? '%rax' : '%eax';
                    Console::out("  cmp \$%ld, %s", PccGMP::toSignedInt($n->gmpVal), $reg);
                    Console::out("  je %s", $n->label);
                }

                if ($node->defaultCase){
                    Console::out("  jmp %s", $node->defaultCase->label);
                }

                Console::out("  jmp %s", $node->brkLabel);
                $this->genStmt($node->then);
                Console::out("%s:", $node->brkLabel);
                return;
            case NodeKind::ND_CASE:
                Console::out("%s:", $node->label);
                $this->genStmt($node->lhs);
                return;
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
                if ($node->lhs){
                    $this->genExpr($node->lhs);
                }
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
                $offset = Align::alignTo($offset, $var->align);
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
            if ($var->isFunction or (! $var->isDefinition)){
                continue;
            }

            if ($var->isStatic){
                Console::out("  .local %s", $var->name);
            } else {
                Console::out("  .globl %s", $var->name);
            }
            Console::out("  .align %d", $var->align);

            if (! is_null($var->initData)){
                Console::out("  .data");
                Console::out("%s:", $var->name);

                $pos = 0;
                $relIdx = 0;
                while ($pos < $var->ty->size){
                    $rel = $var->rels[$relIdx] ?? null;
                    if ($rel and $rel->offset === $pos){
                        Console::out("  .quad %s%+ld", $rel->label, $rel->addend);
                        $relIdx++;
                        $pos += 8;
                    } else {
                        Console::out("  .byte %d", ord($var->initData[$pos++]));
                    }
                }
                continue;
            }

            Console::out("  .bss");
            Console::out("%s:", $var->name);
            Console::out("  .zero %d", $var->ty->size);
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

            // Save arg registers if function is variadic
            if ($fn->vaArea) {
                $gp = count($fn->params);
                $off = $fn->vaArea->offset;

                // va_elem
                Console::out("  movl $%d, %d(%%rbp)", $gp * 8, $off);
                Console::out("  movl $0, %d(%%rbp)", $off + 4);
                Console::out("  movq %%rbp, %d(%%rbp)", $off + 16);
                Console::out("  addq $%d, %d(%%rbp)", $off + 24, $off + 16);

                // __reg_save_area__
                Console::out("  movq %%rdi, %d(%%rbp)", $off + 24);
                Console::out("  movq %%rsi, %d(%%rbp)", $off + 32);
                Console::out("  movq %%rdx, %d(%%rbp)", $off + 40);
                Console::out("  movq %%rcx, %d(%%rbp)", $off + 48);
                Console::out("  movq %%r8, %d(%%rbp)", $off + 56);
                Console::out("  movq %%r9, %d(%%rbp)", $off + 64);
                Console::out("  movsd %%xmm0, %d(%%rbp)", $off + 72);
                Console::out("  movsd %%xmm1, %d(%%rbp)", $off + 80);
                Console::out("  movsd %%xmm2, %d(%%rbp)", $off + 88);
                Console::out("  movsd %%xmm3, %d(%%rbp)", $off + 96);
                Console::out("  movsd %%xmm4, %d(%%rbp)", $off + 104);
                Console::out("  movsd %%xmm5, %d(%%rbp)", $off + 112);
                Console::out("  movsd %%xmm6, %d(%%rbp)", $off + 120);
                Console::out("  movsd %%xmm7, %d(%%rbp)", $off + 128);
            }

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