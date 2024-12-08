<?php

namespace Pcc\Ast;

class Node
{
    public NodeKind $kind;
    public Node $lhs;
    public Node $rhs;
    public int $val;

    public static function newNode(NodeKind $nodeKind): Node
    {
        $node = new Node();
        $node->kind = $nodeKind;
        return $node;
    }

    public static function newBinary(NodeKind $nodeKind, Node $lhs, Node $rhs): Node
    {
        $node = self::newNode($nodeKind);
        $node->lhs = $lhs;
        $node->rhs = $rhs;
        return $node;
    }

    public static function newUnary(NodeKind $nodeKind, Node $expr)
    {
        $node = self::newNode($nodeKind);
        $node->lhs = $expr;
        return $node;
    }

    public static function newNum(int $val): Node
    {
        $node = self::newNode(NodeKind::ND_NUM);
        $node->val = $val;
        return $node;
    }
}