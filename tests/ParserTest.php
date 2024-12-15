<?php

use Pcc\Ast\NodeKind;
use Pcc\Ast\TypeKind;
use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public function testPrimaryNumber()
    {
        $tokenizer = new Tokenizer('5');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->primary();
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->kind);
        $this->assertEquals(5, $node->val);
    }

    public function testPrimaryWithBrackets()
    {
        $tokenizer = new Tokenizer('(5)');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->primary();
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->kind);
        $this->assertEquals(5, $node->val);

        $tokenizer = new Tokenizer('(5 + 2)');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->primary();
        $this->assertEquals(Pcc\Ast\NodeKind::ND_ADD, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);
    }

    public function testMul()
    {
        $tokenizer = new Tokenizer('5 * 2');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->expr();
        $this->assertEquals(Pcc\Ast\NodeKind::ND_MUL, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);

        $tokenizer = new Tokenizer('5 / 2');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->expr();
        $this->assertEquals(Pcc\Ast\NodeKind::ND_DIV, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);
    }

    public function testExpr()
    {
        $tokenizer = new Tokenizer('5 + 2');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->expr();
        $this->assertEquals(Pcc\Ast\NodeKind::ND_ADD, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);
    }

    public function testExprWithNegativeValue()
    {
        $tokenizer = new Tokenizer('-10 + 20');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $node = $parser->expr();

        $this->assertEquals(Pcc\Ast\NodeKind::ND_ADD, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NEG, $node->lhs->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->lhs->kind);
        $this->assertEquals(10, $node->lhs->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(20, $node->rhs->val);
    }

    public function testDeclaration()
    {
        $tokenizer = new Tokenizer('int a=10;');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $declaration = $parser->declaration();

        $this->assertEquals(Pcc\Ast\NodeKind::ND_ASSIGN, $declaration->body[0]->lhs->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_VAR, $declaration->body[0]->lhs->lhs->kind);
        $this->assertEquals('a', $declaration->body[0]->lhs->lhs->var->name);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $declaration->body[0]->lhs->rhs->kind);
        $this->assertEquals(10, $declaration->body[0]->lhs->rhs->val);
    }

    public function testBlock()
    {
        $tokenizer = new Tokenizer('{ {1; {2;} return 3;} }');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_BLOCK, $prog->body[0]->kind);
        $this->assertEquals(NodeKind::ND_BLOCK, $prog->body[0]->body[0]->kind);
    }

    public function testPointer()
    {
        $tokenizer = new Tokenizer('{ int x=3; int y=5; return *(&x+1); }');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        ray($prog);

        $this->assertEquals(NodeKind::ND_ADD, $prog->body[0]->body[4]->lhs->lhs->kind);
        $this->assertEquals(NodeKind::ND_VAR, $prog->body[0]->body[4]->lhs->lhs->lhs->lhs->kind);
        $this->assertEquals('x', $prog->body[0]->body[4]->lhs->lhs->lhs->lhs->var->name);
        $this->assertEquals(TypeKind::TY_PTR, $prog->body[0]->body[4]->lhs->lhs->lhs->ty->kind);
        $this->assertEquals(NodeKind::ND_MUL, $prog->body[0]->body[4]->lhs->lhs->rhs->kind);
        $this->assertEquals(1, $prog->body[0]->body[4]->lhs->lhs->rhs->lhs->val);
        $this->assertEquals(8, $prog->body[0]->body[4]->lhs->lhs->rhs->rhs->val);
    }

    public function testVariableDefinition()
    {
        $tokenizer = new Tokenizer('{ int x=3; }');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ASSIGN, $prog->body[0]->body[0]->body[0]->lhs->kind);
        $this->assertEquals('x', $prog->body[0]->body[0]->body[0]->lhs->lhs->var->name);
        $this->assertEquals(3, $prog->body[0]->body[0]->body[0]->lhs->rhs->val);
    }

    public function testZeroArityFunctionCall()
    {
        $tokenizer = new Tokenizer('{ return ret3(); }');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_FUNCALL, $prog->body[0]->body[0]->lhs->kind);
        $this->assertEquals('ret3', $prog->body[0]->body[0]->lhs->funcname);
    }
}
