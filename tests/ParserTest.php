<?php

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

    public function testAssign()
    {
        $tokenizer = new Tokenizer('a=10');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $expr = $parser->assign();

        $this->assertEquals(Pcc\Ast\NodeKind::ND_ASSIGN, $expr->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_VAR, $expr->lhs->kind);
        $this->assertEquals('a', $expr->lhs->var->name);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $expr->rhs->kind);
        $this->assertEquals(10, $expr->rhs->val);
    }

    public function testBlock()
    {
        $tokenizer = new Tokenizer('{ {1; {2;} return 3;} }');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(\Pcc\Ast\NodeKind::ND_BLOCK, $prog->body[0]->kind);
        $this->assertEquals(\Pcc\Ast\NodeKind::ND_BLOCK, $prog->body[0]->body[0]->kind);
    }

    public function testPointer()
    {
        $tokenizer = new Tokenizer('{ x=3; y=5; return *(&x+1); }');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(\Pcc\Ast\NodeKind::ND_ADD, $prog->body[0]->body[2]->lhs->lhs->kind);
        $this->assertEquals(\Pcc\Ast\NodeKind::ND_VAR, $prog->body[0]->body[2]->lhs->lhs->lhs->lhs->kind);
        $this->assertEquals('x', $prog->body[0]->body[2]->lhs->lhs->lhs->lhs->var->name);
        $this->assertEquals(\Pcc\Ast\TypeKind::TY_PTR, $prog->body[0]->body[2]->lhs->lhs->lhs->ty->kind);
        $this->assertEquals(\Pcc\Ast\NodeKind::ND_MUL, $prog->body[0]->body[2]->lhs->lhs->rhs->kind);
        $this->assertEquals(1, $prog->body[0]->body[2]->lhs->lhs->rhs->lhs->val);
        $this->assertEquals(8, $prog->body[0]->body[2]->lhs->lhs->rhs->rhs->val);
    }
}
