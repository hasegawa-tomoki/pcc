<?php

use Pcc\Ast\Type\UInt64t;
use PHPUnit\Framework\TestCase;

class UInt64tTest extends TestCase
{
    public function testInitialization()
    {
        $uint = new UInt64t(123);
        $this->assertEquals('123', $uint->toString());

        $uint = new UInt64t('18446744073709551615');
        $this->assertEquals('18446744073709551615', $uint->toString());
    }

    public function testAddition()
    {
        $a = new UInt64t(123);
        $b = new UInt64t(456);
        $result = $a->add($b);

        $this->assertEquals('579', $result->toString());
    }

    public function testSubtraction()
    {
        $a = new UInt64t(456);
        $b = new UInt64t(123);
        $result = $a->subtract($b);

        $this->assertEquals('333', $result->toString());
    }

    public function testSubtractionUnderflow()
    {
        $a = new UInt64t(123);
        $b = new UInt64t(124);
        $result = $a->subtract($b);
        $this->assertEquals('18446744073709551615', $result->toString());
    }

    public function testMultiplication()
    {
        $a = new UInt64t(123);
        $b = new UInt64t(2);
        $result = $a->multiply($b);

        $this->assertEquals('246', $result->toString());
    }

    public function testMultiplicationOverflow()
    {
        $a = new UInt64t('9223372036854775809');
        $b = new UInt64t(2);
        $result = $a->multiply($b);
        $this->assertEquals('2', $result->toString());
    }

    public function testDivision()
    {
        $a = new UInt64t(123);
        $b = new UInt64t(2);
        $result = $a->divide($b);

        $this->assertEquals('61', $result->toString());
    }

    public function testDivisionByZero()
    {
        $this->expectException(DivisionByZeroError::class);

        $a = new UInt64t(123);
        $b = new UInt64t(0);
        $a->divide($b);
    }

    public function testOverflow()
    {
        $a = new UInt64t(UInt64t::MAX);
        $result = $a->add(new UInt64t(1));
        $this->assertEquals(0, $result->toInt());
    }

    public function testUnderflow()
    {
        $a = new UInt64t(0);
        $result = $a->subtract(new UInt64t(1));
        $this->assertEquals(UInt64t::MAX, $result->toString());
    }
}