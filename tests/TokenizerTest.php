<?php

use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testTokenize()
    {
        $tokenizer = new Tokenizer('ab=1;');
        $tokenizer->tokenize();
        $this->assertEquals(5, count($tokenizer->tokens));
        $this->assertEquals('ab', $tokenizer->tokens[0]->str);
    }
}
