<?php

namespace Pcc\Ast;

use GMP;
use Pcc\Ast\Type\PccGMP;
use Pcc\Console;
use Pcc\Tokenizer\Token;

class Node
{
    public NodeKind $kind;

    public ?Type $ty = null;
    public Token $tok;

    public ?Node $lhs = null;
    public ?Node $rhs = null;

    // "if" or $for" statement
    public ?Node $cond = null;
    public ?Node $then = null;
    public ?Node $els = null;
    public ?Node $init = null;
    public ?Node $inc = null;

    // "break" and "continue" labels
    public ?string $brkLabel = null;
    public ?string $contLabel = null;

    /**
     * Block or statement expression
     * @var Node[]
     */
    public array $body = [];

    /** @var \Pcc\Ast\Member[] */
    public array $members = [];

    // Function call
    public Type $funcTy;
    /** @var Node[] */
    public array $args = [];
    public bool $passByStack = false;
    public ?Obj $retBuffer = null;

    // Goto or labeled statement
    public ?string $label = null;
    public ?string $uniqueLabel = null;

    // Switch-cases
    /** @var \Pcc\Ast\Node[] */
    public array $cases = [];
    public ?Node $defaultCase = null;

    // "asm" string literal
    public ?string $asmStr = null;

    // Variable
    public Obj $var;
    // Member access
    public Member $member;
    // Numeric literal
    public int $val;
    public GMP $gmpVal;
    public float $fval;

    public static function newNode(NodeKind $nodeKind, Token $tok): Node
    {
        $node = new Node();
        $node->kind = $nodeKind;
        $node->tok = $tok;
        return $node;
    }

    public static function newNullExpr(Token $tok): Node
    {
        return self::newNode(NodeKind::ND_NULL_EXPR, $tok);
    }

    public static function newBinary(NodeKind $nodeKind, Node $lhs, Node $rhs, Token $tok): Node
    {
        $node = self::newNode($nodeKind, $tok);
        $node->lhs = $lhs;
        $node->rhs = $rhs;
        return $node;
    }

    public static function newUnary(NodeKind $nodeKind, Node $expr, Token $tok): Node
    {
        $node = self::newNode($nodeKind, $tok);
        $node->lhs = $expr;
        return $node;
    }

    public static function newNum(int|GMP $val, Token $tok): Node
    {
        $node = self::newNode(NodeKind::ND_NUM, $tok);
        if ($val instanceof GMP){
            $node->gmpVal = $val;
            $node->val = PccGMP::toPHPInt($val);
        } else {
            $node->gmpVal = gmp_init($val);
            $node->val = $val;
        }
        return $node;
    }

    public static function newLong(int|GMP $val, Token $tok): Node
    {
        $node = self::newNum($val, $tok);
        $node->ty = Type::tyLong();
        return $node;
    }

    public static function newUlong(int|GMP $val, Token $tok): Node
    {
        $node = self::newNum($val, $tok);
        $node->ty = Type::tyULong();
        return $node;
    }

    public static function newVarNode(Obj $var, Token $tok): Node
    {
        $node = self::newNode(NodeKind::ND_VAR, $tok);
        $node->var = $var;
        return $node;
    }

    public static function newVar(Obj $var, Token $tok): Node
    {
        return self::newVarNode($var, $tok);
    }

    public static function newVlaPtr(Obj $var, Token $tok): Node
    {
        $node = self::newNode(NodeKind::ND_VLA_PTR, $tok);
        $node->var = $var;
        return $node;
    }

    public static function newCast(Node $expr, Type $ty): Node
    {
        $expr->addType();

        $node = new Node();
        $node->kind = NodeKind::ND_CAST;
        $node->tok = $expr->tok;
        $node->lhs = $expr;
        $node->ty = clone $ty;
        return $node;
    }

    public function addType(): void
    {
        if ($this->ty){
            return;
        }

        $this->lhs?->addType();
        $this->rhs?->addType();
        $this->cond?->addType();
        $this->then?->addType();
        $this->els?->addType();
        $this->init?->addType();
        $this->inc?->addType();

        foreach ($this->body as $node){
            $node->addType();
        }
        foreach ($this->args as $node){
            $node->addType();
        }

        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($this->kind){
            case NodeKind::ND_NUM:
                if (!$this->ty) {
                    $this->ty = Type::tyInt();
                }
                return;
            case NodeKind::ND_ADD:
            case NodeKind::ND_SUB:
            case NodeKind::ND_MUL:
            case NodeKind::ND_DIV:
            case NodeKind::ND_MOD:
            case NodeKind::ND_BITAND:
            case NodeKind::ND_BITOR:
            case NodeKind::ND_BITXOR:
                [$this->lhs, $this->rhs] = Type::usualArithConv($this->lhs, $this->rhs);
                $this->ty = $this->lhs->ty;
                return;
            case NodeKind::ND_ASSIGN:
                if ($this->lhs->ty->kind === TypeKind::TY_ARRAY){
                    Console::errorTok($this->lhs->tok, 'not an lvalue');
                }
                if ($this->lhs->ty->kind !== TypeKind::TY_STRUCT){
                    $this->rhs = Node::newCast($this->rhs, $this->lhs->ty);
                }
                $this->ty = $this->lhs->ty;
                return;
            case NodeKind::ND_EQ:
            case NodeKind::ND_NE:
            case NodeKind::ND_LT:
            case NodeKind::ND_LE:
                [$this->lhs, $this->rhs] = Type::usualArithConv($this->lhs, $this->rhs);
                $this->ty = Type::tyInt();
                return;
            case NodeKind::ND_FUNCALL:
                $this->ty = $this->funcTy->returnTy;
                return;
            case NodeKind::ND_NOT:
            case NodeKind::ND_LOGAND:
            case NodeKind::ND_LOGOR:
                $this->ty = Type::tyInt();
                return;
            case NodeKind::ND_BITNOT:
            case NodeKind::ND_NEG:
            case NodeKind::ND_SHL:
            case NodeKind::ND_SHR:
                $this->ty = $this->lhs->ty;
                return;
            case NodeKind::ND_VAR:
            case NodeKind::ND_VLA_PTR:
                $this->ty = $this->var->ty;
                return;
            case NodeKind::ND_COND:
                if ($this->then->ty->kind === TypeKind::TY_VOID or $this->els->ty->kind === TypeKind::TY_VOID){
                    $this->ty = Type::tyVoid();
                } else {
                    [$this->then, $this->els] = Type::usualArithConv($this->then, $this->els);
                    $this->ty = $this->then->ty;
                }
                return;
            case NodeKind::ND_COMMA:
                $this->ty = $this->rhs->ty;
                return;
            case NodeKind::ND_MEMBER:
                $this->ty = $this->member->ty;
                return;
            case NodeKind::ND_ADDR: {
                $ty = $this->lhs->ty;
                if ($ty->kind === TypeKind::TY_ARRAY) {
                    $this->ty = Type::pointerTo($ty->base);
                } else {
                    $this->ty = Type::pointerTo($ty);
                }
                return;
            }
            case NodeKind::ND_DEREF:
                if (! $this->lhs->ty->base){
                    Console::errorTok($this->tok, 'invalid pointer dereference');
                }
                if ($this->lhs->ty->base->kind === TypeKind::TY_VOID){
                    Console::errorTok($this->tok, 'dereferencing a void pointer');
                }
                $this->ty = $this->lhs->ty->base;
                return;
            case NodeKind::ND_STMT_EXPR:
                if ($this->body){
                    $stmt = end($this->body);
                    if ($stmt->kind === NodeKind::ND_EXPR_STMT){
                        $this->ty = $stmt->lhs->ty;
                        return;
                    }
                }
                Console::errorTok($this->tok, 'statement expression returning void is not supported');
        }
    }
}