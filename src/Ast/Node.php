<?php

namespace Pcc\Ast;

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

    public ?Member $member = null;

    // Function call
    public ?string $funcname = null;
    public Type $funcTy;
    /** @var Node[] */
    public array $args = [];

    // Goto or labeled statement
    public ?string $label = null;
    public ?string $uniqueLabel = null;

    // Used if kind == ND_VAR
    public Obj $var;
    // Used if kind == ND_NUM
    public int $val;

    public static function newNode(NodeKind $nodeKind, Token $tok): Node
    {
        $node = new Node();
        $node->kind = $nodeKind;
        $node->tok = $tok;
        return $node;
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

    public static function newNum(int $val, Token $tok): Node
    {
        $node = self::newNode(NodeKind::ND_NUM, $tok);
        $node->val = $val;
        return $node;
    }

    public static function newVarNode(Obj $var, Token $tok): Node
    {
        $node = self::newNode(NodeKind::ND_VAR, $tok);
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
        $node->ty = $ty;
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
                $this->ty = ($this->val > 2 ** 32) ? Type::tyLong() : Type::tyInt();
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
                $this->ty = Type::tyLong();
                return;
            case NodeKind::ND_NOT:
            case NodeKind::ND_LOGAND:
            case NodeKind::ND_LOGOR:
                $this->ty = Type::tyInt();
                return;
            case NodeKind::ND_BITNOT:
                $this->ty = $this->lhs->ty;
                return;
            case NodeKind::ND_VAR:
                $this->ty = $this->var->ty;
                return;
            case NodeKind::ND_COMMA:
                $this->ty = $this->rhs->ty;
                return;
            case NodeKind::ND_MEMBER:
                $this->ty = $this->member->ty;
                return;
            case NodeKind::ND_ADDR:
                if ($this->lhs->ty->kind === TypeKind::TY_ARRAY){
                    $this->ty = Type::pointerTo($this->lhs->ty->base);
                } else {
                    $this->ty = Type::pointerTo($this->lhs->ty);
                }
                return;
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