<?php

use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testTokenize()
    {
        file_put_contents('tmp.c', 'ab=1;');
        $tokenizer = new Tokenizer('tmp.c');
        $tokenizer->tokenize();
        $this->assertEquals(5, count($tokenizer->tokens));
        $this->assertEquals('ab', $tokenizer->tok->str);
    }
}
