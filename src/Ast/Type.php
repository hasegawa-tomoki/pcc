<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Type
{
    public TypeKind $kind;
    // Pointer
    public ?Type $base;
    // Declaration
    public Token $name;
    // Function type
    public ?Type $returnTy;
    /** @var \Pcc\Ast\Type[] */
    public array $params = [];

    public function __construct(TypeKind $kind, ?Type $base = null)
    {
        $this->kind = $kind;
        $this->base = $base;
    }

    public function isInteger(): bool
    {
        return $this->kind === TypeKind::TY_INT;
    }

    public static function pointerTo(Type $base):Type
    {
        return new Type(TypeKind::TY_PTR, $base);
    }

    public static function funcType(Type $returnTy): Type
    {
        return new Type(TypeKind::TY_FUNC, $returnTy);
    }
}