<?php

namespace Pcc\Ast;

class InitDesg
{
    public function __construct(
        public ?InitDesg $next,
        public int $idx,
        /** @var Member[] */
        public array $members = [],
        public ?Obj $var = null,
    )
    {
    }
}