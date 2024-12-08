<?php
namespace Pcc\Tokenizer;

class Token
{
    public TokenKind $kind;
    public int $val;
    public string $str;
    public int $pos;

    public function __construct(TokenKind $kind, string $str, int $pos)
    {
        $this->kind = $kind;
        $this->str = $str;
        $this->pos = $pos;
    }
}
