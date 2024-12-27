<?php

namespace Pcc\Ast;

use Pcc\Ast\Scope\Scope;
use Pcc\Ast\Scope\TagScope;
use Pcc\Ast\Scope\VarScope;
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

    public function newNode(NodeKind $kind, Token $tok): Node
    {
        $node = new Node();
        $node->kind = $kind;
        $node->tok = $tok;
        return $node;
    }

    public function pushScope(string $name): VarScope
    {
        $sc = new VarScope();
        $sc->name = $name;
        $sc->depth = $this->scopeDepth;

        array_unshift($this->scopes[0]->vars, $sc);
        return $sc;
    }

    public function newVar(string $name, Type $ty): Obj
    {
        $var = new Obj($name);
        $var->ty = $ty;
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
        if ($tok->kind !== TokenKind::TK_IDENT){
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

    public function getNumber(Token $tok): int
    {
        if ($tok->kind !== TokenKind::TK_NUM) {
            Console::errorTok($tok, "expected a number");
        }
        return $tok->val;
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
     * typename = "void" | "char" | "short" | "int" | "long"
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
            // Handle "typedef" keyword
            if ($this->tokenizer->equal($tok, 'typedef')){
                if (! $attr){
                    Console::errorTok($tok, 'storage class specifier is not allowed in this context');
                }
                $attr->isTypedef = true;
                $tok = $tok->next;
                continue;
            }

            // Handle user-defined types
            $ty2 = $this->findTypedef($tok);
            if ($this->tokenizer->equal($tok, 'struct') or $this->tokenizer->equal($tok, 'union') or $ty2){
                if ($counter){
                    break;
                }

                if ($this->tokenizer->equal($tok, 'struct')) {
                    [$ty, $tok] = $this->structDecl($tok, $tok->next);
                } elseif ($this->tokenizer->equal($tok, 'union')){
                    [$ty, $tok] = $this->unionDecl($tok, $tok->next);
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
            } elseif ($this->tokenizer->equal($tok, 'char')){
                $counter += TypeCount::CHAR->value;
            } elseif ($this->tokenizer->equal($tok, 'short')){
                $counter += TypeCount::SHORT->value;
            } elseif ($this->tokenizer->equal($tok, 'int')){
                $counter += TypeCount::INT->value;
            } elseif ($this->tokenizer->equal($tok, 'long')){
                $counter += TypeCount::LONG->value;
            } else {
                Console::unreachable(__FILE__, __LINE__);
            }

            switch ($counter){
                case TypeCount::VOID->value:
                    $ty = Type::tyVoid();
                    break;
                case TypeCount::CHAR->value:
                    $ty = Type::tyChar();
                    break;
                case TypeCount::SHORT->value:
                case TypeCount::SHORT->value + TypeCount::INT->value:
                    $ty = Type::tyShort();
                    break;
                case TypeCount::INT->value:
                    $ty = Type::tyInt();
                    break;
                case TypeCount::LONG->value:
                case TypeCount::LONG->value + TypeCount::INT->value:
                case TypeCount::LONG->value + TypeCount::LONG->value:
                case TypeCount::LONG->value + TypeCount::LONG->value + TypeCount::INT->value:
                    $ty = Type::tyLong();
                    break;
                default:
                    Console::errorTok($tok, 'invalid type');
            }

            $tok = $tok->next;
        }

        return [$ty, $tok];
    }

    /**
     * func-params = (param ("," param)*)? ")"
     * param = typespec declarator
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function funcParams(Token $rest, Token $tok, Type $ty): array
    {
        $params = [];
        while (! $this->tokenizer->equal($tok, ')')){
            if (count($params) > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            [$basety, $tok] = $this->typespec($tok, $tok, null);
            [$type, $tok] = $this->declarator($tok, $tok, $basety);
            $params[] = $type;
        }

        $ty = Type::funcType($ty);
        $ty->params = $params;

        return [$ty, $tok->next];
    }

    /**
     * type-suffix = "(" func-params
     *             | "[" num "]" type-suffix
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
            $sz = $this->getNumber($tok->next);
            $tok = $this->tokenizer->skip($tok->next->next, ']');
            [$ty, $rest] = $this->typeSuffix($rest, $tok, $ty);
            return [Type::arrayOf($ty, $sz), $rest];
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
        while ([$consumed, $tok] = $this->tokenizer->consume($tok, '*') and $consumed){
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
        return $this->abstractDeclarator($rest, $tok, $ty);
    }

    /**
     * declaration = typespec (declarator ("=" expr)? ("," declarator ("=" expr)?)*)? ";"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $basety
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function declaration(Token $rest, Token $tok, Type $basety): array
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
            $var = $this->newLvar($this->getIdent($ty->name), $ty);

            if (! $this->tokenizer->equal($tok, '=')){
                continue;
            }

            $lhs = Node::newVarNode($var, $ty->name);
            [$rhs, $tok] = $this->assign($tok, $tok->next);
            $node = Node::newBinary(NodeKind::ND_ASSIGN, $lhs, $rhs, $tok);
            $nodes[] = Node::newUnary(NodeKind::ND_EXPR_STMT, $node, $tok);
        }

        $node = Node::newNode(NodeKind::ND_BLOCK, $tok);
        $node->body = $nodes;
        return [$node, $tok->next];
    }

    public function isTypeName(Token $tok): bool
    {
        if (in_array($tok->str, [
            'void', 'char', 'short', 'int', 'long', 'struct', 'union',
            'typedef',
        ])){
            return true;
        }
        return $this->findTypedef($tok) !== null;
    }

    /**
     * stmt = "return" expr ";"
     *      | "if" "(" expr ")" stmt ("else" stmt)?
     *      | "for" "(" expr-stmt expr? ";" expr? ")" stmt
     *      | "while" "(" expr ")" stmt
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
            [$node->lhs, $tok] = $this->expr($tok, $tok->next);
            $rest = $this->tokenizer->skip($tok, ';');
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

        if ($this->tokenizer->equal($tok, 'for')){
            $node = Node::newNode(NodeKind::ND_FOR, $tok);
            $tok = $this->tokenizer->skip($tok->next, '(');

            [$node->init, $tok] = $this->exprStmt($tok, $tok);

            if (! $this->tokenizer->equal($tok, ';')){
                [$node->cond, $tok] = $this->expr($tok, $tok);
            }
            $tok =$this->tokenizer->skip($tok, ';');

            if (! $this->tokenizer->equal($tok, ')')){
                [$node->inc, $tok] = $this->expr($tok, $tok);
            }
            $tok = $this->tokenizer->skip($tok, ')');

            [$node->then, $rest] = $this->stmt($rest, $tok);

            return [$node, $rest];
        }

        // "while" "(" expr ")" stmt
        if ($this->tokenizer->equal($tok, 'while')){
            $node = Node::newNode(NodeKind::ND_FOR, $tok);
            $tok = $this->tokenizer->skip($tok->next, '(');
            [$node->cond, $tok] = $this->expr($tok, $tok);
            $tok = $this->tokenizer->skip($tok, ')');
            [$node->then, $rest] = $this->stmt($rest, $tok);
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
            if ($this->isTypeName($tok)){
                $attr = new VarAttr();
                [$basety, $tok] = $this->typespec($tok, $tok, $attr);

                if ($attr->isTypedef){
                    $tok = $this->parseTypedef($tok, $basety);
                    continue;
                }

                [$n, $tok] = $this->declaration($tok, $tok, $basety);
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

    /**
     * assign = equality ("=" assign)?
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function assign(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->equality($tok, $tok);

        if ($this->tokenizer->equal($tok, '=')){
            [$assign, $rest] = $this->assign($rest, $tok->next);
            return [Node::newBinary(NodeKind::ND_ASSIGN, $node, $assign, $tok), $rest];
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
     * relational = add ("<" add | "<=" add | ">" add | ">=" add)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function relational(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->add($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '<')){
                [$add, $tok] = $this->add($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LT, $node, $add, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '<=')){
                [$add, $tok] = $this->add($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LE, $node, $add, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '>')){
                [$add, $tok] = $this->add($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LT, $add, $node, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '>=')){
                [$add, $tok] = $this->add($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_LE, $add, $node, $start);
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
            $node->ty = Type::tyInt();
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
     * mul = unary ("*" unary | "/" unary)*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function mul(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->unary($tok, $tok);

        for (;;){
            $start = $tok;

            if ($this->tokenizer->equal($tok, '*')){
                [$unary, $tok] = $this->unary($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_MUL, $node, $unary, $start);
                continue;
            }
            if ($this->tokenizer->equal($tok, '/')){
                [$unary, $tok] = $this->unary($tok, $tok->next);
                $node = Node::newBinary(NodeKind::ND_DIV, $node, $unary, $start);
                continue;
            }

            return [$node, $tok];
        }
    }

    /**
     * unary = ("+" | "-" | "*" | "&") unary | postfix
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function unary(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, '+')){
            return $this->unary($rest, $tok->next);
        }
        if ($this->tokenizer->equal($tok, '-')){
            [$unary, $rest] = $this->unary($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_NEG, $unary, $tok), $rest];
        }
        if ($this->tokenizer->equal($tok, '&')){
            [$unary, $rest] = $this->unary($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_ADDR, $unary, $tok), $rest];
        }
        if ($this->tokenizer->equal($tok, '*')){
            [$unary, $rest] = $this->unary($rest, $tok->next);
            return [Node::newUnary(NodeKind::ND_DEREF, $unary, $tok), $rest];
        }

        return $this->postfix($rest, $tok);
    }

    // struct-members = (typespec declarator (","  declarator)* ";")*
    public function structMembers(Token $rest, Token $tok, Type $ty): Token
    {
        $members = [];

        while (! $this->tokenizer->equal($tok, '}')){
            [$basety, $tok] = $this->typespec($tok, $tok, null);
            $i = 0;
            while (
                [$consumed, $tok] = $this->tokenizer->consume($tok, ';') and
                (! $consumed)
            ){
                if ($i++){
                    $tok = $this->tokenizer->skip($tok, ',');
                }

                [$declarator, $tok] = $this->declarator($tok, $tok, $basety);

                $mem = new Member();
                $mem->ty = $declarator;
                $mem->name = $mem->ty->name;
                $members[] = $mem;
            }
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
        $tag = null;
        if ($tok->isKind(TokenKind::TK_IDENT)){
            $tag = $tok;
            $tok = $tok->next;
        }

        if ($tag and (! $this->tokenizer->equal($tok, '{'))){
            $sc = $this->findTag($tag);
            if (! $sc){
                Console::errorTok($tag, 'unknown struct type');
            }
            return [$sc->ty, $tok];
        }

        $ty = new Type(TypeKind::TY_STRUCT);
        $rest = $this->structMembers($rest, $tok->next, $ty);
        $ty->align = 1;

        if ($tag){
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

        $offset = 0;
        foreach ($ty->members as $mem){
            $offset = Align::alignTo($offset, $mem->ty->align);
            $mem->offset = $offset;
            $offset += $mem->ty->size;

            if ($ty->align < $mem->ty->align){
                $ty->align = $mem->ty->align;
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

        foreach ($ty->members as $mem){
            $mem->offset = 0;
            if ($ty->align < $mem->ty->align){
                $ty->align = $mem->ty->align;
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
        $node->member = $this->getStructMember($lhs->ty, $tok);
        return $node;
    }

    /**
     * postfix = primary ("[" expr "]") | "." ident | "->" ident)*
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

        $nodes = [];
        while(! $this->tokenizer->equal($tok, ')')){
            if (count($nodes) > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            [$assign, $tok] = $this->assign($tok, $tok);
            $nodes[] = $assign;
        }

        $rest = $this->tokenizer->skip($tok, ')');

        $node = Node::newNode(NodeKind::ND_FUNCALL, $start);
        $node->funcname = $start->str;
        $node->args = $nodes;
        return [$node, $rest];
    }

    /**
     * primary = "(" "{" stmt+ "}" ")"
     *         | "(" expr ")"
     *         | "sizeof" "(" type-name ")"
     *         | "sizeof" unary
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
            return [Node::newNum($ty->size, $start), $rest];
        }

        if ($this->tokenizer->equal($tok, 'sizeof')){
            [$node, $rest] = $this->unary($rest, $tok->next);
            $node->addType();
            return [Node::newNum($node->ty->size, $tok), $rest];
        }

        if ($tok->isKind(TokenKind::TK_IDENT)){
            // Function call
            if ($this->tokenizer->equal($tok->next, '(')){
                return $this->funcall($rest, $tok);
            }

            // Variable
            $sc = $this->findVar($tok);
            if ((! $sc) or (! $sc->var)){
                Console::errorTok($tok, 'undefined variable');
            }

            return [Node::newVarNode($sc->var, $tok), $tok->next];
        }

        if ($tok->isKind(TokenKind::TK_STR)){
            $var = $this->newStringLiteral($tok->str, $tok->ty);
            return [Node::newVarNode($var, $tok), $tok->next];
        }

        if ($tok->isKind(TokenKind::TK_NUM)){
            return [Node::newNum($tok->val, $tok), $tok->next];
        }

        Console::errorTok($tok, 'expected an expression');
    }

    public function parseTypedef(Token $tok, Type $basety): Token
    {
        $first = true;

        while ([$consumed, $tok] = $this->tokenizer->consume($tok, ';') and (! $consumed)){
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

    public function func(Token $tok, Type $basety): Token
    {
        [$ty, $tok] = $this->declarator($tok, $tok, $basety);

        $fn = $this->newGVar($this->getIdent($ty->name), $ty);
        $fn->isFunction = true;
        [$consumed, $tok] = $this->tokenizer->consume($tok, ';');
        $fn->isDefinition = (! $consumed);

        if (! $fn->isDefinition){
            return $tok;
        }

        $this->locals = [];
        $this->enterScope();

        $this->createParamLVars($ty->params);
        $fn->params = $this->locals;

        $tok = $this->tokenizer->skip($tok, '{');
        [$compoundStmt, $tok] = $this->compoundStmt($tok, $tok);
        $fn->body = [$compoundStmt];

        $fn->locals = $this->locals;
        $this->leaveScope();

        return $tok;
    }

    public function globalVariable(Token $tok, Type $basety): Token
    {
        $first = true;

        while ([$consumed, $tok] = $this->tokenizer->consume($tok, ';') and (! $consumed)){
            if (! $first){
                $tok = $this->tokenizer->skip($tok, ',');
            }
            $first = false;

            [$ty, $tok] = $this->declarator($tok, $tok, $basety);
            $this->newGVar($this->getIdent($ty->name), $ty);
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
                $tok = $this->func($tok, $basety);
                continue;
            }

            // Global variable
            $tok = $this->globalVariable($tok, $basety);
        }
        return $this->globals;
    }
}
