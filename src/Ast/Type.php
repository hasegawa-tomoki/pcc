<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Type
{
    public TypeKind $kind;
    public int $size = 0;
    public int $align = 0;
    public bool $isUnsigned = false;
    // Pointer
    public ?Type $base;
    // Declaration
    public ?Token $name = null;
    public ?Token $namePos = null;
    // Array
    public int $arrayLen;
    /** @var \Pcc\Ast\Member[] */
    public array $members = [];
    public bool $isFlexible = false;
    // Function type
    public Type $returnTy;
    /** @var \Pcc\Ast\Type[] */
    public array $params = [];
    public bool $isVariadic = false;

    public function __construct(TypeKind $kind, ?Type $base = null, int $size = 0, int $align = 0, bool $isUnsigned = false)
    {
        $this->kind = $kind;
        $this->base = $base;
        $this->size = $size;
        $this->align = $align;
        $this->isUnsigned = $isUnsigned;
    }

    public static function tyVoid(): Type
    {
        return new Type(TypeKind::TY_VOID, null, 1, 1);
    }

    public static function tyBool(): Type
    {
        return new Type(TypeKind::TY_BOOL, null, 1, 1);
    }

    public static function tyChar(): Type
    {
        return new Type(TypeKind::TY_CHAR, null, 1, 1);
    }

    public static function tyShort(): Type
    {
        return new Type(TypeKind::TY_SHORT, null, 2, 2);
    }

    public static function tyInt(): Type
    {
        return new Type(TypeKind::TY_INT, null, 4, 4);
    }

    public static function tyLong(): Type
    {
        return new Type(TypeKind::TY_LONG, null, 8, 8);
    }

    public static function tyUChar(): Type
    {
        return new Type(TypeKind::TY_CHAR, null, 1, 1, true);
    }

    public static function tyUShort(): Type
    {
        return new Type(TypeKind::TY_SHORT, null, 2, 2, true);
    }

    public static function tyUInt(): Type
    {
        return new Type(TypeKind::TY_INT, null, 4, 4, true);
    }

    public static function tyULong(): Type
    {
        return new Type(TypeKind::TY_LONG, null, 8, 8, true);
    }

    public static function tyFloat(): Type
    {
        return new Type(TypeKind::TY_FLOAT, null, 4, 4);
    }

    public static function tyDouble(): Type
    {
        return new Type(TypeKind::TY_DOUBLE, null, 8, 8);
    }

    public static function newType(TypeKind $kind, int $size, int $align): Type
    {
        return new Type($kind, null, $size, $align);
    }

    public function isInteger(): bool
    {
        $intTypes = [
            TypeKind::TY_BOOL, TypeKind::TY_CHAR, TypeKind::TY_SHORT,
            TypeKind::TY_INT, TypeKind::TY_LONG, TypeKind::TY_ENUM,
        ];
        return in_array($this->kind, $intTypes);
    }

    public function isFlonum(): bool
    {
        return $this->kind === TypeKind::TY_FLOAT || $this->kind === TypeKind::TY_DOUBLE;
    }

    public function isNumeric(): bool
    {
        return $this->isInteger() || $this->isFlonum();
    }

    public static function pointerTo(Type $base):Type
    {
        return new Type(TypeKind::TY_PTR, $base, 8, 8, true);
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

    public static function enumType(): Type
    {
        return self::newType(TypeKind::TY_ENUM, 4, 4);
    }

    public static function structType(): Type
    {
        return self::newType(TypeKind::TY_STRUCT, 0, 1);
    }

    public static function getCommonType(Type $ty1, Type $ty2): Type
    {
        if ($ty1->base){
            return self::pointerTo($ty1->base);
        }

        if ($ty1->kind === TypeKind::TY_FUNC) {
            return self::pointerTo($ty1);
        }
        if ($ty2->kind === TypeKind::TY_FUNC) {
            return self::pointerTo($ty2);
        }

        if ($ty1->kind === TypeKind::TY_DOUBLE or $ty2->kind === TypeKind::TY_DOUBLE) {
            return self::tyDouble();
        }
        if ($ty1->kind === TypeKind::TY_FLOAT or $ty2->kind === TypeKind::TY_FLOAT) {
            return self::tyFloat();
        }

        if ($ty1->size < 4){
            $ty1 = self::tyInt();
        }
        if ($ty2->size < 4){
            $ty2 = self::tyInt();
        }

        if ($ty1->size !== $ty2->size){
            return ($ty1->size < $ty2->size)? $ty2: $ty1;
        }

        if ($ty2->isUnsigned){
            return $ty2;
        }
        return $ty1;
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