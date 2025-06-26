<?php

namespace Pcc\Ast;

use Pcc\Tokenizer\Token;

class Type
{
    public TypeKind $kind;
    public int $size = 0;
    public int $align = 0;
    public bool $isUnsigned = false;
    public ?Type $origin = null; // for type compatibility check
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

    public static function copyType(Type $ty): Type
    {
        $ret = clone $ty;
        $ret->origin = $ty;
        return $ret;
    }

    public static function isCompatible(Type $t1, Type $t2): bool
    {
        if ($t1 === $t2) {
            return true;
        }

        if ($t1->origin !== null) {
            return self::isCompatible($t1->origin, $t2);
        }

        if ($t2->origin !== null) {
            return self::isCompatible($t1, $t2->origin);
        }

        if ($t1->kind !== $t2->kind) {
            return false;
        }

        switch ($t1->kind) {
            case TypeKind::TY_VOID:
                return true;
            case TypeKind::TY_CHAR:
            case TypeKind::TY_SHORT:
            case TypeKind::TY_INT:
            case TypeKind::TY_LONG:
                return $t1->isUnsigned === $t2->isUnsigned;
            case TypeKind::TY_FLOAT:
            case TypeKind::TY_DOUBLE:
                return true;
            case TypeKind::TY_PTR:
                return self::isCompatible($t1->base, $t2->base);
            case TypeKind::TY_FUNC:
                if (!isset($t1->returnTy) || !isset($t2->returnTy)) {
                    return false;
                }
                if (!self::isCompatible($t1->returnTy, $t2->returnTy)) {
                    return false;
                }
                if ($t1->isVariadic !== $t2->isVariadic) {
                    return false;
                }

                if (count($t1->params) !== count($t2->params)) {
                    return false;
                }

                for ($i = 0; $i < count($t1->params); $i++) {
                    if (!self::isCompatible($t1->params[$i], $t2->params[$i])) {
                        return false;
                    }
                }
                return true;
            case TypeKind::TY_ARRAY:
                if (!self::isCompatible($t1->base, $t2->base)) {
                    return false;
                }
                return $t1->arrayLen < 0 and $t2->arrayLen < 0 and 
                       $t1->arrayLen === $t2->arrayLen;
        }
        return false;
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