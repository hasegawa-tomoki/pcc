<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Member
{
    public Type $ty;
    public Token $tok;
    public Token $name;
    public int $offset;
}