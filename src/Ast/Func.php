<?php

namespace Pcc\Ast;

class Func
{
    /** @var \Pcc\Ast\Node[]  */
    public array $body;
    /** @var array<string, \Pcc\Ast\LVar> */
    public array $locals;
    public int $stackSize;

    public string $userInput;
}