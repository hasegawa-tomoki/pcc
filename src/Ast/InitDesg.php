<?php

namespace Pcc\Ast;

class InitDesg
{
    public function __construct(
        public ?InitDesg $next,
        public int $idx,
        public ?Obj $var = null,
    )
    {
    }
}