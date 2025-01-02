<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Initializer
{
    public Type $ty;
    public Token $tok;

    public ?Node $expr = null;

    /** @var \Pcc\Ast\Initializer[] */
    public array $children = [];
}
