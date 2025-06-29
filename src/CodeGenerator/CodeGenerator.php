<?php

namespace Pcc\CodeGenerator;

use Pcc\Ast\Align;
use Pcc\Ast\Obj;
use Pcc\Ast\Node;
use Pcc\Ast\NodeKind;
use Pcc\Ast\Type;
use Pcc\Ast\Type\PccGMP;
use Pcc\Console;
use Pcc\Ast\TypeKind;
use Pcc\Pcc;

class CodeGenerator
{
    private const GP_MAX = 6;
    private const FP_MAX = 8;
    
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
    public string $i32f32 = "cvtsi2ssl %eax, %xmm0";
    public string $i32i64 = "movsxd %eax, %rax";
    public string $i32f64 = "cvtsi2sdl %eax, %xmm0";
    public string $i32f80 = "mov %eax, -4(%rsp); fildl -4(%rsp)";
    
    public string $u32f32 = "mov %eax, %eax; cvtsi2ssq %rax, %xmm0";
    public string $u32i64 = "mov %eax, %eax";
    public string $u32f64 = "mov %eax, %eax; cvtsi2sdq %rax, %xmm0";
    public string $u32f80 = "mov %eax, %eax; mov %rax, -8(%rsp); fildll -8(%rsp)";
    
    public string $i64f32 = "cvtsi2ssq %rax, %xmm0";
    public string $i64f64 = "cvtsi2sdq %rax, %xmm0";
    public string $i64f80 = "movq %rax, -8(%rsp); fildll -8(%rsp)";
    
    public string $u64f32 = "cvtsi2ssq %rax, %xmm0";
    public string $u64f64 = 
        "test %rax,%rax; js 1f; pxor %xmm0,%xmm0; cvtsi2sd %rax,%xmm0; jmp 2f; ".
        "1: mov %rax,%rdi; and $1,%eax; pxor %xmm0,%xmm0; shr %rdi; ".
        "or %rax,%rdi; cvtsi2sd %rdi,%xmm0; addsd %xmm0,%xmm0; 2:";
    public string $u64f80 = 
        "mov %rax, -8(%rsp); fildq -8(%rsp); test %rax, %rax; jns 1f;".
        "mov $1602224128, %eax; mov %eax, -4(%rsp); fadds -4(%rsp); 1:";
    
    public string $f32i8 = "cvttss2sil %xmm0, %eax; movsbl %al, %eax";
    public string $f32u8 = "cvttss2sil %xmm0, %eax; movzbl %al, %eax";
    public string $f32i16 = "cvttss2sil %xmm0, %eax; movswl %ax, %eax";
    public string $f32u16 = "cvttss2sil %xmm0, %eax; movzwl %ax, %eax";
    public string $f32i32 = "cvttss2sil %xmm0, %eax";
    public string $f32u32 = "cvttss2siq %xmm0, %rax";
    public string $f32i64 = "cvttss2siq %xmm0, %rax";
    public string $f32u64 = "cvttss2siq %xmm0, %rax";
    public string $f32f64 = "cvtss2sd %xmm0, %xmm0";
    public string $f32f80 = "movss %xmm0, -4(%rsp); flds -4(%rsp)";
    
    public string $f64i8 = "cvttsd2sil %xmm0, %eax; movsbl %al, %eax";
    public string $f64u8 = "cvttsd2sil %xmm0, %eax; movzbl %al, %eax";
    public string $f64i16 = "cvttsd2sil %xmm0, %eax; movswl %ax, %eax";
    public string $f64u16 = "cvttsd2sil %xmm0, %eax; movzwl %ax, %eax";
    public string $f64i32 = "cvttsd2sil %xmm0, %eax";
    public string $f64u32 = "cvttsd2siq %xmm0, %rax";
    public string $f64f32 = "cvtsd2ss %xmm0, %xmm0";
    public string $f64i64 = "cvttsd2siq %xmm0, %rax";
    public string $f64u64 = "cvttsd2siq %xmm0, %rax";
    public string $f64f80 = "movsd %xmm0, -8(%rsp); fldl -8(%rsp)";
    
    private string $fromF801 = 
        "fnstcw -10(%rsp); movzwl -10(%rsp), %eax; or $12, %ah; ".
        "mov %ax, -12(%rsp); fldcw -12(%rsp); ";
    private string $fromF802 = " -24(%rsp); fldcw -10(%rsp); ";
    
    public string $f80i8;
    public string $f80u8;
    public string $f80i16;
    public string $f80u16;
    public string $f80i32;
    public string $f80u32;
    public string $f80i64;
    public string $f80u64;
    public string $f80f32 = "fstps -8(%rsp); movss -8(%rsp), %xmm0";
    public string $f80f64 = "fstpl -8(%rsp); movsd -8(%rsp), %xmm0";
    
    public array $castTable = [];

    public Obj $currentFn;

    private function builtinAlloca(): void
    {
        // Align size to 16 bytes
        Console::out("  add \$15, %%rdi");
        Console::out("  and \$0xfffffff0, %%edi");
        
        // Shift the temporary area by %rdi
        Console::out("  mov %d(%%rbp), %%rcx", $this->currentFn->allocaBottom->offset);
        Console::out("  sub %%rsp, %%rcx");
        Console::out("  mov %%rsp, %%rax");
        Console::out("  sub %%rdi, %%rsp");
        Console::out("  mov %%rsp, %%rdx");
        Console::out("1:");
        Console::out("  cmp \$0, %%rcx");
        Console::out("  je 2f");
        Console::out("  mov (%%rax), %%r8b");
        Console::out("  mov %%r8b, (%%rdx)");
        Console::out("  inc %%rdx");
        Console::out("  inc %%rax");
        Console::out("  dec %%rcx");
        Console::out("  jmp 1b");
        Console::out("2:");
        
        // Move alloca_bottom pointer
        Console::out("  mov %d(%%rbp), %%rax", $this->currentFn->allocaBottom->offset);
        Console::out("  sub %%rdi, %%rax");
        Console::out("  mov %%rax, %d(%%rbp)", $this->currentFn->allocaBottom->offset);
    }

    public function __construct()
    {
        $this->f80i8 = $this->fromF801 . "fistps" . $this->fromF802 . "movsbl -24(%rsp), %eax";
        $this->f80u8 = $this->fromF801 . "fistps" . $this->fromF802 . "movzbl -24(%rsp), %eax";
        $this->f80i16 = $this->fromF801 . "fistps" . $this->fromF802 . "movzbl -24(%rsp), %eax";
        $this->f80u16 = $this->fromF801 . "fistpl" . $this->fromF802 . "movswl -24(%rsp), %eax";
        $this->f80i32 = $this->fromF801 . "fistpl" . $this->fromF802 . "mov -24(%rsp), %eax";
        $this->f80u32 = $this->fromF801 . "fistpl" . $this->fromF802 . "mov -24(%rsp), %eax";
        $this->f80i64 = $this->fromF801 . "fistpq" . $this->fromF802 . "mov -24(%rsp), %rax";
        $this->f80u64 = $this->fromF801 . "fistpq" . $this->fromF802 . "mov -24(%rsp), %rax";
        
        $this->castTable = [
             // i8         i16            i32            i64            u8            u16            u32            u64            f32            f64            f80
            [null,         null,          null,          $this->i32i64, $this->i32u8, $this->i32u16, null,          $this->i32i64, $this->i32f32, $this->i32f64, $this->i32f80], // i8
            [$this->i32i8, null,          null,          $this->i32i64, $this->i32u8, $this->i32u16, null,          $this->i32i64, $this->i32f32, $this->i32f64, $this->i32f80], // i16
            [$this->i32i8, $this->i32i16, null,          $this->i32i64, $this->i32u8, $this->i32u16, null,          $this->i32i64, $this->i32f32, $this->i32f64, $this->i32f80], // i32
            [$this->i32i8, $this->i32i16, null,          null,          $this->i32u8, $this->i32u16, null,          null,          $this->i64f32, $this->i64f64, $this->i64f80], // i64
            
            [$this->i32i8, null,          null,          $this->i32i64, null,         null,          null,          $this->i32i64, $this->i32f32, $this->i32f64, $this->i32f80], // u8
            [$this->i32i8, $this->i32i16, null,          $this->i32i64, $this->i32u8, null,          null,          $this->i32i64, $this->i32f32, $this->i32f64, $this->i32f80], // u16
            [$this->i32i8, $this->i32i16, null,          $this->u32i64, $this->i32u8, $this->i32u16, null,          $this->u32i64, $this->u32f32, $this->u32f64, $this->u32f80], // u32
            [$this->i32i8, $this->i32i16, null,          null,          $this->i32u8, $this->i32u16, null,          null,          $this->u64f32, $this->u64f64, $this->u64f80], // u64
            
            [$this->f32i8, $this->f32i16, $this->f32i32, $this->f32i64, $this->f32u8, $this->f32u16, $this->f32u32, $this->f32u64, null,          $this->f32f64, $this->f32f80], // f32
            [$this->f64i8, $this->f64i16, $this->f64i32, $this->f64i64, $this->f64u8, $this->f64u16, $this->f64u32, $this->f64u64, $this->f64f32, null,          $this->f64f80], // f64
            [$this->f80i8, $this->f80i16, $this->f80i32, $this->f80i64, $this->f80u8, $this->f80u16, $this->f80u32, $this->f80u64, $this->f80f32, $this->f80f64, null], // f80
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

    public function pushf(): void
    {
        Console::out("  sub $8, %%rsp");
        Console::out("  movsd %%xmm0, (%%rsp)");
        $this->depth++;
    }

    public function popf(int $reg): void
    {
        Console::out("  movsd (%%rsp), %%xmm%d", $reg);
        Console::out("  add $8, %%rsp");
        $this->depth--;
    }

    private function pushArgs2(array $args, bool $firstPass): void
    {
        if (empty($args)) {
            return;
        }

        // Process in reverse order
        $this->pushArgs2(array_slice($args, 1), $firstPass);

        $arg = $args[0];
        if (($firstPass && !$arg->passByStack) || (!$firstPass && $arg->passByStack)) {
            return;
        }

        $this->genExpr($arg);

        switch ($arg->ty->kind) {
            case TypeKind::TY_STRUCT:
            case TypeKind::TY_UNION:
                $this->pushStruct($arg->ty);
                break;
            case TypeKind::TY_FLOAT:
            case TypeKind::TY_DOUBLE:
                $this->pushf();
                break;
            case TypeKind::TY_LDOUBLE:
                Console::out("  sub \$16, %%rsp");
                Console::out("  fstpt (%%rsp)");
                $this->depth += 2;
                break;
            default:
                $this->push();
        }
    }

    public function pushArgs(Node $node): int
    {
        $stack = 0;
        $gp = 0;
        $fp = 0;

        // If the return type is a large struct/union, the caller passes
        // a pointer to a buffer as if it were the first argument.
        if ($node->retBuffer && $node->ty->size > 16) {
            $gp++;
        }

        // Load as many arguments to the registers as possible.
        foreach ($node->args as $arg) {
            $ty = $arg->ty;

            switch ($ty->kind) {
                case TypeKind::TY_STRUCT:
                case TypeKind::TY_UNION:
                    if ($ty->size > 16) {
                        $arg->passByStack = true;
                        $stack += intval(Align::alignTo($ty->size, 8) / 8);
                    } else {
                        $fp1 = $this->hasFlonum1($ty);
                        $fp2 = $this->hasFlonum2($ty);

                        if ($fp + ($fp1 ? 1 : 0) + ($fp2 ? 1 : 0) < self::FP_MAX && 
                            $gp + ($fp1 ? 0 : 1) + ($fp2 ? 0 : 1) < self::GP_MAX) {
                            $fp = $fp + ($fp1 ? 1 : 0) + ($fp2 ? 1 : 0);
                            $gp = $gp + ($fp1 ? 0 : 1) + ($fp2 ? 0 : 1);
                        } else {
                            $arg->passByStack = true;
                            $stack += intval(Align::alignTo($ty->size, 8) / 8);
                        }
                    }
                    break;
                case TypeKind::TY_FLOAT:
                case TypeKind::TY_DOUBLE:
                    if ($fp++ >= self::FP_MAX) {
                        $arg->passByStack = true;
                        $stack++;
                    }
                    break;
                case TypeKind::TY_LDOUBLE:
                    $arg->passByStack = true;
                    $stack += 2;
                    break;
                default:
                    if ($gp++ >= self::GP_MAX) {
                        $arg->passByStack = true;
                        $stack++;
                    }
            }
        }

        if (($this->depth + $stack) % 2 === 1) {
            Console::out("  sub $8, %%rsp");
            $this->depth++;
            $stack++;
        }

        $this->pushArgs2($node->args, true);
        $this->pushArgs2($node->args, false);

        // If the return type is a large struct/union, the caller passes
        // a pointer to a buffer as if it were the first argument.
        if ($node->retBuffer && $node->ty->size > 16) {
            Console::out("  lea %d(%%rbp), %%rax", $node->retBuffer->offset);
            $this->push();
        }

        return $stack;
    }

    private function copyRetBuffer(Obj $var): void
    {
        $ty = $var->ty;
        $gp = 0;
        $fp = 0;

        if ($this->hasFlonum1($ty)) {
            assert($ty->size == 4 || $ty->size >= 8);
            if ($ty->size == 4) {
                Console::out("  movss %%xmm0, %d(%%rbp)", $var->offset);
            } else {
                Console::out("  movsd %%xmm0, %d(%%rbp)", $var->offset);
            }
            $fp++;
        } else {
            for ($i = 0; $i < min(8, $ty->size); $i++) {
                Console::out("  mov %%al, %d(%%rbp)", $var->offset + $i);
                Console::out("  shr $8, %%rax");
            }
            $gp++;
        }

        if ($ty->size > 8) {
            if ($this->hasFlonum2($ty)) {
                assert($ty->size == 12 || $ty->size == 16);
                if ($ty->size == 12) {
                    Console::out("  movss %%xmm%d, %d(%%rbp)", $fp, $var->offset + 8);
                } else {
                    Console::out("  movsd %%xmm%d, %d(%%rbp)", $fp, $var->offset + 8);
                }
            } else {
                $reg1 = ($gp == 0) ? "%al" : "%dl";
                $reg2 = ($gp == 0) ? "%rax" : "%rdx";
                for ($i = 8; $i < min(16, $ty->size); $i++) {
                    Console::out("  mov %s, %d(%%rbp)", $reg1, $var->offset + $i);
                    Console::out("  shr $8, %s", $reg2);
                }
            }
        }
    }

    public function genAddr(Node $node): void
    {
        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind){
            case NodeKind::ND_VAR:
                // Variable-length array, which is always local.
                if ($node->var->ty->kind === TypeKind::TY_VLA) {
                    Console::out("  mov %d(%%rbp), %%rax", $node->var->offset);
                    return;
                }

                // Local variable
                if ($node->var->isLocal){
                    Console::out("  lea %d(%%rbp), %%rax", $node->var->offset);
                    return;
                }

                if (Pcc::getOptFpic()) {
                    // Thread-local variable
                    if ($node->var->isTls) {
                        Console::out("  data16 lea %s@tlsgd(%%rip), %%rdi", $node->var->name);
                        Console::out("  .value 0x6666");
                        Console::out("  rex64");
                        Console::out("  call __tls_get_addr@PLT");
                        return;
                    }

                    // Function or global variable
                    Console::out("  mov %s@GOTPCREL(%%rip), %%rax", $node->var->name);
                    return;
                }

                // Thread-local variable
                if ($node->var->isTls) {
                    Console::out("  mov %%fs:0, %%rax");
                    Console::out("  add $%s@tpoff, %%rax", $node->var->name);
                    return;
                }

                // Function
                if ($node->ty->kind === TypeKind::TY_FUNC) {
                    if ($node->var->isDefinition) {
                        Console::out("  lea %s(%%rip), %%rax", $node->var->name);
                    } else {
                        Console::out("  mov %s@GOTPCREL(%%rip), %%rax", $node->var->name);
                    }
                    return;
                }

                // Global variable
                Console::out("  lea %s(%%rip), %%rax", $node->var->name);
                return;
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
            case NodeKind::ND_FUNCALL:
                if ($node->retBuffer) {
                    $this->genExpr($node);
                    return;
                }
                break;
            case NodeKind::ND_VLA_PTR:
                Console::out("  lea %d(%%rbp), %%rax", $node->var->offset);
                return;
            case NodeKind::ND_ASSIGN:
            case NodeKind::ND_COND:
                if ($node->ty->kind === TypeKind::TY_STRUCT or $node->ty->kind === TypeKind::TY_UNION) {
                    $this->genExpr($node);
                    return;
                }
                break;
        }

        Console::errorTok($node->tok, 'not an lvalue');
    }

    // Load a value from where %rax is pointing to.
    public function load(Type $ty): void
    {
        switch ($ty->kind) {
            case TypeKind::TY_ARRAY:
            case TypeKind::TY_STRUCT:
            case TypeKind::TY_UNION:
            case TypeKind::TY_FUNC:
            case TypeKind::TY_VLA:
                return;
            case TypeKind::TY_FLOAT:
                Console::out("  movss (%%rax), %%xmm0");
                return;
            case TypeKind::TY_DOUBLE:
                Console::out("  movsd (%%rax), %%xmm0");
                return;
            case TypeKind::TY_LDOUBLE:
                Console::out("  fldt (%%rax)");
                return;
        }

        $insn = $ty->isUnsigned? 'movz' : 'movs';

        if ($ty->size === 1) {
            Console::out("  %sbl (%%rax), %%eax", $insn);
        } elseif ($ty->size === 2) {
            Console::out("  %swl (%%rax), %%eax", $insn);
        } elseif ($ty->size === 4) {
            Console::out("  mov (%%rax), %%eax");
        } else {
            Console::out("  mov (%%rax), %%rax");
        }
    }

    public function store(Type $ty): void
    {
        $this->pop('%rdi');

        switch ($ty->kind) {
            case TypeKind::TY_STRUCT:
            case TypeKind::TY_UNION:
                for ($i = 0; $i < $ty->size; $i++){
                    Console::out("  mov %d(%%rax), %%r8b", $i);
                    Console::out("  mov %%r8b, %d(%%rdi)", $i);
                }
                return;
            case TypeKind::TY_FLOAT:
                Console::out("  movss %%xmm0, (%%rdi)");
                return;
            case TypeKind::TY_DOUBLE:
                Console::out("  movsd %%xmm0, (%%rdi)");
                return;
            case TypeKind::TY_LDOUBLE:
                Console::out("  fstpt (%%rdi)");
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
        if ($ty->kind === TypeKind::TY_FLOAT) {
            Console::out("  xorps %%xmm1, %%xmm1");
            Console::out("  ucomiss %%xmm1, %%xmm0");
        } elseif ($ty->kind === TypeKind::TY_DOUBLE) {
            Console::out("  xorpd %%xmm1, %%xmm1");
            Console::out("  ucomisd %%xmm1, %%xmm0");
        } elseif ($ty->kind === TypeKind::TY_LDOUBLE) {
            Console::out("  fldz");
            Console::out("  fucomip");
            Console::out("  fstp %%st(0)");
        } elseif ($ty->isInteger() and $ty->size <= 4){
            Console::out("  cmp \$0, %%eax");
        } else {
            Console::out("  cmp \$0, %%rax");
        }
    }

    public function getTypeId(Type $ty): int
    {
        return match ($ty->kind) {
            TypeKind::TY_CHAR    => $ty->isUnsigned? TypeId::U8->value:  TypeId::I8->value,
            TypeKind::TY_SHORT   => $ty->isUnsigned? TypeId::U16->value: TypeId::I16->value,
            TypeKind::TY_INT     => $ty->isUnsigned? TypeId::U32->value: TypeId::I32->value,
            TypeKind::TY_LONG    => $ty->isUnsigned? TypeId::U64->value: TypeId::I64->value,
            TypeKind::TY_FLOAT   => TypeId::F32->value,
            TypeKind::TY_DOUBLE  => TypeId::F64->value,
            TypeKind::TY_LDOUBLE => TypeId::F80->value,
            default              => TypeId::U64->value,
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

    // Structs or unions equal or smaller than 16 bytes are passed
    // using up to two registers.
    //
    // If the first 8 bytes contains only floating-point type members,
    // they are passed in an XMM register. Otherwise, they are passed
    // in a general-purpose register.
    //
    // If a struct/union is larger than 8 bytes, the same rule is
    // applied to the the next 8 byte chunk.
    //
    // This function returns true if `ty` has only floating-point
    // members in its byte range [lo, hi).
    private function hasFlonum(Type $ty, int $lo, int $hi, int $offset): bool
    {
        if ($ty->kind === TypeKind::TY_STRUCT || $ty->kind === TypeKind::TY_UNION) {
            foreach ($ty->members as $mem) {
                if (!$this->hasFlonum($mem->ty, $lo, $hi, $offset + $mem->offset)) {
                    return false;
                }
            }
            return true;
        }

        if ($ty->kind === TypeKind::TY_ARRAY) {
            for ($i = 0; $i < $ty->arrayLen; $i++) {
                if (!$this->hasFlonum($ty->base, $lo, $hi, $offset + $ty->base->size * $i)) {
                    return false;
                }
            }
            return true;
        }

        return $offset < $lo || $hi <= $offset || $ty->kind === TypeKind::TY_FLOAT || $ty->kind === TypeKind::TY_DOUBLE;
    }

    private function hasFlonum1(Type $ty): bool
    {
        return $this->hasFlonum($ty, 0, 8, 0);
    }

    private function hasFlonum2(Type $ty): bool
    {
        return $this->hasFlonum($ty, 8, 16, 0);
    }

    private function regDx(int $sz): string
    {
        return match ($sz) {
            1 => "%dl",
            2 => "%dx", 
            4 => "%edx",
            8 => "%rdx",
            default => Console::error("unreachable")
        };
    }

    private function regAx(int $sz): string
    {
        return match ($sz) {
            1 => "%al",
            2 => "%ax",
            4 => "%eax", 
            8 => "%rax",
            default => Console::error("unreachable")
        };
    }

    private function pushStruct(Type $ty): void
    {
        $sz = Align::alignTo($ty->size, 8);
        Console::out("  sub $%d, %%rsp", $sz);
        $this->depth += intval($sz / 8);

        for ($i = 0; $i < $ty->size; $i++) {
            Console::out("  mov %d(%%rax), %%r10b", $i);
            Console::out("  mov %%r10b, %d(%%rsp)", $i);
        }
    }

    public function genExpr(Node $node): void
    {
        if ($node->tok->file !== null) {
            Console::out("  .loc %d %d", $node->tok->file->fileNo, $node->tok->lineNo);
        }

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind) {
            case NodeKind::ND_NULL_EXPR:
                return;
            case NodeKind::ND_NUM:
                switch ($node->ty->kind){
                    case TypeKind::TY_FLOAT:
                        Console::out("  mov \$%u, %%eax   # float %f", PccGMP::toPHPInt(PccGMP::toUint32t($node->gmpVal)), $node->fval);
                        Console::out("  movq %%rax, %%xmm0");
                        return;
                    case TypeKind::TY_DOUBLE:
                        Console::out("  mov \$%lu, %%rax   # double %f", PccGMP::toPHPInt(PccGMP::toUint64t($node->gmpVal)), $node->fval);
                        Console::out("  movq %%rax, %%xmm0");
                        return;
                    case TypeKind::TY_LDOUBLE:
                        $bytes = [];
                        $ldoubleBytes = pack('E', $node->fval);
                        for ($i = 0; $i < 16; $i++) {
                            $bytes[] = isset($ldoubleBytes[$i]) ? ord($ldoubleBytes[$i]) : 0;
                        }
                        Console::out("  mov \$%lu, %%rax  # long double %f", ($bytes[7] << 56) | ($bytes[6] << 48) | ($bytes[5] << 40) | ($bytes[4] << 32) | ($bytes[3] << 24) | ($bytes[2] << 16) | ($bytes[1] << 8) | $bytes[0], $node->fval);
                        Console::out("  mov %%rax, -16(%%rsp)");
                        Console::out("  mov \$%lu, %%rax", ($bytes[15] << 56) | ($bytes[14] << 48) | ($bytes[13] << 40) | ($bytes[12] << 32) | ($bytes[11] << 24) | ($bytes[10] << 16) | ($bytes[9] << 8) | $bytes[8]);
                        Console::out("  mov %%rax, -8(%%rsp)");
                        Console::out("  fldt -16(%%rsp)");
                        return;
                }
                Console::out("  mov \$%ld, %%rax", PccGMP::toPHPInt($node->gmpVal));
                return;
            case NodeKind::ND_VAR:
                $this->genAddr($node);
                $this->load($node->ty);
                return;
            case NodeKind::ND_MEMBER:
                $this->genAddr($node);
                $this->load($node->ty);

                if (isset($node->member) && $node->member->isBitfield) {
                    $mem = $node->member;
                    Console::out("  shl $%d, %%rax", 64 - $mem->bitWidth - $mem->bitOffset);
                    if ($mem->ty->isUnsigned) {
                        Console::out("  shr $%d, %%rax", 64 - $mem->bitWidth);
                    } else {
                        Console::out("  sar $%d, %%rax", 64 - $mem->bitWidth);
                    }
                }
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

                if ($node->lhs->kind === NodeKind::ND_MEMBER && isset($node->lhs->member) && $node->lhs->member->isBitfield) {
                    Console::out("  mov %%rax, %%r8");

                    // If the lhs is a bitfield, we need to read the current value
                    // from memory and merge it with a new value.
                    $mem = $node->lhs->member;
                    Console::out("  mov %%rax, %%rdi");
                    Console::out("  and $%d, %%rdi", (1 << $mem->bitWidth) - 1);
                    Console::out("  shl $%d, %%rdi", $mem->bitOffset);

                    Console::out("  mov (%%rsp), %%rax");
                    $this->load($mem->ty);

                    $mask = ((1 << $mem->bitWidth) - 1) << $mem->bitOffset;
                    Console::out("  mov $%d, %%r9", ~$mask);
                    Console::out("  and %%r9, %%rax");
                    Console::out("  or %%rdi, %%rax");
                    $this->store($node->ty);
                    Console::out("  mov %%r8, %%rax");
                    return;
                }

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
                $this->cmpZero($node->cond->ty);
                Console::out("  je .L.else.%d", $c);
                $this->genExpr($node->then);
                Console::out("  jmp .L.end.%d", $c);
                Console::out(".L.else.%d:", $c);
                $this->genExpr($node->els);
                Console::out(".L.end.%d:", $c);
                return;
            case NodeKind::ND_NOT:
                $this->genExpr($node->lhs);
                $this->cmpZero($node->lhs->ty);
                Console::out("  sete %%al");
                Console::out("  movzx %%al, %%rax");
                return;
            case NodeKind::ND_BITNOT:
                $this->genExpr($node->lhs);
                Console::out("  not %%rax");
                return;
            case NodeKind::ND_NEG:
                $this->genExpr($node->lhs);
                
                switch ($node->ty->kind) {
                    case TypeKind::TY_FLOAT:
                        Console::out("  mov \$1, %%rax");
                        Console::out("  shl \$31, %%rax");
                        Console::out("  movq %%rax, %%xmm1");
                        Console::out("  xorps %%xmm1, %%xmm0");
                        return;
                    case TypeKind::TY_DOUBLE:
                        Console::out("  mov \$1, %%rax");
                        Console::out("  shl \$63, %%rax");
                        Console::out("  movq %%rax, %%xmm1");
                        Console::out("  xorpd %%xmm1, %%xmm0");
                        return;
                    case TypeKind::TY_LDOUBLE:
                        Console::out("  fchs");
                        return;
                }
                
                Console::out("  neg %%rax");
                return;
            case NodeKind::ND_LOGAND:
                $c = $this->cnt();
                $this->genExpr($node->lhs);
                $this->cmpZero($node->lhs->ty);
                Console::out("  je .L.false.%d", $c);
                $this->genExpr($node->rhs);
                $this->cmpZero($node->rhs->ty);
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
                $this->cmpZero($node->lhs->ty);
                Console::out("  jne .L.true.%d", $c);
                $this->genExpr($node->rhs);
                $this->cmpZero($node->rhs->ty);
                Console::out("  jne .L.true.%d", $c);
                Console::out("  mov \$0, %%rax");
                Console::out("  jmp .L.end.%d", $c);
                Console::out(".L.true.%d:", $c);
                Console::out("  mov \$1, %%rax");
                Console::out(".L.end.%d:", $c);
                return;
            case NodeKind::ND_LABEL_VAL:
                Console::out("  lea %s(%%rip), %%rax", $node->uniqueLabel);
                return;
            case NodeKind::ND_CAS:
                $this->genExpr($node->casAddr);
                $this->push();
                $this->genExpr($node->casNew);
                $this->push();
                $this->genExpr($node->casOld);
                Console::out("  mov %%rax, %%r8");
                $this->load($node->casOld->ty->base);
                $this->pop("%rdx"); // new
                $this->pop("%rdi"); // addr

                $sz = $node->casAddr->ty->base->size;
                Console::out("  lock cmpxchg %s, (%%rdi)", $this->regDx($sz));
                Console::out("  sete %%cl");
                Console::out("  je 1f");
                Console::out("  mov %s, (%%r8)", $this->regAx($sz));
                Console::out("1:");
                Console::out("  movzbl %%cl, %%eax");
                return;
            case NodeKind::ND_EXCH:
                $this->genExpr($node->lhs);
                $this->push();
                $this->genExpr($node->rhs);
                $this->pop("%rdi");

                $sz = $node->lhs->ty->base->size;
                Console::out("  xchg %s, (%%rdi)", $this->regAx($sz));
                return;
            case NodeKind::ND_FUNCALL:
                // Handle alloca() as builtin function
                if ($node->lhs->kind === NodeKind::ND_VAR && $node->lhs->var->name === 'alloca') {
                    $this->genExpr($node->args[0]);
                    Console::out("  mov %%rax, %%rdi");
                    $this->builtinAlloca();
                    return;
                }
                
                $stackArgs = $this->pushArgs($node);
                $this->genExpr($node->lhs);

                $gp = 0;
                $fp = 0;

                // If the return type is a large struct/union, the caller passes
                // a pointer to a buffer as if it were the first argument.
                if ($node->retBuffer && $node->ty->size > 16) {
                    $this->pop($this->argreg64[$gp++]);
                }

                foreach ($node->args as $arg) {
                    $ty = $arg->ty;

                    switch ($ty->kind) {
                        case TypeKind::TY_STRUCT:
                        case TypeKind::TY_UNION:
                            if ($ty->size > 16) {
                                break;
                            }

                            $fp1 = $this->hasFlonum1($ty);
                            $fp2 = $this->hasFlonum2($ty);

                            if ($fp + ($fp1 ? 1 : 0) + ($fp2 ? 1 : 0) < self::FP_MAX && 
                                $gp + ($fp1 ? 0 : 1) + ($fp2 ? 0 : 1) < self::GP_MAX) {
                                if ($fp1) {
                                    $this->popf($fp++);
                                } else {
                                    $this->pop($this->argreg64[$gp++]);
                                }

                                if ($ty->size > 8) {
                                    if ($fp2) {
                                        $this->popf($fp++);
                                    } else {
                                        $this->pop($this->argreg64[$gp++]);
                                    }
                                }
                            }
                            break;
                        case TypeKind::TY_FLOAT:
                        case TypeKind::TY_DOUBLE:
                            if ($fp < self::FP_MAX) {
                                $this->popf($fp++);
                            }
                            break;
                        case TypeKind::TY_LDOUBLE:
                            break;
                        default:
                            if ($gp < self::GP_MAX) {
                                $this->pop($this->argreg64[$gp++]);
                            }
                    }
                }

                Console::out("  mov %%rax, %%r10");
                Console::out("  mov $%d, %%rax", $fp);
                Console::out("  call *%%r10");
                Console::out("  add $%d, %%rsp", $stackArgs * 8);

                $this->depth -= $stackArgs;

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

                // If the return type is a small struct, a value is returned
                // using up to two registers.
                if ($node->retBuffer && $node->ty->size <= 16) {
                    $this->copyRetBuffer($node->retBuffer);
                    Console::out("  lea %d(%%rbp), %%rax", $node->retBuffer->offset);
                }

                return;
        }

        switch ($node->lhs->ty->kind) {
            case TypeKind::TY_FLOAT:
            case TypeKind::TY_DOUBLE:
                $this->genExpr($node->rhs);
                $this->pushf();
                $this->genExpr($node->lhs);
                $this->popf(1);

                $sz = ($node->lhs->ty->kind === TypeKind::TY_FLOAT) ? 'ss' : 'sd';

                switch ($node->kind) {
                    case NodeKind::ND_ADD:
                        Console::out("  add%s %%xmm1, %%xmm0", $sz);
                        return;
                    case NodeKind::ND_SUB:
                        Console::out("  sub%s %%xmm1, %%xmm0", $sz);
                        return;
                    case NodeKind::ND_MUL:
                        Console::out("  mul%s %%xmm1, %%xmm0", $sz);
                        return;
                    case NodeKind::ND_DIV:
                        Console::out("  div%s %%xmm1, %%xmm0", $sz);
                        return;
                    case NodeKind::ND_EQ:
                    case NodeKind::ND_NE:
                    case NodeKind::ND_LT:
                    case NodeKind::ND_LE:
                        Console::out("  ucomi%s %%xmm0, %%xmm1", $sz);

                        if ($node->kind === NodeKind::ND_EQ) {
                            Console::out("  sete %%al");
                            Console::out("  setnp %%dl");
                            Console::out("  and %%dl, %%al");
                        } elseif ($node->kind === NodeKind::ND_NE) {
                            Console::out("  setne %%al");
                            Console::out("  setp %%dl");
                            Console::out("  or %%dl, %%al");
                        } elseif ($node->kind === NodeKind::ND_LT) {
                            Console::out("  seta %%al");
                        } else {
                            Console::out("  setae %%al");
                        }

                        Console::out("  and $1, %%al");
                        Console::out("  movzb %%al, %%rax");
                        return;
                }

                Console::errorTok($node->tok, 'invalid expression');
                break;
            case TypeKind::TY_LDOUBLE:
                $this->genExpr($node->lhs);
                $this->genExpr($node->rhs);

                switch ($node->kind) {
                    case NodeKind::ND_ADD:
                        Console::out("  faddp");
                        return;
                    case NodeKind::ND_SUB:
                        Console::out("  fsubrp");
                        return;
                    case NodeKind::ND_MUL:
                        Console::out("  fmulp");
                        return;
                    case NodeKind::ND_DIV:
                        Console::out("  fdivrp");
                        return;
                    case NodeKind::ND_EQ:
                    case NodeKind::ND_NE:
                    case NodeKind::ND_LT:
                    case NodeKind::ND_LE:
                        Console::out("  fcomip");
                        Console::out("  fstp %%st(0)");

                        if ($node->kind === NodeKind::ND_EQ) {
                            Console::out("  sete %%al");
                        } elseif ($node->kind === NodeKind::ND_NE) {
                            Console::out("  setne %%al");
                        } elseif ($node->kind === NodeKind::ND_LT) {
                            Console::out("  seta %%al");
                        } else {
                            Console::out("  setae %%al");
                        }

                        Console::out("  movzb %%al, %%rax");
                        return;
                }

                Console::errorTok($node->tok, 'invalid expression');
                break;
        }

        $this->genExpr($node->rhs);
        $this->push();
        $this->genExpr($node->lhs);
        $this->pop('%rdi');

        if ($node->lhs->ty->kind === TypeKind::TY_LONG or $node->lhs->ty->base or
            $node->rhs->ty->kind === TypeKind::TY_LONG or $node->rhs->ty->base){
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
        Console::out("  .loc %d %d", $node->tok->file->fileNo, $node->tok->lineNo);

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind){
            case NodeKind::ND_IF: {
                $c = $this->cnt();
                $this->genExpr($node->cond);
                $this->cmpZero($node->cond->ty);
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
                    $this->cmpZero($node->cond->ty);
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
                $this->cmpZero($node->cond->ty);
                Console::out("  jne .L.begin.%d", $c);
                Console::out("%s:", $node->brkLabel);
                return;
            case NodeKind::ND_SWITCH:
                $this->genExpr($node->cond);

                foreach ($node->cases as $n){
                    $ax = ($node->cond->ty->size == 8) ? '%rax' : '%eax';
                    $di = ($node->cond->ty->size == 8) ? '%rdi' : '%edi';

                    if ($n->begin == $n->end) {
                        Console::out("  cmp \$%ld, %s", $n->begin, $ax);
                        Console::out("  je %s", $n->label);
                        continue;
                    }

                    // GNU case ranges
                    Console::out("  mov %s, %s", $ax, $di);
                    Console::out("  sub \$%ld, %s", $n->begin, $di);
                    Console::out("  cmp \$%ld, %s", $n->end - $n->begin, $di);
                    Console::out("  jbe %s", $n->label);
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
            case NodeKind::ND_GOTO_EXPR:
                $this->genExpr($node->lhs);
                Console::out("  jmp *%%rax");
                return;
            case NodeKind::ND_LABEL:
                Console::out("%s:", $node->uniqueLabel);
                // Also emit the original label name for labels-as-values
                if ($node->label) {
                    Console::out("%s:", $node->label);
                }
                $this->genStmt($node->lhs);
                return;
            case NodeKind::ND_RETURN:
                if ($node->lhs){
                    $this->genExpr($node->lhs);

                    $ty = $node->lhs->ty;
                    if ($ty->kind === TypeKind::TY_STRUCT || $ty->kind === TypeKind::TY_UNION) {
                        if ($ty->size <= 16) {
                            $this->copyStructReg();
                        } else {
                            $this->copyStructMem();
                        }
                    }
                }
                Console::out("  jmp .L.return.%s", $this->currentFn->name);
                return;
            case NodeKind::ND_EXPR_STMT:
                $this->genExpr($node->lhs);
                return;
            case NodeKind::ND_ASM:
                Console::out("  %s", $node->asmStr);
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

            // If a function has many parameters, some parameters are
            // inevitably passed by stack rather than by register.
            // The first passed-by-stack parameter resides at RBP+16.
            $top = 16;
            $bottom = 0;

            $gp = 0;
            $fp = 0;

            // Assign offsets to pass-by-stack parameters.
            if (isset($fn->params)) {
                foreach ($fn->params as $var) {
                    $ty = $var->ty;

                    switch ($ty->kind) {
                        case TypeKind::TY_STRUCT:
                        case TypeKind::TY_UNION:
                            if ($ty->size <= 16) {
                                $fp1 = $this->hasFlonum($ty, 0, 8, 0);
                                $fp2 = $this->hasFlonum($ty, 8, 16, 8);
                                if ($fp + ($fp1 ? 1 : 0) + ($fp2 ? 1 : 0) < self::FP_MAX && $gp + ($fp1 ? 0 : 1) + ($fp2 ? 0 : 1) < self::GP_MAX) {
                                    $fp = $fp + ($fp1 ? 1 : 0) + ($fp2 ? 1 : 0);
                                    $gp = $gp + ($fp1 ? 0 : 1) + ($fp2 ? 0 : 1);
                                    continue 2;
                                }
                            }
                            break;
                        case TypeKind::TY_FLOAT:
                        case TypeKind::TY_DOUBLE:
                            if ($fp++ < self::FP_MAX) {
                                continue 2;
                            }
                            break;
                        case TypeKind::TY_LDOUBLE:
                            break;
                        default:
                            if ($gp++ < self::GP_MAX) {
                                continue 2;
                            }
                    }

                    $top = Align::alignTo($top, 8);
                    $var->offset = $top;
                    $top += $var->ty->size;
                }
            }

            // Assign offsets to pass-by-register parameters and local variables.
            foreach (array_reverse($fn->locals) as $var) {
                if (isset($var->offset) && $var->offset) {
                    continue;
                }

                // AMD64 System V ABI has a special alignment rule for an array of
                // length at least 16 bytes. We need to align such array to at least
                // 16-byte boundaries. See p.14 of
                // https://github.com/hjl-tools/x86-psABI/wiki/x86-64-psABI-draft.pdf.
                $align = ($var->ty->kind === TypeKind::TY_ARRAY && $var->ty->size >= 16)
                    ? max(16, $var->align) : $var->align;

                $bottom += $var->ty->size;
                $bottom = Align::alignTo($bottom, $align);
                $var->offset = -1 * $bottom;
            }

            $fn->stackSize = Align::alignTo($bottom, 16);
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
            
            $align = ($var->ty->kind === TypeKind::TY_ARRAY && $var->ty->size >= 16)
                ? max(16, $var->align) : $var->align;

            if (Pcc::getOptFcommon() && $var->isTentative) {
                Console::out("  .comm %s, %d, %d", $var->name, $var->ty->size, $align);
                continue;
            }

            // .data or .tdata
            if (! is_null($var->initData)){
                if ($var->isTls) {
                    Console::out("  .section .tdata,\"awT\",@progbits");
                } else {
                    Console::out("  .data");
                }

                Console::out("  .type %s, @object", $var->name);
                Console::out("  .size %s, %d", $var->name, $var->ty->size);
                Console::out("  .align %d", $align);
                Console::out("%s:", $var->name);

                $pos = 0;
                $relIdx = 0;
                while ($pos < $var->ty->size){
                    $rel = $var->rels[$relIdx] ?? null;
                    if ($rel and $rel->offset === $pos){
                        Console::out("  .quad %s%+ld", $rel->label[0], $rel->addend);
                        $relIdx++;
                        $pos += 8;
                    } else {
                        Console::out("  .byte %d", ord($var->initData[$pos++]));
                    }
                }
                continue;
            }

            // .bss or .tbss
            if ($var->isTls) {
                Console::out("  .section .tbss,\"awT\",@nobits");
            } else {
                Console::out("  .bss");
            }

            Console::out("  .align %d", $align);
            Console::out("%s:", $var->name);
            Console::out("  .zero %d", $var->ty->size);
        }
    }

    public function storeFp(int $r, int $offset, int $sz): void
    {
        switch($sz){
            case 4:
                Console::out("  movss %%xmm%d, %d(%%rbp)", $r, $offset);
                return;
            case 8:
                Console::out("  movsd %%xmm%d, %d(%%rbp)", $r, $offset);
                return;
        }
        Console::unreachable(__FILE__, __LINE__);
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
            default:
                for ($i = 0; $i < $sz; $i++) {
                    Console::out("  mov %s, %d(%%rbp)", $this->argreg8[$r], $offset + $i);
                    Console::out("  shr $8, %s", $this->argreg64[$r]);
                }
                return;
        }
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

            // No code is emitted for "static inline" functions
            // if no one is referencing them.
            if (!$fn->isLive) {
                continue;
            }

            if ($fn->isStatic){
                Console::out("  .local %s", $fn->name);
            } else {
                Console::out("  .globl %s", $fn->name);
            }

            Console::out("  .text");
            Console::out("  .type %s, @function", $fn->name);
            Console::out("%s:", $fn->name);
            $this->currentFn = $fn;

            // Prologue
            Console::out("  push %%rbp");
            Console::out("  mov %%rsp, %%rbp");
            Console::out("  sub \$%d, %%rsp", $fn->stackSize);
            Console::out("  mov %%rsp, %d(%%rbp)", $fn->allocaBottom->offset);

            // Save arg registers if function is variadic
            if ($fn->vaArea) {
                $gp = 0;
                $fp = 0;
                foreach ($fn->params as $var) {
                    if ($var->ty->isFlonum()) {
                        $fp++;
                    } else {
                        $gp++;
                    }
                }

                $off = $fn->vaArea->offset;

                // va_elem
                Console::out("  movl $%d, %d(%%rbp)", $gp * 8, $off);          // gp_offset
                Console::out("  movl $%d, %d(%%rbp)", $fp * 8 + 48, $off + 4); // fp_offset
                Console::out("  movq %%rbp, %d(%%rbp)", $off + 8);            // overflow_arg_area
                Console::out("  addq $16, %d(%%rbp)", $off + 8);
                Console::out("  movq %%rbp, %d(%%rbp)", $off + 16);           // reg_save_area
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
            $gp = 0;
            $fp = 0;
            if (isset($fn->params)) {
                foreach ($fn->params as $param){
                    if (isset($param->offset) && $param->offset > 0) {
                        continue;
                    }

                    $ty = $param->ty;

                    switch ($ty->kind) {
                        case TypeKind::TY_STRUCT:
                        case TypeKind::TY_UNION:
                            assert($ty->size <= 16);
                            if ($this->hasFlonum($ty, 0, 8, 0)) {
                                $this->storeFp($fp++, $param->offset, min(8, $ty->size));
                            } else {
                                $this->storeGp($gp++, $param->offset, min(8, $ty->size));
                            }

                            if ($ty->size > 8) {
                                if ($this->hasFlonum($ty, 8, 16, 0)) {
                                    $this->storeFp($fp++, $param->offset + 8, $ty->size - 8);
                                } else {
                                    $this->storeGp($gp++, $param->offset + 8, $ty->size - 8);
                                }
                            }
                            break;
                        case TypeKind::TY_FLOAT:
                        case TypeKind::TY_DOUBLE:
                            $this->storeFp($fp++, $param->offset, $param->ty->size);
                            break;
                        default:
                            $this->storeGp($gp++, $param->offset, $param->ty->size);
                    }
                }
            }

            // Emit code
            foreach ($fn->body as $node){
                $this->genStmt($node);
            }
            assert($this->depth == 0);

            // [https://www.sigbus.info/n1570#5.1.2.2.3p1] The C spec defines
            // a special rule for the main function. Reaching the end of the
            // main function is equivalent to returning 0, even though the
            // behavior is undefined for the other functions.
            if ($fn->name === 'main') {
                Console::out("  mov \$0, %%rax");
            }

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
        // Output file directives
        $files = \Pcc\Tokenizer\Tokenizer::getInputFiles();
        foreach ($files as $file) {
            Console::out("  .file %d \"%s\"", $file->fileNo, $file->name);
        }
        
        $funcs = $this->assignLVarOffsets($funcs);
        $this->emitData($funcs);
        $this->emitText($funcs);
        Console::out("  .section  .note.GNU-stack,\"\",@progbits");
    }

    private function copyStructReg(): void
    {
        $ty = $this->currentFn->ty->returnTy;
        $gp = 0;
        $fp = 0;

        Console::out("  mov %%rax, %%rdi");

        if ($this->hasFlonum($ty, 0, 8, 0)) {
            assert($ty->size == 4 || 8 <= $ty->size);
            if ($ty->size == 4) {
                Console::out("  movss (%%rdi), %%xmm0");
            } else {
                Console::out("  movsd (%%rdi), %%xmm0");
            }
            $fp++;
        } else {
            Console::out("  mov $0, %%rax");
            for ($i = min(8, $ty->size) - 1; $i >= 0; $i--) {
                Console::out("  shl $8, %%rax");
                Console::out("  mov %d(%%rdi), %%al", $i);
            }
            $gp++;
        }

        if ($ty->size > 8) {
            if ($this->hasFlonum($ty, 8, 16, 0)) {
                assert($ty->size == 12 || $ty->size == 16);
                if ($ty->size == 4) {
                    Console::out("  movss 8(%%rdi), %%xmm%d", $fp);
                } else {
                    Console::out("  movsd 8(%%rdi), %%xmm%d", $fp);
                }
            } else {
                $reg1 = ($gp == 0) ? "%al" : "%dl";
                $reg2 = ($gp == 0) ? "%rax" : "%rdx";
                Console::out("  mov $0, %s", $reg2);
                for ($i = min(16, $ty->size) - 1; $i >= 8; $i--) {
                    Console::out("  shl $8, %s", $reg2);
                    Console::out("  mov %d(%%rdi), %s", $i, $reg1);
                }
            }
        }
    }

    private function copyStructMem(): void
    {
        $ty = $this->currentFn->ty->returnTy;
        $var = $this->currentFn->params[0] ?? null;

        if ($var && isset($var->offset)) {
            Console::out("  mov %d(%%rbp), %%rdi", $var->offset);

            for ($i = 0; $i < $ty->size; $i++) {
                Console::out("  mov %d(%%rax), %%dl", $i);
                Console::out("  mov %%dl, %d(%%rdi)", $i);
            }
        }
    }
}