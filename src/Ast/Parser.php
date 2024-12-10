<?php

namespace Pcc\Ast;

use Pcc\Console;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Tokenizer\TokenKind;

class Parser
{
    public function __construct(
        private readonly Tokenizer $tokenizer,
    )
    {
    }

    // stmt = expr-stmt
    public function stmt(): Node
    {
        return $this->exprStmt();
    }

    // expr-stmt = expr ";"
    public function exprStmt(): Node
    {
        $node = Node::newUnary(NodeKind::ND_EXPR_STMT, $this->expr());
        $this->tokenizer->expect(';');
        return $node;
    }

    // expr = assign
    public function expr(): Node
    {
        return $this->assign();
    }

    // assign = equality ("=" assign)?
    public function assign(): Node
    {
        $node = $this->equality();
        if ($this->tokenizer->consumable('=')){
            $this->tokenizer->consume('=');
            $node = Node::newBinary(NodeKind::ND_ASSIGN, $node, $this->assign());
        }
        return $node;
    }

    // equality = relational ("==" relational | "!=" relational)*
    public function equality(): Node
    {
        $node = $this->relational();

        for (;;){
            if ($this->tokenizer->consume('==')){
                $node = Node::newBinary(NodeKind::ND_EQ, $node, $this->relational());
                continue;
            }
            if ($this->tokenizer->consume('!=')){
                $node = Node::newBinary(NodeKind::ND_NE, $node, $this->relational());
                continue;
            }

            return $node;
        }
    }

    // relational = add ("<" add | "<=" add | ">" add | ">=" add)*
    public function relational(): Node
    {
        $node = $this->add();

        for (;;){
            if ($this->tokenizer->consume('<')){
                $node = Node::newBinary(NodeKind::ND_LT, $node, $this->add());
                continue;
            }
            if ($this->tokenizer->consume('<=')){
                $node = Node::newBinary(NodeKind::ND_LE, $node, $this->add());
                continue;
            }
            if ($this->tokenizer->consume('>')){
                $node = Node::newBinary(NodeKind::ND_LT, $this->add(), $node);
                continue;
            }
            if ($this->tokenizer->consume('>=')){
                $node = Node::newBinary(NodeKind::ND_LE, $this->add(), $node);
                continue;
            }

            return $node;
        }
    }

    // add = mul ("+" mul | "-" mul)*
    public function add(): Node
    {
        $node = $this->mul();

        for (;;){
            if ($this->tokenizer->consume('+')){
                $node = Node::newBinary(NodeKind::ND_ADD, $node, $this->mul());
                continue;
            }
            if ($this->tokenizer->consume('-')){
                $node = Node::newBinary(NodeKind::ND_SUB, $node, $this->mul());
                continue;
            }

            return $node;
        }
    }

    // mul = unary ("*" unary | "/" unary)*
    public function mul(): Node
    {
        $node = $this->unary();

        for (;;){
            if ($this->tokenizer->consume('*')){
                $node = Node::newBinary(NodeKind::ND_MUL, $node, $this->unary());
                continue;
            }
            if ($this->tokenizer->consume('/')){
                $node = Node::newBinary(NodeKind::ND_DIV, $node, $this->unary());
                continue;
            }

            return $node;
        }
    }

    // unary = ("+" | "-") unary | primary
    public function unary(): Node
    {
        if ($this->tokenizer->consume('+')){
            return $this->unary();
        }
        if ($this->tokenizer->consume('-')){
            return Node::newUnary(NodeKind::ND_NEG, $this->unary());
        }

        return $this->primary();
    }

    // primary = "(" expr ")" | ident | number
    public function primary(): Node
    {
        if ($this->tokenizer->consume('(')){
            $node = $this->expr();
            $this->tokenizer->expect(')');
            return $node;
        }

        if ($this->tokenizer->isTokenKind(TokenKind::TK_IDENT)){
            return Node::newVar($this->tokenizer->consumeIdent()?->str);
        }

        if ($this->tokenizer->isTokenKind(TokenKind::TK_NUM)){
            return Node::newNum($this->tokenizer->expectNumber());
        }

        Console::errorAt($this->tokenizer->userInput, $this->tokenizer->tokens[0]->pos, "数値でも開き括弧でもないトークンです\n");
    }

    /**
     * @return \Pcc\Ast\Node[]
     */
    public function parse(): array
    {
        $nodes = [];
        while (! $this->tokenizer->isTokenKind(TokenKind::TK_EOF)){
            $nodes[] = $this->stmt();
        }

        return $nodes;
    }
}