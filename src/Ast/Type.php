<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Type
{
    public TypeKind $kind;
    public int $size;
    // Pointer
    public ?Type $base;
    // Declaration
    public Token $name;
    // Array
    public int $arrayLen;
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

    public static function tyInt(): Type
    {
        $ty = new Type(TypeKind::TY_INT);
        $ty->size = 8;
        return $ty;
    }

    public static function pointerTo(Type $base):Type
    {
        $type = new Type(TypeKind::TY_PTR, $base);
        $type->size = 8;
        return $type;
    }

    public static function funcType(Type $returnTy): Type
    {
        return new Type(TypeKind::TY_FUNC, $returnTy);
    }

    public static function arrayOf(Type $base, int $len){
        $ty = new Type(TypeKind::TY_ARRAY, $base);
        $ty->size = $base->size * $len;
        $ty->arrayLen = $len;
        $ty->name = $base->name;
        return $ty;
    }
}