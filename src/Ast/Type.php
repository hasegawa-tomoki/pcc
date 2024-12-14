<?php

namespace Pcc\Ast;

class Type
{
    public TypeKind $kind;
    public ?Type $base;

    public function __construct(TypeKind $kind, ?Type $base = null)
    {
        $this->kind = $kind;
        $this->base = $base;
    }

    public function isInteger(): bool
    {
        return $this->kind === TypeKind::TY_INT;
    }
}