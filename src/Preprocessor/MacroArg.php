<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;

class MacroArg
{
    public ?MacroArg $next = null;
    public string $name;
    public Token $tok;

    public function __construct(string $name, Token $tok)
    {
        $this->name = $name;
        $this->tok = $tok;
    }
}