<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Tokenizer;

class Parser
{
    public function __construct(
        private readonly Tokenizer $tokenizer,
    )
    {
    }

    public function primary(): Node
    {
        if ($this->tokenizer->consume('(')){
            $node = $this->expr();
            $this->tokenizer->expect(')');
            return $node;
        }

        return Node::newNodeNum($this->tokenizer->expectNumber());
    }

    public function unary(): Node
    {
        if ($this->tokenizer->consume('+')){
            return $this->primary();
        }
        if ($this->tokenizer->consume('-')){
            return Node::newNode(NodeKind::ND_SUB, Node::newNodeNum(0), $this->primary());
        }
        return $this->primary();
    }

    public function mul(): Node
    {
        $node = $this->unary();

        for (;;){
            if ($this->tokenizer->consume('*')){
                $node = Node::newNode(NodeKind::ND_MUL, $node, $this->unary());
            } elseif ($this->tokenizer->consume('/')){
                $node = Node::newNode(NodeKind::ND_DIV, $node, $this->unary());
            } else {
                return $node;
            }
        }
    }

    public function expr(): Node
    {
        $node = $this->mul();

        for (;;){
            if ($this->tokenizer->consume('+')){
                $node = Node::newNode(NodeKind::ND_ADD, $node, $this->mul());
            } elseif ($this->tokenizer->consume('-')){
                $node = Node::newNode(NodeKind::ND_SUB, $node, $this->mul());
            } else {
                return $node;
            }
        }
    }
}