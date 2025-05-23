<?php

use Pcc\Ast\Parser;
use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testTokenize()
    {
        file_put_contents('tmp.c', 'ab=1;');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $this->assertCount(5, $tokenizer->tokens);
        $this->assertEquals('ab', $tokenizer->tok->str);
    }

    public function testReadEscapedChar()
    {
        file_put_contents('tmp.c', '\\141');
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readEscapedChar(1);
        $this->assertEquals('a', $c);
        $this->assertEquals(4, $pos);

        file_put_contents('tmp.c', '\\x61');
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readEscapedChar(1);
        $this->assertEquals('a', $c);
        $this->assertEquals(4, $pos);

        file_put_contents('tmp.c', '\\n');
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readEscapedChar(1);
        $this->assertEquals("\n", $c);
        $this->assertEquals(2, $pos);

        file_put_contents('tmp.c', '\\\\');
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readEscapedChar(1);
        $this->assertEquals("\\", $c);
        $this->assertEquals(2, $pos);

        file_put_contents('tmp.c', "\\'");
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readEscapedChar(1);
        $this->assertEquals("'", $c);
        $this->assertEquals(2, $pos);
    }

    public function testReadStringLiteral()
    {
        file_put_contents('tmp.c', '"abc"');
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $pos] = $tokenizer->readStringLiteral(0);
        $this->assertEquals("abc\0", $tok->str);
        $this->assertEquals(5, $pos);

        file_put_contents('tmp.c', '"\'abc"');
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $pos] = $tokenizer->readStringLiteral(0);
        $this->assertEquals("'abc\0", $tok->str);
        $this->assertEquals(6, $pos);

        file_put_contents('tmp.c', '"\'\\\\x80\'"');
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $pos] = $tokenizer->readStringLiteral(0);
        $this->assertEquals("'\\x80'\0", $tok->str);
        $this->assertEquals(9, $pos);
    }

    public function testReadCharLiteral()
    {
        file_put_contents('tmp.c', "'a'");
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readCharLiteral(0);
        $this->assertEquals(97, $c->val);
        $this->assertEquals('a', $c->str);

        file_put_contents('tmp.c', "'abc'");
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readCharLiteral(0);
        $this->assertEquals(97, $c->val);
        $this->assertEquals('abc', $c->str);

        file_put_contents('tmp.c', "'\\x61'");
        $tokenizer = new Tokenizer('tmp.c');
        [$c, $pos] = $tokenizer->readCharLiteral(0);
        $this->assertEquals(97, $c->val);
        $this->assertEquals('\\x61', $c->str);
    }

    public function testReadNumber()
    {
        file_put_contents('tmp.c', "0x10");
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $end] = $tokenizer->readNumber(0);
        $this->assertEquals(0x10, $tok->val);

        file_put_contents('tmp.c', "8192");
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $end] = $tokenizer->readNumber(0);
        $this->assertEquals(8192, $tok->val);

        file_put_contents('tmp.c', "0x10.1p0");
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $end] = $tokenizer->readNumber(0);
        ray($tok, $end);

        file_put_contents('tmp.c', "1.2345e-2f");
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $end] = $tokenizer->readNumber(0);
        $this->assertEquals(1.2345e-2, $tok->fval);

        file_put_contents('tmp.c', "3e+8");
        $tokenizer = new Tokenizer('tmp.c');
        [$tok, $end] = $tokenizer->readNumber(0);
        $this->assertEquals(3e+8, $tok->fval);
    }
}
