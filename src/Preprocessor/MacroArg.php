<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;

class MacroArg
{
    public ?MacroArg $next = null;
    public string $name;
    public bool $isVaArgs = false;
    public Token $tok;
    public ?Token $expanded = null;

    public function __construct(string $name, Token $tok)
    {
        $this->name = $name;
        $this->tok = $tok;
    }
}