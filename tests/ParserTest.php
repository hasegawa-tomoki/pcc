<?php

use Pcc\Ast\NodeKind;
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
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NEG, $node->kind);
        $this->assertEquals(5, $node->lhs->val);
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
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NEG, $node->lhs->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->lhs->lhs->kind);
        $this->assertEquals(10, $node->lhs->lhs->val);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $node->rhs->kind);
        $this->assertEquals(20, $node->rhs->val);
    }

    public function testDeclaration()
    {
        file_put_contents('tmp.c', 'int a=10;');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        [$declaration, $tok] = $declaration = $parser->declaration($tokenizer->tok, $tokenizer->tok);

        $this->assertEquals(Pcc\Ast\NodeKind::ND_ASSIGN, $declaration->body[0]->lhs->kind);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_VAR, $declaration->body[0]->lhs->lhs->kind);
        $this->assertEquals('a', $declaration->body[0]->lhs->lhs->var->name);
        $this->assertEquals(Pcc\Ast\NodeKind::ND_NUM, $declaration->body[0]->lhs->rhs->kind);
        $this->assertEquals(10, $declaration->body[0]->lhs->rhs->val);
    }

    public function testBlock()
    {
        file_put_contents('tmp.c', 'int main() { {1; {2;} return 3;} }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_BLOCK, $prog['main']->body[0]->kind);
        $this->assertEquals(NodeKind::ND_BLOCK, $prog['main']->body[0]->body[0]->kind);
    }

    public function testPointer()
    {
        file_put_contents('tmp.c', 'int main() { int x=3; int y=5; return *(&x+1); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ADD, $prog['main']->body[0]->body[2]->lhs->lhs->kind);
        $this->assertEquals(NodeKind::ND_VAR, $prog['main']->body[0]->body[2]->lhs->lhs->lhs->lhs->kind);
        $this->assertEquals('x', $prog['main']->body[0]->body[2]->lhs->lhs->lhs->lhs->var->name);
        $this->assertEquals(TypeKind::TY_PTR, $prog['main']->body[0]->body[2]->lhs->lhs->lhs->ty->kind);
        $this->assertEquals(NodeKind::ND_MUL, $prog['main']->body[0]->body[2]->lhs->lhs->rhs->kind);
        $this->assertEquals(1, $prog['main']->body[0]->body[2]->lhs->lhs->rhs->lhs->val);
        $this->assertEquals(8, $prog['main']->body[0]->body[2]->lhs->lhs->rhs->rhs->val);
    }

    public function testVariableDefinition()
    {
        file_put_contents('tmp.c', 'int main() { int x=3; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ASSIGN, $prog['main']->body[0]->body[0]->body[0]->lhs->kind);
        $this->assertEquals('x', $prog['main']->body[0]->body[0]->body[0]->lhs->lhs->var->name);
        $this->assertEquals(3, $prog['main']->body[0]->body[0]->body[0]->lhs->rhs->val);
    }

    public function testZeroArityFunctionCall()
    {
        file_put_contents('tmp.c', 'int main() { return ret3(); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_FUNCALL, $prog['main']->body[0]->body[0]->lhs->kind);
        $this->assertEquals('ret3', $prog['main']->body[0]->body[0]->lhs->funcname);
    }

    public function testFunctionCallWithUpTo6arguments()
    {
        file_put_contents('tmp.c', 'int main() { return add(3, 5); }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_FUNCALL, $prog['main']->body[0]->body[0]->lhs->kind);
        $this->assertEquals('add', $prog['main']->body[0]->body[0]->lhs->funcname);
        $this->assertEquals(3, $prog['main']->body[0]->body[0]->lhs->args[0]->val);
        $this->assertEquals(5, $prog['main']->body[0]->body[0]->lhs->args[1]->val);
    }

    public function testZeroArityFunctionDefinition()
    {
        file_put_contents('tmp.c', 'int main(){ return 0; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();
        
        $this->assertEquals('main', $prog['main']->name);
        $this->assertEquals(NodeKind::ND_RETURN, $prog['main']->body[0]->body[0]->kind);
        $this->assertEquals(NodeKind::ND_NUM, $prog['main']->body[0]->body[0]->lhs->kind);
        $this->assertEquals(0, $prog['main']->body[0]->body[0]->lhs->val);
    }

    public function testFunctionDefinitionUpTo6parameters()
    {
        file_put_contents('tmp.c', 'int main() { return add2(3,4); } int add2(int x, int y) { return x+y; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals('main', $prog['main']->name);
        $this->assertEquals(NodeKind::ND_RETURN, $prog['main']->body[0]->body[0]->kind);
        $this->assertEquals(NodeKind::ND_FUNCALL, $prog['main']->body[0]->body[0]->lhs->kind);
        $this->assertEquals('add2', $prog['main']->body[0]->body[0]->lhs->funcname);
    }

    public function testOneDimensionalArray()
    {
        file_put_contents('tmp.c', 'int main() { int x[2]; int *y=&x; *y=3; return *x; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(NodeKind::ND_ASSIGN, $prog['main']->body[0]->body[2]->lhs->kind);
        $this->assertEquals(NodeKind::ND_DEREF, $prog['main']->body[0]->body[2]->lhs->lhs->kind);
        $this->assertEquals(TypeKind::TY_PTR, $prog['main']->body[0]->body[2]->lhs->lhs->lhs->ty->kind);
        $this->assertEquals('y', $prog['main']->body[0]->body[2]->lhs->lhs->lhs->var->name);
        $this->assertEquals(NodeKind::ND_NUM, $prog['main']->body[0]->body[2]->lhs->rhs->kind);
        $this->assertEquals(3, $prog['main']->body[0]->body[2]->lhs->rhs->val);
    }

    public function testGVar()
    {
        file_put_contents('tmp.c', 'int x; int main() { return x; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(TypeKind::TY_INT, $prog['x']->ty->kind);
        $this->assertEquals('x', $prog['x']->name);
        $this->assertFalse($prog['x']->isFunction);
    }

    public function testEscapeSequence()
    {
        file_put_contents('tmp.c', 'int main() { return "\\a"[0]; }');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $parser = new Pcc\Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $this->assertEquals(chr(7), $prog['.L..0']->initData);
    }
}
