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

    public function mul(): Node
    {
        $node = $this->primary();

        for (;;){
            if ($this->tokenizer->consume('*')){
                $node = Node::newNode(NodeKind::ND_MUL, $node, $this->primary());
            } elseif ($this->tokenizer->consume('/')){
                $node = Node::newNode(NodeKind::ND_DIV, $node, $this->primary());
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