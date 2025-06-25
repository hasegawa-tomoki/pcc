<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Member
{
    public Type $ty;
    public Token $tok;
    public ?Token $name;
    public int $align = 0;
    public int $offset;

    // Bitfield
    public bool $isBitfield = false;
    public int $bitOffset = 0;
    public int $bitWidth = 0;
}