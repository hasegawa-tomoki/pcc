<?php

namespace Pcc\Ast;

class Func
{
    public string $name;
    /** @var \Pcc\Ast\LVar[] */
    public array $params;
    /** @var \Pcc\Ast\Node[]  */
    public array $body;
    /** @var array<string, \Pcc\Ast\LVar> */
    public array $locals;
    public int $stackSize;
}