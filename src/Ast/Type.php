<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Type
{
    public TypeKind $kind;
    public int $size = 0;
    public int $align = 0;
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

    public static function tyVoid(): Type
    {
        $ty = new Type(TypeKind::TY_VOID);
        $ty->size = 1;
        $ty->align = 1;
        return $ty;
    }

    public static function tyChar(): Type
    {
        $ty = new Type(TypeKind::TY_CHAR);
        $ty->size = 1;
        $ty->align = 1;
        return $ty;
    }

    public static function tyShort(): Type
    {
        $ty = new Type(TypeKind::TY_SHORT);
        $ty->size = 2;
        $ty->align = 2;
        return $ty;
    }

    public static function tyInt(): Type
    {
        $ty = new Type(TypeKind::TY_INT);
        $ty->size = 4;
        $ty->align = 4;
        return $ty;
    }

    public static function tyLong(): Type
    {
        $ty = new Type(TypeKind::TY_LONG);
        $ty->size = 8;
        $ty->align = 8;
        return $ty;
    }

    public static function newType(TypeKind $kind, int $size, int $align): Type
    {
        $ty = new Type($kind);
        $ty->size = $size;
        $ty->align = $align;
        return $ty;
    }

    public function isInteger(): bool
    {
        $intTypes = [
            TypeKind::TY_CHAR,
            TypeKind::TY_SHORT,
            TypeKind::TY_INT,
            TypeKind::TY_LONG,
        ];
        return in_array($this->kind, $intTypes);
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
        $type = new Type(TypeKind::TY_FUNC);
        $type->returnTy = $returnTy;
        return $type;
    }

    public static function arrayOf(Type $base, int $len): Type
    {
        $ty = self::newType(TypeKind::TY_ARRAY, $base->size * $len, $base->align);
        $ty->base = $base;
        $ty->arrayLen = $len;
        return $ty;
    }

    public static function getCommonType(Type $ty1, Type $ty2): Type
    {
        if ($ty1->base){
            return self::pointerTo($ty1->base);
        }
        if ($ty1->size === 8 or $ty2->size === 8){
            return self::tyLong();
        }
        return self::tyInt();
    }

    /**
     * @param \Pcc\Ast\Node $lhs
     * @param \Pcc\Ast\Node $rhs
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Ast\Node}
     */
    public static function usualArithConv(Node $lhs, Node $rhs): array
    {
        $ty = self::getCommonType($lhs->ty, $rhs->ty);
        return [
            Node::newCast($lhs, $ty),
            Node::newCast($rhs, $ty),
        ];
    }
}