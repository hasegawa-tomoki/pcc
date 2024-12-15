<?php

namespace Pcc\Ast;

use Pcc\Console;
use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Tokenizer\TokenKind;

class Parser
{
    /** @var array<string, \Pcc\Ast\LVar> */
    public array $locals = [];

    public function __construct(
        private readonly Tokenizer $tokenizer,
    )
    {
    }

    public function newLvar(string $name, Type $ty): LVar
    {
        $var = new LVar($name);
        $var->ty = $ty;
        $this->locals[$name] = $var;
        return $var;
    }

    public function getIdent(Token $tok): string
    {
        if ($tok->kind !== TokenKind::TK_IDENT){
            Console::errorTok($tok, 'expected an identifier');
        }
        return $tok->str;
    }

    // declspec = "int"
    public function declspec(): Type
    {
        $this->tokenizer->consume('int');
        return new Type(TypeKind::TY_INT);
    }

    // declarator = "*"* ident
    public function declarator(Type $ty): Type
    {
        while ($this->tokenizer->consume('*')){
            $ty = Type::pointerTo($ty);
        }

        if (! $this->tokenizer->isTokenKind(TokenKind::TK_IDENT)){
            Console::errorTok($this->tokenizer->tokens[0], 'expected a variable name');
        }

        $ty->name = $this->tokenizer->getIdent();
        return $ty;
    }

    // declaration = declspec (declarator ("=" expr)? ("," declarator ("=" expr)?)*)? ";"
    public function declaration(): Node
    {
        $basety = $this->declspec();

        $i = 0;
        $nodes = [];
        while (! $this->tokenizer->equal(';')){
            if ($i++ > 0){
                $this->tokenizer->expect(',');
            }

            $ty = $this->declarator($basety);
            $var = $this->newLvar($this->getIdent($ty->name), $ty);

            if (! $this->tokenizer->consume('=')){
                continue;
            }

            $lhs = Node::newVar($var, $ty->name);
            $rhs = $this->assign();
            $node = Node::newBinary(NodeKind::ND_ASSIGN, $lhs, $rhs, $this->tokenizer->tokens[0]);
            $nodes[] = Node::newUnary(NodeKind::ND_EXPR_STMT, $node, $this->tokenizer->tokens[0]);
        }

        $node = Node::newNode(NodeKind::ND_BLOCK, $this->tokenizer->tokens[0]);
        $node->body = $nodes;
        return $node;
    }

    // stmt = "return" expr ";"
    //        | "if" "(" expr ")" stmt ("else" stmt)?
    //        | "for" "(" expr-stmt expr? ";" expr? ")" stmt
    //        | "while" "(" expr ")" stmt
    //        | "{" compound-stmt
    //        | expr-stmt
    public function stmt(): Node
    {
        if ($this->tokenizer->consume('return')){
            $node = Node::newNode(NodeKind::ND_RETURN, $this->tokenizer->tokens[0]);
            $node->lhs = $this->expr();
            $this->tokenizer->expect(';');
            return $node;
        }

        if ($this->tokenizer->consume('if')){
            $node = Node::newNode(NodeKind::ND_IF, $this->tokenizer->tokens[0]);
            $this->tokenizer->expect('(');
            $node->cond = $this->expr();
            $this->tokenizer->expect(')');
            $node->then = $this->stmt();
            if ($this->tokenizer->consume('else')){
                $node->els = $this->stmt();
            }
            return $node;
        }

        // "for" "(" expr-stmt expr? ";" expr? ")" stmt
        if ($this->tokenizer->consume('for')){
            $node = Node::newNode(NodeKind::ND_FOR, $this->tokenizer->tokens[0]);
            $this->tokenizer->expect('(');
            $node->init = $this->exprStmt();

            if (! $this->tokenizer->consume(';')){
                $node->cond = $this->expr();
                $this->tokenizer->expect(';');
            }

            if (! $this->tokenizer->consume(')')){
                $node->inc = $this->expr();
                $this->tokenizer->expect(')');
            }

            $node->then = $this->stmt();

            return $node;
        }

        // "while" "(" expr ")" stmt
        if ($this->tokenizer->consume('while')){
            $node = Node::newNode(NodeKind::ND_FOR, $this->tokenizer->tokens[0]);
            $this->tokenizer->expect('(');
            $node->cond = $this->expr();
            $this->tokenizer->expect(')');
            $node->then = $this->stmt();
            return $node;
        }

        if ($this->tokenizer->consume('{')){
            return $this->compoundStmt();
        }

        return $this->exprStmt();
    }

    // compound-stmt = (declaration | stmt)* "}"
    public function compoundStmt(): Node
    {
        $node = Node::newNode(NodeKind::ND_BLOCK, $this->tokenizer->tokens[0]);

        $nodes = [];
        while (! $this->tokenizer->consume('}')){
            if ($this->tokenizer->consume('int')){
                $n = $this->declaration();
            } else {
                $n = $this->stmt();
            }
            $n->addType();
            $nodes[] = $n;
        }

        $node->body = $nodes;
        return $node;
    }

    // expr-stmt = expr? ";"
    public function exprStmt(): Node
    {
        if ($this->tokenizer->consume(';')){
            return Node::newNode(NodeKind::ND_BLOCK, $this->tokenizer->tokens[0]);
        }

        $node = Node::newNode(NodeKind::ND_EXPR_STMT, $this->tokenizer->tokens[0]);
        $node->lhs = $this->expr();
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

        if ($this->tokenizer->equal('=')){
            $this->tokenizer->consume('=');
            return Node::newBinary(NodeKind::ND_ASSIGN, $node, $this->assign(), $this->tokenizer->tokens[0]);
        }

        return $node;
    }

    // equality = relational ("==" relational | "!=" relational)*
    public function equality(): Node
    {
        $node = $this->relational();

        for (;;){
            $start = $this->tokenizer->tokens[0];

            if ($this->tokenizer->consume('==')){
                $node = Node::newBinary(NodeKind::ND_EQ, $node, $this->relational(), $start);
                continue;
            }
            if ($this->tokenizer->consume('!=')){
                $node = Node::newBinary(NodeKind::ND_NE, $node, $this->relational(), $start);
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
            $start = $this->tokenizer->tokens[0];

            if ($this->tokenizer->consume('<')){
                $node = Node::newBinary(NodeKind::ND_LT, $node, $this->add(), $start);
                continue;
            }
            if ($this->tokenizer->consume('<=')){
                $node = Node::newBinary(NodeKind::ND_LE, $node, $this->add(), $start);
                continue;
            }
            if ($this->tokenizer->consume('>')){
                $node = Node::newBinary(NodeKind::ND_LT, $this->add(), $node, $start);
                continue;
            }
            if ($this->tokenizer->consume('>=')){
                $node = Node::newBinary(NodeKind::ND_LE, $this->add(), $node, $start);
                continue;
            }

            return $node;
        }
    }

    public function newAdd(Node $lhs, Node $rhs, Token $tok): Node
    {
        $lhs->addType();
        $rhs->addType();

        // num + num
        if ($lhs->ty->isInteger() and $rhs->ty->isInteger()){
            return Node::newBinary(NodeKind::ND_ADD, $lhs, $rhs, $tok);
        }
        if ($lhs->ty->base and $rhs->ty->base){
            Console::errorTok($tok, 'invalid operands');
        }

        // Canonicalize 'num + ptr' to 'ptr + num'.
        if (! $lhs->ty->base and $rhs->ty->base){
            $tmp = $lhs;
            $lhs = $rhs;
            $rhs = $tmp;
        }

        // ptr + num
        $rhs = Node::newBinary(NodeKind::ND_MUL, $rhs, Node::newNum(8, $tok), $tok);
        return Node::newBinary(NodeKind::ND_ADD, $lhs, $rhs, $tok);
    }

    public function newSub(Node $lhs, Node $rhs, Token $tok): Node
    {
        $lhs->addType();
        $rhs->addType();

        // num - num
        if ($lhs->ty->isInteger() and $rhs->ty->isInteger()){
            return Node::newBinary(NodeKind::ND_SUB, $lhs, $rhs, $tok);
        }

        // ptr - num
        if ($lhs->ty->base and $rhs->ty->isInteger()){
            $rhs = Node::newBinary(NodeKind::ND_MUL, $rhs, Node::newNum(8, $tok), $tok);
            $rhs->addType();
            $node = Node::newBinary(NodeKind::ND_SUB, $lhs, $rhs, $tok);
            $node->ty = $lhs->ty;
            return $node;
        }

        // ptr - ptr
        if ($lhs->ty->base and $rhs->ty->base){
            $node = Node::newBinary(NodeKind::ND_SUB, $lhs, $rhs, $tok);
            $node->ty = new Type(TypeKind::TY_INT);
            return Node::newBinary(NodeKind::ND_DIV, $node, Node::newNum(8, $tok), $tok);
        }

        Console::errorTok($tok, 'invalid operands');
    }

    // add = mul ("+" mul | "-" mul)*
    public function add(): Node
    {
        $node = $this->mul();

        for (;;){
            $start = $this->tokenizer->tokens[0];

            if ($this->tokenizer->consume('+')){
                $node = $this->newAdd($node, $this->mul(), $start);
                continue;
            }
            if ($this->tokenizer->consume('-')){
                $node = $this->newSub($node, $this->mul(), $start);
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
            $start = $this->tokenizer->tokens[0];

            if ($this->tokenizer->consume('*')){
                $node = Node::newBinary(NodeKind::ND_MUL, $node, $this->unary(), $start);
                continue;
            }
            if ($this->tokenizer->consume('/')){
                $node = Node::newBinary(NodeKind::ND_DIV, $node, $this->unary(), $start);
                continue;
            }

            return $node;
        }
    }

    // unary = ("+" | "-" | "*" | "&") unary | primary
    public function unary(): Node
    {
        if ($this->tokenizer->consume('+')){
            return $this->unary();
        }
        if ($this->tokenizer->consume('-')){
            return Node::newUnary(NodeKind::ND_NEG, $this->unary(), $this->tokenizer->tokens[0]);
        }
        if ($this->tokenizer->consume('&')){
            return Node::newUnary(NodeKind::ND_ADDR, $this->unary(), $this->tokenizer->tokens[0]);
        }
        if ($this->tokenizer->consume('*')){
            return Node::newUnary(NodeKind::ND_DEREF, $this->unary(), $this->tokenizer->tokens[0]);
        }

        return $this->primary();
    }

    // funcall = ident "(" (assign ("," assign)*)? ")"
    public function funcall(): Node
    {
        $start = $this->tokenizer->tokens[0];

        $funcname = $this->tokenizer->getIdent()->str;
        $this->tokenizer->expect('(');

        $nodes = [];
        while(! $this->tokenizer->consume(')')){
            if (count($nodes) > 0){
                $this->tokenizer->expect(',');
            }
            $nodes[] = $this->assign();
        }

        $node = Node::newNode(NodeKind::ND_FUNCALL, $start);
        $node->funcname = $funcname;
        $node->args = $nodes;
        return $node;
    }

    // primary = "(" expr ")" | ident func-args? | number
    // args = "(" ")"
    public function primary(): Node
    {
        if ($this->tokenizer->consume('(')){
            $node = $this->expr();
            $this->tokenizer->expect(')');
            return $node;
        }

        if ($this->tokenizer->isTokenKind(TokenKind::TK_IDENT)){
            // Function call
            if ($this->tokenizer->equal('(', 1)){
                return $this->funcall();
            }

            // Variable
            $varName = $this->tokenizer->getIdent()->str;
            if (! isset($this->locals[$varName])){
                Console::errorTok($this->tokenizer->tokens[0], 'undefined variable');
            }

            return Node::newVar($this->locals[$varName], $this->tokenizer->tokens[0]);
        }

        if ($this->tokenizer->isTokenKind(TokenKind::TK_NUM)){
            return Node::newNum($this->tokenizer->expectNumber(), $this->tokenizer->tokens[0]);
        }

        Console::errorTok($this->tokenizer->tokens[0], 'expected an expression');
    }

    // program = stmt*
    public function parse(): Func
    {
        $this->tokenizer->expect('{');

        $prog = new Func();
        $prog->body = [$this->compoundStmt()];
        $prog->locals = $this->locals;

        return $prog;
    }
}