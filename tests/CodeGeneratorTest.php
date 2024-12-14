<?php

use Pcc\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class CodeGeneratorTest extends TestCase
{
    public function testAlignTo()
    {
        $codeGenerator = new Pcc\CodeGenerator\CodeGenerator();
        $this->assertEquals(8, $codeGenerator->alignTo(5, 8));
        $this->assertEquals(16, $codeGenerator->alignTo(11, 8));
    }
}
