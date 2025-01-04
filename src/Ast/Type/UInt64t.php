<?php
namespace Pcc\Ast\Type;

class UInt64t
{
    public const string MAX = '18446744073709551615';
    public const string MODULO = '18446744073709551616';

    private string $value;

    public function __construct(int|string $value)
    {
        $this->setValue($this->normalize($value));
    }

    private function normalize(int|string $value): string
    {
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (bccomp($value, '0') < 0) {
            $value = bcadd($value, self::MODULO);
        }
        return bcmod($value, self::MODULO);
    }
    private function setValue(int|string $value): void
    {
        if (is_int($value)) {
            $value = (string)$value;
        }

        if (!ctype_digit($value) || bccomp($value, self::MAX) > 0 || bccomp($value, '0') < 0) {
            throw new \InvalidArgumentException(sprintf("Value must be an unsigned 64-bit integer (0 - %s).", self::MAX));
        }

        $this->value = $value;
    }

    public function add(UInt64t $other): UInt64t
    {
        $result = bcadd($this->value, $other->value);
        return new UInt64t($result);
    }

    public function subtract(UInt64t $other): UInt64t
    {
        $result = bcsub($this->value, $other->value);
        return new UInt64t($result);
    }

    public function multiply(UInt64t $other): UInt64t
    {
        $result = bcmul($this->value, $other->value);
        return new UInt64t($result);
    }

    public function divide(UInt64t $other): UInt64t
    {
        if ($other->value === '0') {
            throw new \DivisionByZeroError("Cannot divide by zero.");
        }
        $result = bcdiv($this->value, $other->value, 0);
        return new UInt64t($result);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function toInt(): int
    {
        if (bccomp($this->value, (string)PHP_INT_MAX) > 0) {
            throw new \OverflowException("Value exceeds PHP_INT_MAX and cannot be represented as an int.");
        }
        return (int)$this->value;
    }
}
