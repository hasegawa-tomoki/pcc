<?php
namespace Pcc\Ast;

class Relocation
{
    public function __construct(
        public int $offset = 0,
        public ?array &$label = null,
        public int $addend = 0,
    )
    {
    }
}