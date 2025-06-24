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
    public float $fval;
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

    public function convertKeywords(): void
    {
        $keywords = [
            'return', 'if', 'else', 'for', 'while', 'int', 'sizeof', 'char',
            'struct', 'union', 'short', 'long', 'void', 'typedef', '_Bool',
            'enum', 'static', 'goto', 'break', 'continue', 'switch', 'case',
            'default', 'extern', '_Alignof', '_Alignas', 'do', 'signed',
            'unsigned', 'float', 'double',
        ];

        for ($tok = $this; $tok->kind !== TokenKind::TK_EOF; $tok = $tok->next) {
            if (in_array($tok->str, $keywords)) {
                $tok->kind = TokenKind::TK_KEYWORD;
            }
        }
    }
}
