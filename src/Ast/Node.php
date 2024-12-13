<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Node
{
    public NodeKind $kind;
    public Token $tok;

    public Node $lhs;
    public Node $rhs;

    // "if" or $for" statement
    public ?Node $cond = null;
    public Node $then;
    public ?Node $els = null;
    public ?Node $init = null;
    public ?Node $inc = null;

    /**
     * block
     * @var Node[]
     */
    public array $body = [];

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

    public static function newUnary(NodeKind $nodeKind, Node $expr, Token $tok)
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
}