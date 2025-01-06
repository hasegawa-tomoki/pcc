<?php /** @noinspection PhpUnusedParameterInspection */

namespace Pcc\Ast;

use GMP;
use Pcc\Ast\Scope\Scope;
use Pcc\Ast\Scope\TagScope;
use Pcc\Ast\Scope\VarScope;
use Pcc\Ast\Type\PccGMP;
use Pcc\Console;
use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Tokenizer\TokenKind;

class Parser
{
    /** @var array<int, \Pcc\Ast\Obj> */
    public array $locals = [];
    /** @var array<int, \Pcc\Ast\Obj> */
    public array $globals = [];
    /** @var array<int, \Pcc\Ast\Scope\Scope> */
    public array $scopes = [];
    public int $scopeDepth = 0;
    public Obj $currentFn;
    /** @var Node[] */
    public array $gotos = [];
    /** @var Node[] */
    public array $labels = [];

    // Current "goto" and "continue" jump targets
    public ?string $brkLabel = null;
    public ?string $contLabel = null;

    public ?Node $currentSwitch = null;

    public function __construct(
        private readonly Tokenizer $tokenizer,
    )
    {
        $this->scopes[] = new Scope();
    }

    public function enterScope(): void
    {
        array_unshift($this->scopes, new Scope());
        $this->scopeDepth++;
    }

    public function leaveScope(): void
    {
        array_shift($this->scopes);
        $this->scopeDepth--;
    }

    public function findVar(Token $tok): ?VarScope
    {
        foreach ($this->scopes as $sc){
            foreach ($sc->vars as $vsc){
                if ($vsc->name === $tok->str){
                    return $vsc;
                }
            }
        }

        return null;
    }

    public function findTag(Token $tok): ?TagScope
    {
        foreach ($this->scopes as $sc){
            foreach ($sc->tags as $tsc){
                if ($tsc->name === $tok->str){
                    return $tsc;
                }
            }
        }

        return null;
    }

    public function pushScope(string $name): VarScope
    {
        $sc = new VarScope();
        $sc->name = $name;
        $sc->depth = $this->scopeDepth;

        array_unshift($this->scopes[0]->vars, $sc);
        return $sc;
    }

    public function newInitializer(Type $ty, bool $isFlexible): Initializer
    {
        $init = new Initializer();
        $init->ty = $ty;

        if ($ty->kind === TypeKind::TY_ARRAY){
            if ($isFlexible and $ty->size < 0){
                $init->isFlexible = true;
                return $init;
            }

            for ($i = 0; $i < $ty->arrayLen; $i++){
                $init->children[] = $this->newInitializer($ty->base, false);
            }
            return $init;
        }

        if ($ty->kind === TypeKind::TY_STRUCT or $ty->kind === TypeKind::TY_UNION){
            foreach ($ty->members as $idx => $mem){
                if ($isFlexible and $ty->isFlexible and (! isset($ty->members[$idx + 1]))){
                    $child = new Initializer();
                    $child->ty = $mem->ty;
                    $child->isFlexible = true;
                    $init->children[] = $child;
                } else {
                    $init->children[] = $this->newInitializer($mem->ty, false);
                }
            }
            return $init;
        }

        return $init;
    }

    public function newVar(string $name, Type $ty): Obj
    {
        $var = new Obj($name);
        $var->ty = $ty;
        $var->align = $ty->align;
        $this->pushScope($name)->var = $var;
        return $var;
    }

    public function newLvar(string $name, Type $ty): Obj
    {
        $var = $this->newVar($name, $ty);
        $var->isLocal = true;
        $this->locals[] = $var;
        return $var;
    }

    public function newGVar(string $name, Type $ty): Obj
    {
        $var = $this->newVar($name, $ty);
        $var->isLocal = false;
        $var->isStatic = true;
        $var->isDefinition = true;
        $this->globals[] = $var;
        return $var;
    }

    public function newUniqueName(): string
    {
        static $id = 0;
        return sprintf('.L..%d', $id++);
    }

    public function newAnonGVar(Type $ty): Obj
    {
        return $this->newGVar($this->newUniqueName(), $ty);
    }

    public function newStringLiteral(string $p, Type $ty): Obj
    {
        $var = $this->newAnonGVar($ty);
        $var->initData = $p;
        return $var;
    }

    public function getIdent(Token $tok): string
    {
        if (! $tok->isKind(TokenKind::TK_IDENT)){
            Console::errorTok($tok, "expected an identifier");
        }
        return $tok->str;
    }

    public function findTypedef(Token $tok): ?Type
    {
        if ($tok->isKind(TokenKind::TK_IDENT)){
            $sc = $this->findVar($tok);
            if ($sc){
                return $sc->typeDef;
            }
        }
        return null;
    }

    public function pushTagScope(Token $tok, Type $ty): void
    {
        $sc = new TagScope();
        $sc->name = $tok->str;
        $sc->depth = $this->scopeDepth;
        $sc->ty = $ty;

        array_unshift($this->scopes[0]->tags, $sc);
    }

    /**
     * typespec = typename typename*
     * typename = "void" | "_Bool" | "char" | "short" | "int" | "long"
     *          | struct-decl | union-decl | typedef-name
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\VarAttr|null $attr
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function typespec(Token $rest, Token $tok, ?VarAttr $attr): array
    {
        $ty = Type::tyInt();
        $counter = 0;

        while ($this->isTypeName($tok)){
            // Handle storage class specifiers
            if ($this->tokenizer->equal($tok, 'typedef') or $this->tokenizer->equal($tok, 'static') or $this->tokenizer->equal($tok, 'extern')){
                if (! $attr){
                    Console::errorTok($tok, 'storage class specifier is not allowed in this context');
                }

                if ($this->tokenizer->equal($tok, 'typedef')) {
                    $attr->isTypedef = true;
                } elseif ($this->tokenizer->equal($tok, 'static')) {
                    $attr->isStatic = true;
                } else {
                    $attr->isExtern = true;
                }

                if ($attr->isTypedef and $attr->isStatic + $attr->isExtern > 1){
                    Console::errorTok($tok, 'typedef may not be used together with static or extern');
                }

                $tok = $tok->next;
                continue;
            }

            if ($this->tokenizer->equal($tok, '_Alignas')){
                if (! $attr){
                    Console::errorTok($tok, '_Alignas is not allowed in this context');
                }
                $tok = $this->tokenizer->skip($tok->next, '(');
                if ($this->isTypeName($tok)){
                    [$ty, $tok] = $this->typename($tok, $tok);
                    $attr->align = $ty->align;
                } else {
                    [$gmpVal, $tok] = $this->constExpr($tok, $tok);
                    $attr->align = PccGMP::toSignedInt($gmpVal);
                }
                $tok = $this->tokenizer->skip($tok, ')');
                continue;
            }

            // Handle user-defined types
            $ty2 = $this->findTypedef($tok);
            if ($this->tokenizer->equal($tok, 'struct') or $this->tokenizer->equal($tok, 'union') or $this->tokenizer->equal($tok, 'enum') or $ty2){
                if ($counter){
                    break;
                }

                if ($this->tokenizer->equal($tok, 'struct')) {
                    [$ty, $tok] = $this->structDecl($tok, $tok->next);
                } elseif ($this->tokenizer->equal($tok, 'union')) {
                    [$ty, $tok] = $this->unionDecl($tok, $tok->next);
                } elseif ($this->tokenizer->equal($tok, 'enum')) {
                    [$ty, $tok] = $this->enumSpecifier($tok, $tok->next);
                } else {
                    $ty = $ty2;
                    $tok = $tok->next;
                }
                $counter += TypeCount::OTHER->value;
                continue;
            }

            // Handle built-in types
            if ($this->tokenizer->equal($tok, 'void')){
                $counter += TypeCount::VOID->value;
            } elseif ($this->tokenizer->equal($tok, '_Bool')){
                $counter += TypeCount::BOOL->value;
            } elseif ($this->tokenizer->equal($tok, 'char')){
                $counter += TypeCount::CHAR->value;
            } elseif ($this->tokenizer->equal($tok, 'short')){
                $counter += TypeCount::SHORT->value;
            } elseif ($this->tokenizer->equal($tok, 'int')){
                $counter += TypeCount::INT->value;
            } elseif ($this->tokenizer->equal($tok, 'long')){
                $counter += TypeCount::LONG->value;
            } elseif ($this->tokenizer->equal($tok, 'signed')){
                $counter |= TypeCount::SIGNED->value;
            } elseif ($this->tokenizer->equal($tok, 'unsigned')){
                $counter |= TypeCount::UNSIGNED->value;
            } else {
                Console::unreachable(__FILE__, __LINE__);
            }

            switch ($counter){
                case TypeCount::VOID->value:
                    $ty = Type::tyVoid();
                    break;
                case TypeCount::BOOL->value:
                    $ty = Type::tyBool();
                    break;
                case TypeCount::CHAR->value:
                case TypeCount::SIGNED->value + TypeCount::CHAR->value:
                    $ty = Type::tyChar();
                    break;
                case TypeCount::UNSIGNED->value + TypeCount::CHAR->value:
                    $ty = Type::tyUChar();
                    break;
                case TypeCount::SHORT->value:
                case TypeCount::SHORT->value + TypeCount::INT->value:
                case TypeCount::SIGNED->value + TypeCount::SHORT->value:
                case TypeCount::SIGNED->value + TypeCount::SHORT->value + TypeCount::INT->value:
                    $ty = Type::tyShort();
                    break;
                case TypeCount::UNSIGNED->value + TypeCount::SHORT->value:
                case TypeCount::UNSIGNED->value + TypeCount::SHORT->value + TypeCount::INT->value:
                    $ty = Type::tyUShort();
                    break;
                case TypeCount::INT->value:
                case TypeCount::SIGNED->value:
                case TypeCount::SIGNED->value + TypeCount::INT->value:
                    $ty = Type::tyInt();
                    break;
                case TypeCount::UNSIGNED->value:
                case TypeCount::UNSIGNED->value + TypeCount::INT->value:
                    $ty = Type::tyUInt();
                    break;
                case TypeCount::LONG->value:
                case TypeCount::LONG->value + TypeCount::INT->value:
                case TypeCount::LONG->value + TypeCount::LONG->value:
                case TypeCount::LONG->value + TypeCount::LONG->value + TypeCount::INT->value:
                case TypeCount::SIGNED->value + TypeCount::LONG->value:
                case TypeCount::SIGNED->value + TypeCount::LONG->value + TypeCount::INT->value:
                case TypeCount::SIGNED->value + TypeCount::LONG->value + TypeCount::LONG->value:
                case TypeCount::SIGNED->value + TypeCount::LONG->value + TypeCount::LONG->value + TypeCount::INT->value:
                    $ty = Type::tyLong();
                    break;
                case TypeCount::UNSIGNED->value + TypeCount::LONG->value:
                case TypeCount::UNSIGNED->value + TypeCount::LONG->value + TypeCount::INT->value:
                case TypeCount::UNSIGNED->value + TypeCount::LONG->value + TypeCount::LONG->value:
                case TypeCount::UNSIGNED->value + TypeCount::LONG->value + TypeCount::LONG->value + TypeCount::INT->value:
                    $ty = Type::tyULong();
                    break;
                default:
                    Console::errorTok($tok, 'invalid type');
            }

            $tok = $tok->next;
        }

        return [$ty, $tok];
    }

    /**
     * func-params = ("void" | param ("," param)* ("," "...")?)? ")"
     * param = typespec declarator
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function funcParams(Token $rest, Token $tok, Type $ty): array
    {
        if ($this->tokenizer->equal($tok, 'void') and $this->tokenizer->equal($tok->next, ')')){
            return [Type::funcType($ty), $tok->next->next];
        }

        $params = [];
        $isVariadic = false;

        while (! $this->tokenizer->equal($tok, ')')){
            if (count($params) > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            if ($this->tokenizer->equal($tok, '...')){
                $isVariadic = true;
                $tok = $tok->next;
                $this->tokenizer->skip($tok, ')');
                break;
            }

            [$ty2, $tok] = $this->typespec($tok, $tok, null);
            [$ty2, $tok] = $this->declarator($tok, $tok, $ty2);

            // "array of T" is converted to "pointer to T" only in the parameter context.
            // For example, *argv[] is converted to **argv by this.
            if ($ty2->kind === TypeKind::TY_ARRAY){
                $name = $ty2->name;
                $ty2 = Type::pointerTo($ty2->base);
                $ty2->name = $name;
            }
            $params[] = clone $ty2;
        }

        $ty = Type::funcType($ty);
        $ty->params = $params;
        $ty->isVariadic = $isVariadic;

        return [$ty, $tok->next];
    }

    /**
     * array-dimensions = const-expr? "]" type-suffix
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function arrayDimensions(Token $rest, Token $tok, Type $ty): array
    {
        if ($this->tokenizer->equal($tok, ']')){
            [$ty, $rest] = $this->typeSuffix($rest, $tok->next, $ty);
            return [Type::arrayOf($ty, -1), $rest];
        }

        [$gmpSz, $tok] = $this->constExpr($tok, $tok);
        $sz = PccGMP::toSignedInt($gmpSz, 32);
        $tok = $this->tokenizer->skip($tok, ']');
        [$ty, $rest] = $this->typeSuffix($rest, $tok, $ty);
        return [Type::arrayOf($ty, $sz), $rest];
    }

    /**
     * type-suffix = "(" func-params
     *             | "[" num "]" array-dimensions
     *             | ε
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function typeSuffix(Token $rest, Token $tok, Type $ty): array
    {
        if ($this->tokenizer->equal($tok, '(')){
            return $this->funcParams($rest, $tok->next, $ty);
        }

        if ($this->tokenizer->equal($tok, '[')){
            return $this->arrayDimensions($rest, $tok->next, $ty);
        }

        return [$ty, $tok];
    }

    /**
     * declarator = "*"* ("(" ident ")" | "(" declarator ")" | ident) type-suffix
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     *
     * ({ char (*x)[3]; sizeof(x); })
     */
    public function declarator(Token $rest, Token $tok, Type $ty): array
    {
        while ([$consumed, $tok] = $this->tokenizer->consume($tok, $tok, '*') and $consumed){
            $ty = Type::pointerTo($ty);
        }

        if ($this->tokenizer->equal($tok, '(')){
            $start = $tok;

            $ignore = new Type(TypeKind::TY_CHAR);
            [$declarator, $tok] = $this->declarator($tok, $tok->next, $ignore);

            $tok = $this->tokenizer->skip($tok, ')');

            [$ty, $rest] = $this->typeSuffix($rest, $tok, $ty);
            [$type, $tok] = $this->declarator($tok, $start->next, $ty);
            return [$type, $rest];
        }

        if (! $tok->isKind(TokenKind::TK_IDENT)){
            Console::errorTok($tok, 'expected a variable name');
        }

        [$ty, $rest] = $this->typeSuffix($rest, $tok->next, $ty);
        $ty->name = $tok;
        return [$ty, $rest];
    }

    /**
     * abstract-declarator = "*"* ("(" abstract-declarator ")")? type-suffix
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function abstractDeclarator(Token $rest, Token $tok, Type $ty): array
    {
        while ($this->tokenizer->equal($tok, '*')){
            $ty = Type::pointerTo($ty);
            $tok = $tok->next;
        }

        if ($this->tokenizer->equal($tok, '(')){
            $start = $tok;

            $ignore = new Type(TypeKind::TY_CHAR);
            [$declarator, $tok] = $this->abstractDeclarator($tok, $tok->next, $ignore);

            $tok = $this->tokenizer->skip($tok, ')');

            [$ty, $rest] = $this->typeSuffix($rest, $tok, $ty);
            [$type, $tok] = $this->abstractDeclarator($tok, $start->next, $ty);

            return [$type, $rest];
        }

        return $this->typeSuffix($rest, $tok, $ty);
    }

    /**
     * type-name = typespec abstract-declarator
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function typename(Token $rest, Token $tok): array
    {
        [$ty, $tok] = $this->typespec($rest, $tok, null);
        if ($ty->kind === TypeKind::TY_STRUCT and $ty->size === -1){
            $sc = $this->findTag($ty->name);
            $ty = $sc->ty;
        }
        return $this->abstractDeclarator($rest, $tok, $ty);
    }

    public function isEnd(Token $tok): bool
    {
        return $this->tokenizer->equal($tok, '}') or ($this->tokenizer->equal($tok, ',') and $this->tokenizer->equal($tok->next, '}'));
    }

    /**
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: bool, 1: \Pcc\Tokenizer\Token}
     */
    public function consumeEnd(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, '}')){
            return [true, $tok->next];
        }

        if ($this->tokenizer->equal($tok, ',') and $this->tokenizer->equal($tok->next, '}')){
            return [true, $tok->next->next];
        }

        return [false, $rest];
    }

    /**
     * enum-specifier = ident? "{" enum-list? "}"
     *                | ident ("{" enum-list? "}")?
     * enum-list      = ident ("=" num)? ("," ident ("=" num)?)* ","?
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function enumSpecifier(Token $rest, Token $tok): array
    {
        $ty = Type::enumType();

        // Read a struct tag.
        $tag = null;
        if ($tok->isKind(TokenKind::TK_IDENT)){
            $tag = $tok;
            $tok = $tok->next;
        }

        if ($tag and (! $this->tokenizer->equal($tok, '{'))){
            $sc = $this->findTag($tag);
            if (! $sc){
                Console::errorTok($tag, 'unknown enum type');
            }
            if ($sc->ty->kind !== TypeKind::TY_ENUM){
                Console::errorTok($tag, 'not an enum tag');
            }
            return [$sc->ty, $tok];
        }

        $tok = $this->tokenizer->skip($tok, '{');

        // Read an enum-list.
        $i = 0;
        $val = 0;
        while ([$consumed, $rest] = $this->consumeEnd($rest, $tok) and (! $consumed)){
            if ($i++ > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            $name = $this->getIdent($tok);
            $tok = $tok->next;

            if ($this->tokenizer->equal($tok, '=')){
                [$gmpVal, $tok] = $this->constExpr($tok, $tok->next);
                $val = PccGMP::toSignedInt($gmpVal);
            }

            $sc = $this->pushScope($name);
            $sc->enumTy = $ty;
            $sc->enumVal = $val++;
        }

        if ($tag){
            $this->pushTagScope($tag, $ty);
        }

        return [$ty, $rest];
    }

    /**
     * declaration = typespec (declarator ("=" expr)? ("," declarator ("=" expr)?)*)? ";"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $basety
     * @param \Pcc\Ast\VarAttr|null $attr
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function declaration(Token $rest, Token $tok, Type $basety, ?VarAttr $attr): array
    {
        $i = 0;
        $nodes = [];
        while (! $this->tokenizer->equal($tok, ';')){
            if ($i++ > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            [$ty, $tok] = $this->declarator($tok, $tok, $basety);
            if ($ty->kind === TypeKind::TY_VOID){
                Console::errorTok($tok, 'variable declared void');
            }

            if ($attr and $attr->isStatic){
                // static local variable
                $var = $this->newAnonGVar($ty);
                $this->pushScope($this->getIdent($ty->name))->var = $var;
                if ($this->tokenizer->equal($tok, '=')){
                    $tok = $this->gVarInitializer($tok, $tok->next, $var);
                }
                continue;
            }

            $var = $this->newLvar($this->getIdent($ty->name), $ty);
            if ($attr and $attr->align){
                $var->align = $attr->align;
            }

            if ($this->tokenizer->equal($tok, '=')){
                [$expr, $tok] = $this->lVarInitializer($tok, $tok->next, $var);
                $nodes[] = Node::newUnary(NodeKind::ND_EXPR_STMT, $expr, $tok);
            }

            if ($var->ty->size < 0){
                Console::errorTok($tok, 'variable has incomplete type');
            }
            if ($var->ty->kind === TypeKind::TY_VOID){
                Console::errorTok($tok, 'variable declared void');
            }
        }

        $node = Node::newNode(NodeKind::ND_BLOCK, $tok);
        $node->body = $nodes;
        return [$node, $tok->next];
    }

    public function skipExcessElement(Token $tok): Token
    {
        if ($this->tokenizer->skip($tok, '{')){
            $tok = $this->skipExcessElement($tok->next);
            return $this->tokenizer->skip($tok, '}');
        }

        [$_, $tok] = $this->assign($tok, $tok);
        return $tok;
    }

    /**
     * string-initializer = string-literal
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function stringInitializer(Token $rest, Token $tok, Initializer $init): array
    {
        if ($init->isFlexible){
            $init = $this->newInitializer(Type::arrayOf($init->ty->base, $tok->ty->arrayLen), false);
        }

        $len = min($init->ty->arrayLen, $tok->ty->arrayLen);
        for ($i = 0; $i < $len; $i++){
            $init->children[$i]->expr = Node::newNum(isset($tok->str[$i])? ord($tok->str[$i]): 0, $tok);
        }
        return [$init, $tok->next];
    }

    public function countArrayInitElements(Token $tok, Type $ty): int
    {
        $dummy = $this->newInitializer($ty->base, false);
        for ($i = 0; [$consumed, $tok] = $this->consumeEnd($tok, $tok) and (! $consumed); $i++){
            if ($i > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            [$init, $tok] = $this->initializer2($tok, $tok, $dummy);
        }
        return $i;
    }

    /**
     * array-initializer1 = "{" initializer ("," initializer)* ","?"}"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function arrayInitializer1(Token $rest, Token $tok, Initializer $init): array
    {
        $tok = $this->tokenizer->skip($tok, '{');

        if ($init->isFlexible){
            $len = $this->countArrayInitElements($tok, $init->ty);
            $init = $this->newInitializer(Type::arrayOf($init->ty->base, $len), false);
        }

        for ($i = 0; [$consumed, $rest] = $this->consumeEnd($rest, $tok) and (! $consumed); $i++){
            if ($i > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            if ($i < $init->ty->arrayLen){
                [$init->children[$i], $tok] = $this->initializer2($tok, $tok, $init->children[$i]);
            } else {
                $tok = $this->skipExcessElement($tok);
            }
        }

        return [$init, $rest];
    }

    /**
     * array-initializer2 = initializer ("," initializer)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function arrayInitializer2(Token $rest, Token $tok, Initializer $init): array
    {
        if ($init->isFlexible){
            $len = $this->countArrayInitElements($tok, $init->ty);
            $init = $this->newInitializer(Type::arrayOf($init->ty->base, $len), false);
        }

        for ($i = 0; $i < $init->ty->arrayLen and (! $this->isEnd($tok)); $i++){
            if ($i > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            [$init->children[$i], $tok] = $this->initializer2($tok, $tok, $init->children[$i]);
        }
        return [$init, $tok];
    }

    /**
     * struct-initializer1 = "{" initializer ("," initializer)* ","? "}"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function structInitializer1(Token $rest, Token $tok, Initializer $init): array
    {
        $tok = $this->tokenizer->skip($tok, '{');

        $idx = 0;
        while ([$consumed, $rest] = $this->consumeEnd($rest, $tok) and (! $consumed)){
            if ($idx > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            if (isset($init->ty->members[$idx])){
                [$init->children[$idx], $tok] = $this->initializer2($tok, $tok, $init->children[$idx]);
                $idx++;
            } else {
                $tok = $this->skipExcessElement($tok);
            }
        }

        return [$init, $rest];
    }

    /**
     * struct-initializer2 = initializer ("," initializer)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function structInitializer2(Token $rest, Token $tok, Initializer $init): array
    {
        foreach ($init->ty->members as $idx => $mem){
            if ($this->isEnd($tok)){
                break;
            }
            if ($idx > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            [$init->children[$idx], $tok] = $this->initializer2($tok, $tok, $init->children[$idx]);
        }
        return [$init, $tok];
    }

    /**
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function unionInitializer(Token $rest, Token $tok, Initializer $init): array
    {
        if ($this->tokenizer->equal($tok, '{')){
            [$init->children[0], $rest] = $this->initializer2($tok, $tok->next, $init->children[0]);
            $rest = $this->tokenizer->skip($rest, '}');
        } else {
            [$init->children[0], $rest] = $this->initializer2($tok, $tok, $init->children[0]);
        }
        return [$init, $rest];
    }

    /**
     * initializer = string-initializer | array-initializer
     *             | struct-initializer | union-initializer
     *             | assign
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Initializer $init
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function initializer2(Token $rest, Token $tok, Initializer $init): array
    {
        if ($init->ty->kind === TypeKind::TY_ARRAY and $tok->kind === TokenKind::TK_STR){
            return $this->stringInitializer($rest, $tok, $init);
        }

        if ($init->ty->kind === TypeKind::TY_ARRAY){
            if ($this->tokenizer->equal($tok, '{')){
                return $this->arrayInitializer1($rest, $tok, $init);
            } else {
                return $this->arrayInitializer2($rest, $tok, $init);
            }
        }

        if ($init->ty->kind === TypeKind::TY_STRUCT){
            if ($this->tokenizer->equal($tok, '{')){
                return $this->structInitializer1($rest, $tok, $init);
            }

            [$expr, $rest] = $this->assign($rest, $tok);
            $expr->addType();
            if ($expr->ty->kind === TypeKind::TY_STRUCT){
                $init->expr = $expr;
                return [$init, $rest];
            }

            return $this->structInitializer2($rest, $tok, $init);
        }

        if ($init->ty->kind === TypeKind::TY_UNION){
            return $this->unionInitializer($rest, $tok, $init);
        }

        if ($this->tokenizer->equal($tok, '{')){
            [$init, $tok] = $this->initializer2($tok, $tok->next, $init);
            $rest = $this->tokenizer->skip($tok, '}');
            return [$init, $rest];
        }

        [$init->expr, $rest] = $this->assign($rest, $tok);
        return [$init, $rest];
    }

    public function copyStructType(Type $ty): Type
    {
        $t = clone $ty;

        $members = [];
        foreach ($t->members as $mem){
            $m = clone $mem;
            $members[] = $m;
        }
        $t->members = $members;
        return $t;
    }

    /**
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @param \Pcc\Ast\Obj $var
     * @return array{\Pcc\Ast\Initializer, \Pcc\Tokenizer\Token}
     */
    public function initializer(Token $rest, Token $tok, Type $ty, Obj $var): array
    {
        $init = $this->newInitializer($ty, true);
        [$init, $rest] = $this->initializer2($rest, $tok, $init);

        if (($ty->kind === TypeKind::TY_STRUCT or $ty->kind === TypeKind::TY_UNION) and $ty->isFlexible){
            $ty = $this->copyStructType($ty);

            $lastIdx = count($ty->members) - 1;
            $ty->members[$lastIdx]->ty = $init->children[$lastIdx]->ty;
            $ty->size += $ty->members[$lastIdx]->ty->size;

            $var->ty = $ty;
            return [$init, $rest];
        }

        $var->ty = $init->ty;
        return [$init, $rest];
    }

    public function initDesgExpr(InitDesg $desg, Token $tok): Node
    {
        if ($desg->var){
            return Node::newVarNode($desg->var, $tok);
        }

        if (count($desg->members)){
            $node = Node::newUnary(NodeKind::ND_MEMBER, $this->initDesgExpr($desg->next, $tok), $tok);
            $node->members = $desg->members;
            return $node;
        }

        $lhs = $this->initDesgExpr($desg->next, $tok);
        $rhs = Node::newNum($desg->idx, $tok);
        return Node::newUnary(NodeKind::ND_DEREF, $this->newAdd($lhs, $rhs, $tok), $tok);
    }

    public function createLVarInit(Initializer $init, Type $ty, InitDesg $desg, Token $tok): Node
    {
        if ($ty->kind === TypeKind::TY_ARRAY){
            $node = Node::newNode(NodeKind::ND_NULL_EXPR, $tok);
            for ($i = 0; $i < $ty->arrayLen; $i++){
                $desg2 = new InitDesg($desg, $i);
                $rhs = $this->createLVarInit($init->children[$i], $ty->base, $desg2, $tok);
                $node = Node::newBinary(NodeKind::ND_COMMA, $node, $rhs, $tok);
            }
            return $node;
        }

        if ($ty->kind === TypeKind::TY_STRUCT and (! $init->expr)){
            $node = Node::newNode(NodeKind::ND_NULL_EXPR, $tok);

            foreach ($ty->members as $idx => $mem){
                $desg2 = new InitDesg($desg, 0, [$mem]);
                $rhs = $this->createLVarInit($init->children[$idx], $mem->ty, $desg2, $tok);
                $node = Node::newBinary(NodeKind::ND_COMMA, $node, $rhs, $tok);
            }
            return $node;
        }

        if ($ty->kind === TypeKind::TY_UNION){
            $desg2 = new InitDesg($desg, 0, [$ty->members[0]]);
            return $this->createLVarInit($init->children[0], $ty->members[0]->ty, $desg2, $tok);
        }

        if (! $init->expr){
            return Node::newNode(NodeKind::ND_NULL_EXPR, $tok);
        }

        $lhs = $this->initDesgExpr($desg, $tok);
        return Node::newBinary(NodeKind::ND_ASSIGN, $lhs, $init->expr, $tok);
    }

    /**
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Obj $var
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function lVarInitializer(Token $rest, Token $tok, Obj $var): array
    {
        [$init, $rest] = $this->initializer($rest, $tok, $var->ty, $var);
        $desg = new InitDesg(null, 0, [], $var);

        $lhs = Node::newNode(NodeKind::ND_MEMZERO, $tok);
        $lhs->var = $var;

        $rhs = $this->createLVarInit($init, $var->ty, $desg, $tok);
        return [Node::newBinary(NodeKind::ND_COMMA, $lhs, $rhs, $tok), $rest];
    }

    public function writeBuf(string $buf, int $offset, int $val, int $sz): string
    {
        $bufSize = strlen($buf);
        if ($bufSize < $offset){
            $buf .= str_repeat("\0", $offset - $bufSize);
        }
        switch ($sz){
            case 1:
                return $buf.pack('C', $val);
            case 2:
                return $buf.pack('v', $val);
            case 4:
                return $buf.pack('V', $val);
            case 8:
                return $buf.pack('P', $val);
            default:
                Console::unreachable(__FILE__, __LINE__);
        }
    }


    /**
     * @param \Pcc\Ast\Relocation[] $rels
     * @param \Pcc\Ast\Initializer $init
     * @param \Pcc\Ast\Type $ty
     * @param string $buf
     * @param int $offset
     * @return array{0: string, 1: \Pcc\Ast\Relocation[]}
     */
    public function writeGVarData(array $rels, Initializer $init, Type $ty, string $buf, int $offset): array
    {
        $cur = null;
        if ($ty->kind === TypeKind::TY_ARRAY){
            for ($i = 0; $i < $ty->arrayLen; $i++){
                [$buf, $rels] = $this->writeGVarData($rels, $init->children[$i], $ty->base, $buf, $offset + $ty->base->size * $i);
            }
            return [$buf, $rels];
        }

        if ($ty->kind === TypeKind::TY_STRUCT){
            foreach ($ty->members as $idx => $mem){
                [$buf, $rels] = $this->writeGVarData($rels, $init->children[$idx], $mem->ty, $buf, $offset + $mem->offset);
            }
            return [$buf, $rels];
        }

        if ($ty->kind === TypeKind::TY_UNION){
            return $this->writeGVarData($rels, $init->children[0], $ty->members[0]->ty, $buf, $offset);
        }

        if (! $init->expr){
            return [$buf, $rels];
        }

        [$gmpVal, $label] = $this->evaluate2($init->expr, '');

        if (! $label){
            $buf = $this->writeBuf($buf, $offset, PccGMP::toSignedInt($gmpVal), $ty->size);
            return [$buf, $rels];
        }

        $rels[] = new Relocation($offset, $label, PccGMP::toSignedInt($gmpVal));
        return [$buf, $rels];
    }

    public function gVarInitializer(Token $rest, Token $tok, Obj $var): Token
    {
        [$init, $rest] = $this->initializer($rest, $tok, $var->ty, $var);

        $rels = [];
        [$buf, $rels] = $this->writeGVarData($rels, $init, $var->ty, '', 0);
        if (strlen($buf) < $var->ty->size){
            $buf .= str_repeat("\0", $var->ty->size - strlen($buf));
        }
        $var->initData = $buf;
        $var->rels = $rels;
        return $rest;
    }

    public function isTypeName(Token $tok): bool
    {
        if (in_array($tok->str, [
            'void', '_Bool', 'char', 'short', 'int', 'long', 'struct', 'union',
            'typedef', 'enum', 'static', 'extern', '_Alignas', 'signed', 'unsigned',
        ])){
            return true;
        }
        return $this->findTypedef($tok) !== null;
    }

    /**
     * stmt = "return" expr? ";"
     *      | "if" "(" expr ")" stmt ("else" stmt)?
     *      | "switch" "(" expr ")" stmt
     *      | "case" const-expr ":" stmt
     *      | "default" ":" stmt
     *      | "for" "(" expr-stmt expr? ";" expr? ")" stmt
     *      | "while" "(" expr ")" stmt
     *      | "do" stmt "while" "(" expr ")" ";"
     *      | "goto" ident ";"
     *      | "break" ";"
     *      | "continue" ";"
     *      | ident ":" stmt
     *      | "{" compound-stmt
     *      | expr-stmt
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function stmt(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, 'return')){
            $node = Node::newNode(NodeKind::ND_RETURN, $tok);
            [$consumed, $rest] = $this->tokenizer->consume($rest, $tok->next, ';');
            if ($consumed){
                return [$node, $rest];
            }

            [$exp, $tok] = $this->expr($tok, $tok->next);
            $rest = $this->tokenizer->skip($tok, ';');

            $exp->addType();
            $node->lhs = Node::newCast($exp, $this->currentFn->ty->returnTy);
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok,'if')){
            $node = Node::newNode(NodeKind::ND_IF, $tok);
            $tok = $this->tokenizer->skip($tok->next, '(');
            [$node->cond, $tok] = $this->expr($tok, $tok);
            $tok = $this->tokenizer->skip($tok, ')');
            [$node->then, $tok] = $this->stmt($tok, $tok);
            if ($this->tokenizer->equal($tok, 'else')){
                [$node->els, $tok] = $this->stmt($tok, $tok->next);
            }
            return [$node, $tok];
        }

        if ($this->tokenizer->equal($tok, 'switch')){
            $node = Node::newNode(NodeKind::ND_SWITCH, $tok);
            $tok = $this->tokenizer->skip($tok->next, '(');
            [$node->cond, $tok] = $this->expr($tok, $tok);
            $tok = $this->tokenizer->skip($tok, ')');

            $sw = $this->currentSwitch;
            $this->currentSwitch = $node;

            $brk = $this->brkLabel;
            $this->brkLabel = $node->brkLabel = $this->newUniqueName();

            [$node->then, $rest] = $this->stmt($rest, $tok);

            $this->currentSwitch = $sw;
            $this->brkLabel = $brk;
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'case')){
            if (! $this->currentSwitch){
                Console::errorTok($tok, 'stray case');
            }

            $node = Node::newNode(NodeKind::ND_CASE, $tok);
            [$gmpVal, $tok] = $this->constExpr($tok, $tok->next);
            $tok = $this->tokenizer->skip($tok, ':');
            $node->label = $this->newUniqueName();
            [$node->lhs, $rest] = $this->stmt($rest, $tok);
            $node->val = PccGMP::toSignedInt($gmpVal);
            $node->gmpVal = $gmpVal;
            array_unshift($this->currentSwitch->cases, $node);

            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'default')){
            if (! $this->currentSwitch){
                Console::errorTok($tok, 'stray default');
            }

            $node = Node::newNode(NodeKind::ND_CASE, $tok);
            $tok = $this->tokenizer->skip($tok->next, ':');
            $node->label = $this->newUniqueName();
            [$node->lhs, $rest] = $this->stmt($rest, $tok);
            $this->currentSwitch->defaultCase = $node;

            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'for')){
            $node = Node::newNode(NodeKind::ND_FOR, $tok);
            $tok = $this->tokenizer->skip($tok->next, '(');

            $this->enterScope();

            $brk = $this->brkLabel;
            $cont = $this->contLabel;
            $this->brkLabel = $node->brkLabel = $this->newUniqueName();
            $this->contLabel = $node->contLabel = $this->newUniqueName();

            if ($this->isTypeName($tok)){
                [$basety, $tok] = $this->typespec($tok, $tok, null);
                [$node->init, $tok] = $this->declaration($tok, $tok, $basety, null);
            } else {
                [$node->init, $tok] = $this->exprStmt($tok, $tok);
            }

            if (! $this->tokenizer->equal($tok, ';')){
                [$node->cond, $tok] = $this->expr($tok, $tok);
            }
            $tok =$this->tokenizer->skip($tok, ';');

            if (! $this->tokenizer->equal($tok, ')')){
                [$node->inc, $tok] = $this->expr($tok, $tok);
            }
            $tok = $this->tokenizer->skip($tok, ')');

            [$node->then, $rest] = $this->stmt($rest, $tok);

            $this->leaveScope();
            $this->brkLabel = $brk;
            $this->contLabel = $cont;

            return [$node, $rest];
        }

        // "while" "(" expr ")" stmt
        if ($this->tokenizer->equal($tok, 'while')){
            $node = Node::newNode(NodeKind::ND_FOR, $tok);
            $tok = $this->tokenizer->skip($tok->next, '(');
            [$node->cond, $tok] = $this->expr($tok, $tok);
            $tok = $this->tokenizer->skip($tok, ')');

            $brk = $this->brkLabel;
            $cont = $this->contLabel;
            $this->brkLabel = $node->brkLabel = $this->newUniqueName();
            $this->contLabel = $node->contLabel = $this->newUniqueName();

            [$node->then, $rest] = $this->stmt($rest, $tok);

            $this->brkLabel = $brk;
            $this->contLabel = $cont;

            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'do')){
            $node = Node::newNode(NodeKind::ND_DO, $tok);

            $brk = $this->brkLabel;
            $cont = $this->contLabel;
            $this->brkLabel = $node->brkLabel = $this->newUniqueName();
            $this->contLabel = $node->contLabel = $this->newUniqueName();

            [$node->then, $tok] = $this->stmt($tok, $tok->next);

            $this->brkLabel = $brk;
            $this->contLabel = $cont;

            $tok = $this->tokenizer->skip($tok, 'while');
            $tok = $this->tokenizer->skip($tok, '(');
            [$node->cond, $tok] = $this->expr($tok, $tok);
            $tok = $this->tokenizer->skip($tok, ')');
            $rest = $this->tokenizer->skip($tok, ';');
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'goto')){
            $node = Node::newNode(NodeKind::ND_GOTO, $tok);
            $node->label = $this->getIdent($tok->next);
            array_unshift($this->gotos, $node);
            $rest = $this->tokenizer->skip($tok->next->next, ';');
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'break')){
            if (! $this->brkLabel){
                Console::errorTok($tok, 'stray break');
            }
            $node = Node::newNode(NodeKind::ND_GOTO, $tok);
            $node->uniqueLabel = $this->brkLabel;
            $rest = $this->tokenizer->skip($tok->next, ';');
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'continue')){
            if (! $this->contLabel){
                Console::errorTok($tok, 'stray continue');
            }
            $node = Node::newNode(NodeKind::ND_GOTO, $tok);
            $node->uniqueLabel = $this->contLabel;
            $rest = $this->tokenizer->skip($tok->next, ';');
            return [$node, $rest];
        }

        if ($tok->isKind(TokenKind::TK_IDENT) and $this->tokenizer->equal($tok->next, ':')){
            $node = Node::newNode(NodeKind::ND_LABEL, $tok);
            $node->label = $tok->str;
            $node->uniqueLabel = $this->newUniqueName();
            [$node->lhs, $rest] = $this->stmt($rest, $tok->next->next);
            array_unshift($this->labels, $node);
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, '{')){
            return $this->compoundStmt($rest, $tok->next);
        }

        return $this->exprStmt($rest, $tok);
    }

    /**
     * compound-stmt = (typedef | declaration | stmt)* "}"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function compoundStmt(Token $rest, Token $tok): array
    {
        $node = Node::newNode(NodeKind::ND_BLOCK, $tok);

        $this->enterScope();

        $nodes = [];
        while (! $this->tokenizer->equal($tok, '}')){
            if ($this->isTypeName($tok) and (! $this->tokenizer->equal($tok->next, ':'))){
                $attr = new VarAttr();
                [$basety, $tok] = $this->typespec($tok, $tok, $attr);

                if ($attr->isTypedef){
                    $tok = $this->parseTypedef($tok, $basety);
                    continue;
                }

                if ($this->isFunction($tok)){
                    $tok = $this->func($tok, $basety, $attr);
                    continue;
                }

                if ($attr->isExtern){
                    $tok = $this->globalVariable($tok, $basety, $attr);
                    continue;
                }

                [$n, $tok] = $this->declaration($tok, $tok, $basety, $attr);
            } else {
                [$n, $tok] = $this->stmt($tok, $tok);
            }
            $n->addType();
            $nodes[] = $n;
        }

        $this->leaveScope();

        $node->body = $nodes;
        return [$node, $tok->next];
    }

    /**
     * expr-stmt = expr? ";"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function exprStmt(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, ';')){
            return [Node::newNode(NodeKind::ND_BLOCK, $tok), $tok->next];
        }

        $node = Node::newNode(NodeKind::ND_EXPR_STMT, $tok);
        [$node->lhs, $tok] = $this->expr($tok, $tok);
        $rest = $this->tokenizer->skip($tok, ';');
        return [$node, $rest];
    }

    /**
     * expr = assign ("," expr)?
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function expr(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->assign($tok, $tok);

        if ($this->tokenizer->equal($tok, ',')){
            [$expr, $rest] = $this->expr($rest, $tok->next);
            $node = Node::newBinary(NodeKind::ND_COMMA, $node, $expr, $tok);
            return [$node, $rest];
        }
        $rest = $tok;
        return [$node, $rest];
    }

    public function evaluate(Node $node): GMP
    {
        [$val, ] = $this->evaluate2($node, null);
        return $val;
    }

    /**
     * @param \Pcc\Ast\Node $node
     * @param ?string $label
     * @return array{0: GMP, 1: $string}
     */
    public function evaluate2(Node $node, ?string $label): array
    {
        $node->addType();

        $val = null;
        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch ($node->kind){
            case NodeKind::ND_ADD:
                [$val1, $label] = $this->evaluate2($node->lhs, $label);
                $val = gmp_add($val1, $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_SUB:
                [$val1, $label] = $this->evaluate2($node->lhs, $label);
                $val = gmp_sub($val1, $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_MUL:
                $val = gmp_mul($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_DIV:
                $val = gmp_div_q($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_MOD:
                $val = gmp_div_r($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_BITAND:
                $val = gmp_and($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_BITOR:
                $val = gmp_or($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_BITXOR:
                $val = gmp_xor($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_SHL:
                $val = PccGMP::shiftL($this->evaluate($node->lhs), PccGMP::toSignedInt($this->evaluate($node->rhs)));
                break;
            case NodeKind::ND_SHR:
                $val = PccGMP::shiftR($this->evaluate($node->lhs), PccGMP::toSignedInt($this->evaluate($node->rhs)));
                break;
            case NodeKind::ND_EQ:
                $val = (gmp_cmp($this->evaluate($node->lhs), $this->evaluate($node->rhs)) === 0);
                break;
            case NodeKind::ND_NE:
                $val = (gmp_cmp($this->evaluate($node->lhs), $this->evaluate($node->rhs)) !== 0);
                break;
            case NodeKind::ND_LT:
                $val = (gmp_cmp($this->evaluate($node->lhs), $this->evaluate($node->rhs)) < 0);
                break;
            case NodeKind::ND_LE:
                $val = (gmp_cmp($this->evaluate($node->lhs), $this->evaluate($node->rhs)) <= 0);
                break;
            case NodeKind::ND_COND:
                if (gmp_cmp($this->evaluate($node->cond), 0) !== 0){
                    [$val, $label] = $this->evaluate2($node->then, $label);
                } else {
                    [$val, $label] = $this->evaluate2($node->els, $label);
                }
                break;
            case NodeKind::ND_COMMA:
                [$val, $label] = $this->evaluate2($node->rhs, $label);
                break;
            case NodeKind::ND_NOT:
                $val = (gmp_cmp($this->evaluate($node->lhs), 0) === 0);
                break;
            case NodeKind::ND_BITNOT:
                $val = gmp_sub(gmp_neg($this->evaluate($node->lhs)), gmp_init(1));
                break;
            case NodeKind::ND_LOGAND:
                $val = PccGMP::logicalAnd($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_LOGOR:
                $val = PccGMP::logicalOr($this->evaluate($node->lhs), $this->evaluate($node->rhs));
                break;
            case NodeKind::ND_CAST:
                [$val, $label] = $this->evaluate2($node->lhs, $label);
                if ($node->ty->isInteger()){
                    return [
                        match ($node->ty->size){
                            1 => gmp_and($val, 0xff),
                            2 => gmp_and($val, 0xffff),
                            4 => gmp_and($val, gmp_init('0xffffffff')),
                            8 => gmp_and($val, gmp_init('0xffffffffffffffff')),
                        },
                        $label
                    ];
                }
                break;
            case NodeKind::ND_ADDR:
                [$val, $label] = $this->evalRval($node->lhs, $label);
                break;
            case NodeKind::ND_MEMBER:
                if (is_null($label)){
                    Console::errorTok($node->tok, 'not a compile-time constant (ND_MEMBER)');
                }
                if ($node->ty->kind !== TypeKind::TY_ARRAY){
                    Console::errorTok($node->tok, 'invalid initializer');
                }
                [$val, $label] = $this->evalRval($node->lhs, $label);
                $val = gmp_add($val, $node->members[0]->offset);
                break;
            case NodeKind::ND_VAR:
                if (is_null($label)){
                    Console::errorTok($node->tok, 'not a compile-time constant (ND_VAR)');
                }
                if ($node->var->ty->kind !== TypeKind::TY_ARRAY and $node->var->ty->kind !== TypeKind::TY_FUNC){
                    Console::errorTok($node->tok, 'invalid initializer');
                }
                $val = gmp_init(0);
                $label = $node->var->name;
                break;
            case NodeKind::ND_NUM:
                $val = $node->gmpVal;
                break;
        }
        if (is_null($val)){
            Console::errorTok($node->tok, 'not a compile-time constant (E)');
        }

        if (in_array($val, [true, false])){
            $val = gmp_init($val ? 1 : 0);
        }
        $modValue = PccGMP::overFlow($val, 64);

        return [$modValue, $label];
    }

    /**
     * @param \Pcc\Ast\Node $node
     * @param ?string $label
     * @return array{0: GMP, 1: string}
     */
    public function evalRval(Node $node, ?string $label): array
    {
        /** @noinspection PhpUncoveredEnumCasesInspection */
        switch($node->kind){
            case NodeKind::ND_VAR:
                if ($node->var->isLocal){
                    Console::errorTok($node->tok, 'not a compile-time constant');
                }
                $label = $node->var->name;
                return [gmp_init(0), $label];
            case NodeKind::ND_DEREF:
                return $this->evaluate2($node->lhs, $label);
            case NodeKind::ND_MEMBER:
                [$rval, $label] = $this->evalRval($node->lhs, $label);
                return [gmp_add(gmp_init($rval), gmp_init($node->members[0]->offset)), $label];
        }
        Console::errorTok($node->tok, 'invalid initializer');
    }

    /**
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: GMP, 1: \Pcc\Tokenizer\Token}
     */
    public function constExpr(Token $rest, Token $tok): array
    {
        [$node, $rest] = $this->conditional($rest, $tok);
        $val = $this->evaluate($node);
        return [$val, $rest];
    }

    /**
     * Convert `A op= B` to `tmp = &A, *tmp = *tmp op B`
     * where tmp is a fresh pointer variable.
     */
    public function toAssign(Node $binary): Node
    {
        $binary->lhs->addType();
        $binary->rhs->addType();
        $tok = $binary->tok;

        $var = $this->newLvar('', Type::pointerTo($binary->lhs->ty));
        $expr1 = Node::newBinary(NodeKind::ND_ASSIGN, Node::newVarNode($var, $tok),
            Node::newUnary(NodeKind::ND_ADDR, $binary->lhs, $tok), $tok);
        $expr2 = Node::newBinary(NodeKind::ND_ASSIGN,
            Node::newUnary(NodeKind::ND_DEREF, Node::newVarNode($var, $tok), $tok),
            Node::newBinary($binary->kind,
                Node::newUnary(NodeKind::ND_DEREF, Node::newVarNode($var, $tok), $tok),
                $binary->rhs,
                $tok),
            $tok);

        return Node::newBinary(NodeKind::ND_COMMA, $expr1, $expr2, $tok);
    }


    /**
     * assign    = conditional (assign-op assign)?
     * assign-op = "=" | "+=" | "-=" | "*=" | "/=" | "%=" | "&=" | "|=" | "^="
     *           | "<<" | ">>"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function assign(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->conditional($tok, $tok);

        if ($this->tokenizer->equal($tok, '=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [Node::newBinary(NodeKind::ND_ASSIGN, $node, $assign, $tok), $rest];
        }

        if ($this->tokenizer->equal($tok, '+=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign($this->newAdd($node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '-=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign($this->newSub($node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '*=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_MUL, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '/=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_DIV, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '%=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_MOD, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '&=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_BITAND, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '|=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_BITOR, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '^=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_BITXOR, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '<<=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_SHL, $node, $assign, $tok)), $rest];
        }

        if ($this->tokenizer->equal($tok, '>>=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [$this->toAssign(Node::newBinary(NodeKind::ND_SHR, $node, $assign, $tok)), $rest];
        }

        return [$node, $tok];
    }

    /**
     * conditional = logor ("?" expr ":" conditional)?
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function conditional(Token $rest, Token $tok): array
    {
        [$cond, $tok] = $this->logor($tok, $tok);

        if (! $this->tokenizer->equal($tok, '?')){
            return [$cond, $tok];
        }

        $node = Node::newNode(NodeKind::ND_COND, $tok);
        $node->cond = $cond;
        [$node->then, $tok] = $this->expr($tok, $tok->next);
        $tok = $this->tokenizer->skip($tok, ':');
        [$node->els, $rest] = $this->conditional($rest, $tok);

        return [$node, $rest];
    }

    /**
     * logor = logand ("||" logand)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function logor(Token $rest, $tok): array
    {
        [$node, $tok] = $this->logand($tok, $tok);
        while ($this->tokenizer->equal($tok, '||')){
            $start = $tok;
            [$logand, $tok] = $this->logand($tok, $tok->next);
            $node = Node::newBinary(NodeKind::ND_LOGOR, $node, $logand, $start);
        }
        return [$node, $tok];
    }

    /**
     * logand = bitor ("&&" bitor)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function logand(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->bitor($tok, $tok);
        while ($this->tokenizer->equal($tok, '&&')){
            $start = $tok;
            [$bitor, $tok] = $this->bitor($tok, $tok->next);
            $node = Node::newBinary(NodeKind::ND_LOGAND, $node, $bitor, $start);
        }
        return [$node, $tok];
    }

    /**
     * bitor = bitxor ("|" bitxor)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function bitor(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->bitxor($tok, $tok);
        while ($this->tokenizer->equal($tok, '|')){
            $start = $tok;
            [$bitxor, $tok] = $this->bitxor($tok, $tok->next);
            $node = Node::newBinary(NodeKind::ND_BITOR, $node, $bitxor, $start);
        }
        return [$node, $tok];
    }

    /**
     * bitxor = bitand ("^" bitand)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function bitxor(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->bitand($tok, $tok);
        while ($this->tokenizer->equal($tok, '^')){
            $start = $tok;
            [$bitand, $tok] = $this->bitand($tok, $tok->next);
            $node = Node::newBinary(NodeKind::ND_BITXOR, $node, $bitand, $start);
        }
        return [$node, $tok];
    }

    /**
     * bitand = equality ("&" equality)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function bitand(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->equality($tok, $tok);
        while ($this->tokenizer->equal($tok, '&')){
            $start = $tok;
            [$equality, $tok] = $this->equality($tok, $tok->next);
            $node = Node::newBinary(NodeKind::ND_BITAND, $node, $equality, $start);
        }
        return [$node, $tok];
    }

    /**
     * equality = relational ("==" relational | "!=" relational)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function equality(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->relational($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '==')){
                [$relational, $tok] = $this->relational($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_EQ, $node, $relational, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '!=')){
                [$relational, $tok] = $this->relational($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_NE, $node, $relational, $start);
                continue;
            }

            return [$node, $tok];
        }
    }

    /**
     * relational = shift ("<" shift | "<=" shift | ">" shift | ">=" shift)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function relational(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->shift($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '<')){
                [$shift, $tok] = $this->shift($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LT, $node, $shift, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '<=')){
                [$shift, $tok] = $this->shift($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LE, $node, $shift, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '>')){
                [$shift, $tok] = $this->shift($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LT, $shift, $node, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '>=')){
                [$shift, $tok] = $this->shift($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LE, $shift, $node, $start);
                continue;
            }

            return [$node, $tok];
        }
    }

    /**
     * shift = add ("<<" add | ">>" add)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function shift(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->add($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '<<')){
                [$add, $tok] = $this->add($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_SHL, $node, $add, $start);
                continue;
            }

            if ($this->tokenizer->equal($tok, '>>')){
                [$add, $tok] = $this->add($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_SHR, $node, $add, $start);
                continue;
            }

            return [$node, $tok];
        }
    }

    public function newAdd(Node $lhs, Node $rhs, Token $tok): Node
    {
        $lhs->addType();
        $rhs->addType();

        // num + num
        if ($lhs->ty->isInteger() and $rhs->ty->isInteger()){
            return Node::newBinary(NodeKind::ND_ADD, $lhs, $rhs, $tok);
        }
        if ($lhs->ty->base and $rhs->ty->base){
            Console::errorTok($tok, 'invalid operands');
        }

        // Canonicalize 'num + ptr' to 'ptr + num'.
        if (! $lhs->ty->base and $rhs->ty->base){
            $tmp = $lhs;
            $lhs = $rhs;
            $rhs = $tmp;
        }

        // ptr + num
        $rhs = Node::newBinary(NodeKind::ND_MUL, $rhs, Node::newNum($lhs->ty->base->size, $tok), $tok);
        return Node::newBinary(NodeKind::ND_ADD, $lhs, $rhs, $tok);
    }

    public function newSub(Node $lhs, Node $rhs, Token $tok): ?Node
    {
        $lhs->addType();
        $rhs->addType();

        // num - num
        if ($lhs->ty->isInteger() and $rhs->ty->isInteger()){
            return Node::newBinary(NodeKind::ND_SUB, $lhs, $rhs, $tok);
        }

        // ptr - num
        if ($lhs->ty->base and $rhs->ty->isInteger()){
            $rhs = Node::newBinary(NodeKind::ND_MUL, $rhs, Node::newNum($lhs->ty->base->size, $tok), $tok);
            $rhs->addType();
            $node = Node::newBinary(NodeKind::ND_SUB, $lhs, $rhs, $tok);
            $node->ty = $lhs->ty;
            return $node;
        }

        // ptr - ptr
        if ($lhs->ty->base and $rhs->ty->base){
            $node = Node::newBinary(NodeKind::ND_SUB, $lhs, $rhs, $tok);
            $node->ty = Type::tyLong();
            return Node::newBinary(NodeKind::ND_DIV, $node, Node::newNum($lhs->ty->base->size, $tok), $tok);
        }

        Console::errorTok($tok, 'invalid operands');
    }

    /**
     * add = mul ("+" mul | "-" mul)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function add(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->mul($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '+')){
                [$mul, $tok] = $this->mul($tok, $tok->next);
                $node = $this->newAdd($node, $mul, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '-')){
                [$mul, $tok] = $this->mul($tok, $tok->next);
                $node = $this->newSub($node, $mul, $start);
                continue;
            }

            return [$node, $tok];
        }
    }

    /**
     * mul = cast ("*" unary | "/" cast)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function mul(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->cast($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '*')){
                [$cast, $tok] = $this->cast($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_MUL, $node, $cast, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '/')){
                [$cast, $tok] = $this->cast($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_DIV, $node, $cast, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '%')){
                [$cast, $tok] = $this->cast($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_MOD, $node, $cast, $start);
                continue;
            }

            return [$node, $tok];
        }
    }

    /**
     * compound-literal = initializer "}"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @param \Pcc\Tokenizer\Token $start
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function compoundLiteral(Token $rest, Token $tok, Type $ty, Token $start): array
    {
        if ($this->scopeDepth === 0){
            $var = $this->newAnonGVar($ty);
            $rest = $this->gVarInitializer($rest, $tok, $var);
            return [Node::newVarNode($var, $start), $rest];
        }

        $var = $this->newLvar($this->newUniqueName(), $ty);
        [$lhs, $rest] = $this->lVarInitializer($rest, $tok, $var);
        $rhs = Node::newVarNode($var, $tok);
        return [Node::newBinary(NodeKind::ND_COMMA, $lhs, $rhs, $tok), $rest];
    }

    /**
     * cast = "(" type-name ")" "{" compound-literal
     *      | "(" type-name ")" cast
     *      | unary
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function cast(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, '(') and $this->isTypeName($tok->next)){
            $start = $tok;
            [$ty, $tok] = $this->typename($tok, $tok->next);
            $tok = $this->tokenizer->skip($tok, ')');

            // compound literal
            if ($this->tokenizer->equal($tok, '{')){
                return $this->compoundLiteral($rest, $tok, $ty, $start);
            }

            // type cast
            [$cast, $rest] = $this->cast($rest, $tok);
            $node = Node::newCast($cast, $ty);
            $node->tok = $start;
            return [$node, $rest];
        }

        return $this->unary($rest, $tok);
    }

    /**
     * unary = ("+" | "-" | "*" | "&" | "!" | "~") cast
     *       | ("++" | "--") unary
     *       | postfix
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function unary(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, '+')){
            return $this->cast($rest, $tok->next);
        }
        if ($this->tokenizer->equal($tok, '-')){
            [$cast, $rest] = $this->cast($rest, $tok->next);
            return [Node::newBinary(NodeKind::ND_SUB, Node::newNum(0, $tok), $cast, $tok), $rest];
        }
        if ($this->tokenizer->equal($tok, '&')){
            [$cast, $rest] = $this->cast($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_ADDR, $cast, $tok), $rest];
        }
        if ($this->tokenizer->equal($tok, '*')){
            [$cast, $rest] = $this->cast($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_DEREF, $cast, $tok), $rest];
        }
        if ($this->tokenizer->equal($tok, '!')){
            [$cast, $rest] = $this->cast($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_NOT, $cast, $tok), $rest];
        }
        if ($this->tokenizer->equal($tok, '~')){
            [$cast, $rest] = $this->cast($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_BITNOT, $cast, $tok), $rest];
        }

        // Read ++i as i += 1
        if ($this->tokenizer->equal($tok, '++')){
            [$unary, $rest] = $this->unary($rest, $tok->next);
            return [$this->toAssign($this->newAdd($unary, Node::newNum(1, $tok), $tok)), $rest];
        }

        // Read --i as i -= 1
        if ($this->tokenizer->equal($tok, '--')){
            [$unary, $rest] = $this->unary($rest, $tok->next);
            return [$this->toAssign($this->newSub($unary, Node::newNum(1, $tok), $tok)), $rest];
        }

        return $this->postfix($rest, $tok);
    }

    // struct-members = (typespec declarator (","  declarator)* ";")*
    public function structMembers(Token $rest, Token $tok, Type $ty): Token
    {
        $members = [];

        while (! $this->tokenizer->equal($tok, '}')){
            $attr = new VarAttr();
            [$basety, $tok] = $this->typespec($tok, $tok, $attr);
            $first = true;

            while ([$consumed, $tok] = $this->tokenizer->consume($tok, $tok, ';') and (! $consumed)){
                if (! $first){
                    $tok = $this->tokenizer->skip($tok, ',');
                }
                $first = false;

                [$declarator, $tok] = $this->declarator($tok, $tok, $basety);

                $mem = new Member();
                $mem->ty = $declarator;
                $mem->name = $mem->ty->name;
                $mem->align = $attr->align?: $mem->ty->align;
                $members[] = $mem;
            }
        }

        $lastMemberIdx = count($members) - 1;
        if (count($members) and $members[$lastMemberIdx]->ty->kind === TypeKind::TY_ARRAY and $members[$lastMemberIdx]->ty->arrayLen < 0){
            $members[$lastMemberIdx]->ty = Type::arrayOf($members[$lastMemberIdx]->ty->base, 0);
            $ty->isFlexible = true;
        }

        $ty->members = $members;
        return $tok->next;
    }

    /**
     * struct-union-decl = ident? ("{" struct-members)?
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function structUnionDecl(Token $rest, Token $tok): array
    {
        // Read a tag.
        $tag = null;
        if ($tok->isKind(TokenKind::TK_IDENT)){
            $tag = $tok;
            $tok = $tok->next;
        }

        if ($tag and (! $this->tokenizer->equal($tok, '{'))){
            $rest = $tok;

            $sc = $this->findTag($tag);
            if ($sc){
                return [$sc->ty, $rest];
            }

            $ty = Type::structType();
            $ty->size = -1;
            $ty->name = $tag;
            // In pcc, getStructMember() will call findTag().
            // $this->pushTagScope($tag, $ty);
            return [$ty, $rest];
        }

        $tok = $this->tokenizer->skip($tok, '{');

        // Construct a struct object.
        $ty = Type::structType();
        $rest = $this->structMembers($rest, $tok, $ty);

        if ($tag){
            // If this is a redefinition, overwrite the previous type.
            // Otherwise, register the struct type.
            $sc = $this->findTag($tag);
            if ($sc and $sc->depth === $this->scopeDepth){
                $sc->ty = $ty;
                return [$sc->ty, $rest];
            }

            $this->pushTagScope($tag, $ty);
        }

        return [$ty, $rest];
    }

    /**
     * struct-decl = struct-union-decl
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function structDecl(Token $rest, Token $tok): array
    {
        [$ty, $rest] = $this->structUnionDecl($rest, $tok);
        $ty->kind = TypeKind::TY_STRUCT;

        if ($ty->size < 0){
            return [$ty, $rest];
        }

        $offset = 0;
        foreach ($ty->members as $mem){
            $offset = Align::alignTo($offset, $mem->align);
            $mem->offset = $offset;
            $offset += $mem->ty->size;

            if ($ty->align < $mem->align){
                $ty->align = $mem->align;
            }
        }
        $ty->size = Align::alignTo($offset, $ty->align);

        return [$ty, $rest];
    }

    /**
     * union-decl = struct-union-decl
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function unionDecl(Token $rest, Token $tok): array
    {
        [$ty, $rest] = $this->structUnionDecl($rest, $tok);
        $ty->kind = TypeKind::TY_UNION;

        if ($ty->size < 0){
            return [$ty, $rest];
        }

        foreach ($ty->members as $mem){
            $mem->offset = 0;
            if ($ty->align < $mem->align){
                $ty->align = $mem->align;
            }
            if ($ty->size < $mem->ty->size){
                $ty->size = $mem->ty->size;
            }
        }
        $ty->size = Align::alignTo($ty->size, $ty->align);

        return [$ty, $rest];
    }

    public function getStructMember(Type $ty, Token $tok): Member
    {
        if ($ty->size === -1){
            $sc = $this->findTag($ty->name);
            $ty = $sc->ty;
        }

        foreach ($ty->members as $mem){
            if ($mem->name->str === $tok->str){
                return $mem;
            }
        }
        Console::errorTok($tok, 'no such member');
    }

    public function structRef(Node $lhs, Token $tok): Node
    {
        $lhs->addType();
        if ($lhs->ty->kind !== TypeKind::TY_STRUCT and $lhs->ty->kind !== TypeKind::TY_UNION){
            Console::errorTok($tok, 'not a struct nor a union');
        }

        $node = Node::newUnary(NodeKind::ND_MEMBER, $lhs, $tok);
        $node->members = [$this->getStructMember($lhs->ty, $tok)];
        return $node;
    }

    // Convert A++ to `(typeof A)((A += 1) - 1)`
    public function newIncDec(Node $node, Token $tok, int $addend): Node
    {
        return Node::newCast($this->newAdd($this->toAssign($this->newAdd($node, Node::newNum($addend, $tok), $tok)),
            Node::newNum(-1 * $addend, $tok), $tok),
            $node->ty);
    }

    /**
     * postfix = primary ("[" expr "]" | "." ident | "->" ident | "++" | "--")*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function postfix(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->primary($tok, $tok);

        for (;;){
            if ($this->tokenizer->equal($tok, '[')){
                // x[y] is short for *(x+y)
                $start = $tok;
                [$idx, $tok] = $this->expr($tok, $tok->next);
                $tok = $this->tokenizer->skip($tok, ']');
                $node = Node::newUnary(NodeKind::ND_DEREF, $this->newAdd($node, $idx, $start), $start);
                continue;
            }

            if ($this->tokenizer->equal($tok, '.')){
                $node = $this->structRef($node, $tok->next);
                $tok = $tok->next->next;
                continue;
            }

            if ($this->tokenizer->equal($tok, '->')){
                // x->y is short for (*x).y
                $node = Node::newUnary(NodeKind::ND_DEREF, $node, $tok);
                $node = $this->structRef($node, $tok->next);
                $tok = $tok->next->next;
                continue;
            }

            if ($this->tokenizer->equal($tok, '++')){
                $node = $this->newIncDec($node, $tok, 1);
                $tok = $tok->next;
                continue;
            }

            if ($this->tokenizer->equal($tok, '--')){
                $node = $this->newIncDec($node, $tok, -1);
                $tok = $tok->next;
                continue;
            }

            return [$node, $tok];
        }
    }

    /**
     * funcall = ident "(" (assign ("," assign)*)? ")"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function funcall(Token $rest, Token $tok): array
    {
        $start = $tok;
        $tok = $tok->next->next;

        $sc = $this->findVar($start);
        if (! $sc){
            Console::errorTok($start, 'implicit declaration of a function');
        }
        if ((! $sc->var) or $sc->var->ty->kind !== TypeKind::TY_FUNC){
            Console::errorTok($start, 'not a function');
        }

        $ty = $sc->var->ty;
        $paramTy = $ty->params;

        $nodes = [];
        while(! $this->tokenizer->equal($tok, ')')){
            if (count($nodes) > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            [$arg, $tok] = $this->assign($tok, $tok);
            $arg->addType();

            if (isset($paramTy[0])){
                if ($paramTy[0]->kind === TypeKind::TY_STRUCT or $paramTy[0]->kind === TypeKind::TY_UNION){
                    Console::errorTok($tok, 'passing struct or union is not supported yet');
                }
                $arg = Node::newCast($arg, $paramTy[0]);
                array_shift($paramTy);
            }

            $nodes[] = $arg;
        }

        $rest = $this->tokenizer->skip($tok, ')');

        $node = Node::newNode(NodeKind::ND_FUNCALL, $start);
        $node->funcname = $start->str;
        $node->funcTy = $ty;
        $node->ty = $ty->returnTy;
        $node->args = $nodes;
        return [$node, $rest];
    }

    /**
     * primary = "(" "{" stmt+ "}" ")"
     *         | "(" expr ")"
     *         | "sizeof" "(" type-name ")"
     *         | "sizeof" unary
     *         | "_Alignof" "(" type-name ")"
     *         | ident func-args?
     *         | str
     *         | number
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function primary(Token $rest, Token $tok): array
    {
        $start = $tok;

        if ($this->tokenizer->equal($tok, '(') and $this->tokenizer->equal($tok->next, '{')){
            // This is a GNU statement expression
            $node = Node::newNode(NodeKind::ND_STMT_EXPR, $tok);
            [$compoundStmt, $tok] = $this->compoundStmt($tok, $tok->next->next);
            $node->body = $compoundStmt->body;
            $rest = $this->tokenizer->skip($tok, ')');
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, '(')){
            [$node, $tok] = $this->expr($tok, $tok->next);
            $rest = $this->tokenizer->skip($tok, ')');
            return [$node, $rest];
        }

        if ($this->tokenizer->equal($tok, 'sizeof') and $this->tokenizer->equal($tok->next, '(') and $this->isTypeName($tok->next->next)){
            [$ty, $tok] = $this->typename($tok, $tok->next->next);
            $rest = $this->tokenizer->skip($tok, ')');
            return [Node::newUlong($ty->size, $start), $rest];
        }

        if ($this->tokenizer->equal($tok, 'sizeof')){
            [$node, $rest] = $this->unary($rest, $tok->next);
            $node->addType();
            return [Node::newUlong($node->ty->size, $tok), $rest];
        }

        if ($this->tokenizer->equal($tok, '_Alignof')){
            $tok = $this->tokenizer->skip($tok->next, '(');
            [$ty, $tok] = $this->typename($tok, $tok);
            $rest = $this->tokenizer->skip($tok, ')');
            return [Node::newUlong($ty->align, $tok), $rest];
        }

        if ($tok->isKind(TokenKind::TK_IDENT)){
            // Function call
            if ($this->tokenizer->equal($tok->next, '(')){
                return $this->funcall($rest, $tok);
            }

            // Variable or enum constant
            $sc = $this->findVar($tok);
            if ((! $sc) or ((! $sc->var) and (! $sc->enumTy))){
                Console::errorTok($tok, 'undefined variable');
            }

            if ($sc->var){
                $node = Node::newVarNode($sc->var, $tok);
            } else {
                $node = Node::newNum($sc->enumVal, $tok);
            }

            return [$node, $tok->next];
        }

        if ($tok->isKind(TokenKind::TK_STR)){
            $var = $this->newStringLiteral($tok->str, $tok->ty);
            return [Node::newVarNode($var, $tok), $tok->next];
        }

        if ($tok->isKind(TokenKind::TK_NUM)){
            $num = Node::newNum($tok->gmpVal, $tok);
            $num->ty = $tok->ty;
            return [$num, $tok->next];
        }

        Console::errorTok($tok, 'expected an expression');
    }

    public function parseTypedef(Token $tok, Type $basety): Token
    {
        $first = true;

        while ([$consumed, $tok] = $this->tokenizer->consume($tok, $tok, ';') and (! $consumed)){
            if (! $first){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            $first = false;

            [$ty, $tok] = $this->declarator($tok, $tok, $basety);
            $this->pushScope($this->getIdent($ty->name))->typeDef = $ty;
        }

        return $tok;
    }

    /**
     * @param \Pcc\Ast\Type[] $params
     * @return void
     */
    public function createParamLVars(array $params): void
    {
        foreach ($params as $param){
            $this->newLvar($this->getIdent($param->name), $param);
        }
    }

    public function resolveGotoLabels(): void
    {
        $goto = null;
        foreach ($this->gotos as $goto){
            foreach ($this->labels as $label){
                if ($goto->label === $label->label){
                    $goto->uniqueLabel = $label->uniqueLabel;
                    break;
                }
            }
        }

        if ((! is_null($goto)) and (! $goto->uniqueLabel)){
            Console::errorTok($goto->tok->next, 'use of undeclared label');
        }

        $this->gotos = [];
        $this->labels = [];
    }

    public function func(Token $tok, Type $basety, VarAttr $attr): Token
    {
        [$ty, $tok] = $this->declarator($tok, $tok, $basety);

        $fn = $this->newGVar($this->getIdent($ty->name), $ty);
        $fn->isFunction = true;
        [$consumed, $tok] = $this->tokenizer->consume($tok, $tok, ';');
        $fn->isDefinition = (! $consumed);
        $fn->isStatic = $attr->isStatic;

        if (! $fn->isDefinition){
            return $tok;
        }

        $this->currentFn = $fn;
        $this->locals = [];
        $this->enterScope();

        $this->createParamLVars($ty->params);
        $fn->params = $this->locals;

        if ($ty->isVariadic){
            $fn->vaArea = $this->newLvar('__va_area__', Type::arrayOf(Type::tyChar(), 136));
        }

        $tok = $this->tokenizer->skip($tok, '{');
        [$compoundStmt, $tok] = $this->compoundStmt($tok, $tok);
        $fn->body = [$compoundStmt];

        $fn->locals = $this->locals;
        $this->leaveScope();
        $this->resolveGotoLabels();

        return $tok;
    }

    public function globalVariable(Token $tok, Type $basety, VarAttr $attr): Token
    {
        $first = true;

        while ([$consumed, $tok] = $this->tokenizer->consume($tok, $tok, ';') and (! $consumed)){
            if (! $first){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            $first = false;

            [$ty, $tok] = $this->declarator($tok, $tok, $basety);
            $var = $this->newGVar($this->getIdent($ty->name), $ty);
            $var->isDefinition = ! $attr->isExtern;
            $var->isStatic = $attr->isStatic;
            if ($attr->align){
                $var->align = $attr->align;
            }

            if ($this->tokenizer->equal($tok, '=')){
                $tok = $this->gVarInitializer($tok, $tok->next, $var);
            }
        }

        return $tok;
    }

    public function isFunction(Token $tok): bool
    {
        if ($this->tokenizer->equal($tok, ';')){
            return false;
        }
        $dummy = Type::tyInt();
        [$ty, $tok] = $this->declarator($tok, $tok, $dummy);
        return $ty->kind === TypeKind::TY_FUNC;
    }

    /**
     * program = (typedef | function-definition | global-variable)*
     *
     * @return Obj[]
     */
    public function parse(): array
    {
        $tok = $this->tokenizer->tokens[0];
        $this->globals = [];

        while (! $tok->isKind(TokenKind::TK_EOF)){
            $attr = new VarAttr();
            [$basety, $tok] = $this->typespec($tok, $tok, $attr);

            // Typedef
            if ($attr->isTypedef){
                $tok = $this->parseTypedef($tok, $basety);
                continue;
            }

            // Function
            if ($this->isFunction($tok)){
                $tok = $this->func($tok, $basety, $attr);
                continue;
            }

            // Global variable
            $tok = $this->globalVariable($tok, $basety, $attr);
        }
        return $this->globals;
    }
}
