<?php

use Pcc\Ast\Type\PccGMP;
use Pcc\Ast\Type\UInt64t;
use PHPUnit\Framework\TestCase;

class PccGMPTest extends TestCase
{
    public function testShiftRLogical()
    {
        $c = gmp_init('0xffffffffffffffff');
        $this->assertEquals(1, gmp_intval(PccGMP::shiftRLogical($c, 63)));
    }

    public function testShiftRArithmetic()
    {
        $c = gmp_init(-1);
        $twoComplementStr = PccGMP::toTwoComplementString($c);
        $this->assertEquals('1111111111111111111111111111111111111111111111111111111111111111', $twoComplementStr);
        $shifted = PccGMP::shiftRArithmetic($c, 1);
        $shiftedTwoComplementStr = PccGMP::toTwoComplementString($shifted);
        $this->assertEquals('1011111111111111111111111111111111111111111111111111111111111111', $shiftedTwoComplementStr);
    }
}