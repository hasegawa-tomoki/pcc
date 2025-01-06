<?php
namespace Pcc\Tokenizer;

use GMP;
use Pcc\Ast\Type;

class Token
{
    public TokenKind $kind;
    public Token $next;
    public int $val;
    public GMP $gmpVal;
    public string $str;
    public int $pos;
    public int $lineNo;
    public int $len {
        get {
            return strlen($this->str);
        }
    }
    public Type $ty;

    public function __construct(TokenKind $kind, string $str, int $pos)
    {
        $this->kind = $kind;
        $this->str = $str;
        $this->pos = $pos;
    }

    public function isKind(TokenKind $kind): bool
    {
        return $this->kind === $kind;
    }
}
