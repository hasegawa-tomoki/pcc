<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Initializer
{
    public Type $ty;
    public Token $tok;
    public bool $isFlexible = false;

    public ?Node $expr = null;

    /** @var \Pcc\Ast\Initializer[] */
    public array $children = [];

    // Only one member can be initialized for a union.
    // `mem` is used to clarify which member is initialized.
    public ?Member $mem = null;
}
