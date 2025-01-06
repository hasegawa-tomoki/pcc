<?php

use Pcc\Ast\Type\PccGMP;
use Pcc\Ast\Type\UInt64t;
use PHPUnit\Framework\TestCase;

class PccGMPTest extends TestCase
{
    public function testShiftR()
    {
        $c = gmp_init('0xffffffffffffffff');
        $this->assertEquals(1, gmp_intval(PccGMP::shiftR($c, 63)));
    }
}