<?php
namespace Pcc\Tokenizer;

class Token
{
    public TokenKind $kind;
    public int $val;
    public string $str;

    public function __construct(TokenKind $kind, string $str)
    {
        $this->kind = $kind;
        $this->str = $str;
    }
}
