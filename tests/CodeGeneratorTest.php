<?php

use Pcc\Ast\Align;
use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class CodeGeneratorTest extends TestCase
{
    public function testAlignTo()
    {
        $codeGenerator = new Pcc\CodeGenerator\CodeGenerator();
        $this->assertEquals(8, Align::alignTo(5, 8));
        $this->assertEquals(16, Align::alignTo(11, 8));
    }
}
