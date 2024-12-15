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

    /**
     * block
     * @var Node[]
     */
    public array $body = [];

    // Function call
    public ?string $funcname = null;
    /** @var Node[] */
    public array $args = [];

    // Used if kind == ND_VAR
    public LVar $var;
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

    public static function newVar(LVar $var, Token $tok): Node
    {
        $node = self::newNode(NodeKind::ND_VAR, $tok);
        $node->var = $var;
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

        switch ($this->kind){
            case NodeKind::ND_ADD:
            case NodeKind::ND_SUB:
            case NodeKind::ND_MUL:
            case NodeKind::ND_DIV:
            case NodeKind::ND_NEG:
            case NodeKind::ND_ASSIGN:
                $this->ty = $this->lhs->ty;
                return;
            case NodeKind::ND_EQ:
            case NodeKind::ND_NE:
            case NodeKind::ND_LT:
            case NodeKind::ND_LE:
            case NodeKind::ND_NUM:
            case NodeKind::ND_FUNCALL:
                $this->ty = new Type(TypeKind::TY_INT);
                return;
            case NodeKind::ND_VAR:
                $this->ty = $this->var->ty;
                return;
            case NodeKind::ND_ADDR:
                $this->ty = Type::pointerTo($this->lhs->ty);
                return;
            case NodeKind::ND_DEREF:
                if ($this->lhs->ty->kind !== TypeKind::TY_PTR){
                    Console::errorTok($this->tok, 'invalid pointer dereference');
                }
                $this->ty = $this->lhs->ty->base;
                return;
        }
    }
}