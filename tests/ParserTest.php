<?php

use Pcc\Ast\NodeKind;
use Pcc\Ast\Type;
use Pcc\Ast\TypeKind;
use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public function testUnary()
    {
        file_put_contents('tmp.c', '5');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->unary($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->kind);
        $this->assertEquals(5, $node->val);

        file_put_contents('tmp.c', '-5');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->unary($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_SUB, $node->kind);
        $this->assertEquals(0, $node->lhs->val);
        $this->assertEquals(5, $node->rhs->val);
    }

    public function testMul()
    {
        file_put_contents('tmp.c', '5 * 2');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->mul($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_MUL, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);

        file_put_contents('tmp.c', '5 / 2');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->mul($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_DIV, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);
    }

    public function testPrimaryWithBrackets()
    {
        file_put_contents('tmp.c', '(5)');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->primary($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->kind);
        $this->assertEquals(5, $node->val);

        file_put_contents('tmp.c', '(5 + 2)');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->primary($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_ADD, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);
    }

    public function testExpr()
    {
        file_put_contents('tmp.c', '5 + 2');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->expr($tokenizer->tok, $tokenizer->tok);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_ADD, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->kind);
        $this->assertEquals(5, $node->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(2, $node->rhs->val);
    }

    public function testExprWithNegativeValue()
    {
        file_put_contents('tmp.c', '-10 + 20');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$node, $tok] = $parser->expr($tokenizer->tok, $tokenizer->tok);

        $this->assertEquals(Pcc\Ast\NodeKind::ND_ADD, $node->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_SUB, $node->lhs->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->lhs->lhs->kind);
        $this->assertEquals(0, $node->lhs->lhs->lhs->val);
        $this->assertEquals(10, $node->lhs->rhs->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(20, $node->rhs->val);
    }

    public function testBlock()
    {
        file_put_contents('tmp.c', 'int main() { {1; {2;} return 3;} }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_BLOCK, $prog[0]->body[0]->kind);
        $this->assertEquals(NodeKind::ND_BLOCK, $prog[0]->body[0]->body[0]->kind);
    }

    public function testPointer()
    {
        file_put_contents('tmp.c', 'int main() { int x=3; int y=5; return *(&x+1); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ADD, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->kind);
        $this->assertEquals(NodeKind::ND_VAR, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->lhs->lhs->lhs->kind);
        $this->assertEquals('x', $prog[0]->body[0]->body[2]->lhs->lhs->lhs->lhs->lhs->lhs->var->name);
        $this->assertEquals(TypeKind::TY_PTR, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->ty->kind);
        $this->assertEquals(NodeKind::ND_MUL, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->rhs->lhs->kind);
        $this->assertEquals(1, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->rhs->lhs->lhs->lhs->val);
        $this->assertEquals(4, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->rhs->lhs->rhs->lhs->val);
    }

    public function testVariableDefinition()
    {
        file_put_contents('tmp.c', 'int main() { int x=3; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ASSIGN, $prog[0]->body[0]->body[0]->body[0]->lhs->rhs->kind);
        $this->assertEquals('x', $prog[0]->body[0]->body[0]->body[0]->lhs->rhs->lhs->var->name);
        $this->assertEquals(3, $prog[0]->body[0]->body[0]->body[0]->lhs->rhs->rhs->lhs->val);
    }

    public function testZeroArityFunctionCall()
    {
        file_put_contents('tmp.c', 'int ret3(){} int main() { return ret3(); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_FUNCALL, $prog[1]->body[0]->body[0]->lhs->lhs->kind);
        $this->assertEquals('ret3', $prog[1]->body[0]->body[0]->lhs->lhs->funcname);
    }

    public function testFunctionCallWithUpTo6arguments()
    {
        file_put_contents('tmp.c', 'int add(int a, int b){} int main() { return add(3, 5); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_FUNCALL, $prog[1]->body[0]->body[0]->lhs->lhs->kind);
        $this->assertEquals('add', $prog[1]->body[0]->body[0]->lhs->lhs->funcname);
        $this->assertEquals(3, $prog[1]->body[0]->body[0]->lhs->lhs->args[0]->lhs->val);
        $this->assertEquals(5, $prog[1]->body[0]->body[0]->lhs->lhs->args[1]->lhs->val);
    }

    public function testZeroArityFunctionDefinition()
    {
        file_put_contents('tmp.c', 'int main(){ return 0; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();
        
        $this->assertEquals('main', $prog[0]->name);
        $this->assertEquals(NodeKind::ND_RETURN, $prog[0]->body[0]->body[0]->kind);
        $this->assertEquals(NodeKind::ND_NUM, $prog[0]->body[0]->body[0]->lhs->lhs->kind);
        $this->assertEquals(0, $prog[0]->body[0]->body[0]->lhs->lhs->val);
    }

    public function testFunctionDefinitionUpTo6parameters()
    {
        file_put_contents('tmp.c', 'int add2(int x, int y) { return x+y; } int main() { return add2(3,4); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals('main', $prog[1]->name);
        $this->assertEquals(NodeKind::ND_RETURN, $prog[1]->body[0]->body[0]->kind);
        $this->assertEquals(NodeKind::ND_FUNCALL, $prog[1]->body[0]->body[0]->lhs->lhs->kind);
        $this->assertEquals('add2', $prog[1]->body[0]->body[0]->lhs->lhs->funcname);
    }

    public function testOneDimensionalArray()
    {
        file_put_contents('tmp.c', 'int main() { int x[2]; int *y=&x; *y=3; return *x; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ASSIGN, $prog[0]->body[0]->body[2]->lhs->kind);
        $this->assertEquals(NodeKind::ND_DEREF, $prog[0]->body[0]->body[2]->lhs->lhs->kind);
        $this->assertEquals(TypeKind::TY_PTR, $prog[0]->body[0]->body[2]->lhs->lhs->lhs->ty->kind);
        $this->assertEquals('y', $prog[0]->body[0]->body[2]->lhs->lhs->lhs->var->name);
        $this->assertEquals(NodeKind::ND_NUM, $prog[0]->body[0]->body[2]->lhs->rhs->lhs->kind);
        $this->assertEquals(3, $prog[0]->body[0]->body[2]->lhs->rhs->lhs->val);
    }

    public function testGVar()
    {
        file_put_contents('tmp.c', 'int x; int main() { return x; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(TypeKind::TY_INT, $prog[0]->ty->kind);
        $this->assertEquals('x', $prog[0]->name);
        $this->assertFalse($prog[0]->isFunction);
    }

    public function testEscapeSequence()
    {
        file_put_contents('tmp.c', 'int main() { return "\\a"[0]; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(chr(7).chr(0), $prog[1]->initData);
    }
}
