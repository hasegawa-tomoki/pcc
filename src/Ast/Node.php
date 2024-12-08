<?php

namespace Pcc\Ast;

class Node
{
    public NodeKind $kind;
    public Node $lhs;
    public Node $rhs;
    public int $val;

    public static function newNode(NodeKind $nodeKind, Node $lhs, Node $rhs): Node
    {
        $node = new Node();
        $node->kind = $nodeKind;
        $node->lhs = $lhs;
        $node->rhs = $rhs;
        return $node;
    }

    public static function newNodeNum(int $val): Node
    {
        $node = new Node();
        $node->kind = NodeKind::ND_NUM;
        $node->val = $val;
        return $node;
    }
}