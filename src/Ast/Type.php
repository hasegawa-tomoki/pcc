<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Type
{
    public TypeKind $kind;
    public int $size = 0;
    public int $align;
    // Pointer
    public ?Type $base;
    // Declaration
    public Token $name;
    // Array
    public int $arrayLen;
    /** @var \Pcc\Ast\Member[] */
    public array $members;
    // Function type
    public ?Type $returnTy;
    /** @var \Pcc\Ast\Type[] */
    public array $params = [];

    public function __construct(TypeKind $kind, ?Type $base = null)
    {
        $this->kind = $kind;
        $this->base = $base;
    }

    public static function newType(TypeKind $kind, int $size, int $align): Type
    {
        $ty = new Type($kind);
        $ty->size = $size;
        $ty->align = $align;
        return $ty;
    }

    public static function tyInt(): Type
    {
        $ty = new Type(TypeKind::TY_INT);
        $ty->size = 4;
        $ty->align = 4;
        return $ty;
    }

    public static function tyChar(): Type
    {
        $ty = new Type(TypeKind::TY_CHAR);
        $ty->size = 1;
        $ty->align = 1;
        return $ty;
    }

    public function isInteger(): bool
    {
        return $this->kind === TypeKind::TY_CHAR or $this->kind === TypeKind::TY_INT;
    }

    public static function pointerTo(Type $base):Type
    {
        $type = new Type(TypeKind::TY_PTR, $base);
        $type->size = 8;
        $type->align = 8;
        return $type;
    }

    public static function funcType(Type $returnTy): Type
    {
        return new Type(TypeKind::TY_FUNC, $returnTy);
    }

    public static function arrayOf(Type $base, int $len): Type
    {
        $ty = self::newType(TypeKind::TY_ARRAY, $base->size * $len, $base->align);
        $ty->base = $base;
        $ty->arrayLen = $len;
        return $ty;
    }
}