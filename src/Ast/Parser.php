<?php

namespace Pcc\Ast;

use Pcc\Console;
use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Tokenizer\TokenKind;

class Parser
{
    /** @var array<string, \Pcc\Ast\Obj> */
    public array $locals = [];
    /** @var array<string, \Pcc\Ast\Obj> */
    public array $globals = [];

    public function __construct(
        private readonly Tokenizer $tokenizer,
    )
    {
    }

    public function findVar(Token $tok): ?Obj
    {
        $name = $tok->str;
        if (isset($this->locals[$name])){
            return $this->locals[$name];
        }
        if (isset($this->globals[$name])){
            return $this->globals[$name];
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

    public function newVar(string $name, Type $ty): Obj
    {
        $var = new Obj($name);
        $var->ty = $ty;
        return $var;
    }

    public function newLvar(string $name, Type $ty): Obj
    {
        $var = new Obj($name);
        $var->ty = $ty;
        $var->isLocal = true;
        $this->locals[$name] = $var;
        return $var;
    }

    public function newGVar(string $name, Type $ty): Obj
    {
        $var = new Obj($name);
        $var->ty = $ty;
        $this->globals[$name] = $var;
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

    public function getNumber(Token $tok): int
    {
        if ($tok->kind !== TokenKind::TK_NUM) {
            Console::errorTok($tok, "expected a number");
        }
        return $tok->val;
    }

    /**
     * declspec = "char" | "int"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function declspec(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, 'char')){
            return [Type::tyChar(), $tok->next];
        }

        return [Type::tyInt(), $this->tokenizer->skip($tok, 'int')];
    }

    /**
     * func-params = (param ("," param)*)? ")"
     * param = declspec declarator
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
            [$basety, $tok] = $this->declspec($tok, $tok);
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
     * declarator = "*"* ident type-suffix
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Ast\Type, 1: \Pcc\Tokenizer\Token}
     */
    public function declarator(Token $rest, Token $tok, Type $ty): array
    {
        while (
            [$consumed, $tok] = $this->tokenizer->consume($tok, '*') and
            $consumed
        ){
            $ty = Type::pointerTo($ty);
        }

        if (! $tok->isKind(TokenKind::TK_IDENT)){
            Console::errorTok($tok, 'expected a variable name');
        }

        [$ty, $rest] = $this->typeSuffix($rest, $tok->next, $ty);
        $ty->name = $tok;
        return [$ty, $rest];
    }

    /**
     * declaration = declspec (declarator ("=" expr)? ("," declarator ("=" expr)?)*)? ";"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function declaration(Token $rest, Token $tok): array
    {
        [$basety, $tok] = $this->declspec($tok, $tok);

        $i = 0;
        $nodes = [];
        while (! $this->tokenizer->equal($tok, ';')){
            if ($i++ > 0){
                $tok = $this->tokenizer->skip($tok, ',');
            }

            [$ty, $tok] = $this->declarator($tok, $tok, $basety);
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
     * compound-stmt = (declaration | stmt)* "}"
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function compoundStmt(Token $rest, Token $tok): array
    {
        $node = Node::newNode(NodeKind::ND_BLOCK, $tok);

        $nodes = [];
        while (! $this->tokenizer->equal($tok, '}')){
            if ($tok->isTypeName()){
                [$n, $tok] = $this->declaration($tok, $tok);
            } else {
                [$n, $tok] = $this->stmt($tok, $tok);
            }
            $n->addType();
            $nodes[] = $n;
        }

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
     * expr = assign
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function expr(Token $rest, Token $tok): array
    {
        return $this->assign($rest, $tok);
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
        return null;
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

    /**
     * postfix = primary ("[" expr "]")*
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function postfix(Token $rest, Token $tok): array
    {
        [$node, $tok] = $this->primary($tok, $tok);

        while ($this->tokenizer->equal($tok, '[')){
            // x[y] is short for *(x+y)
            $start = $tok;
            [$idx, $tok] = $this->expr($tok, $tok->next);
            $tok = $this->tokenizer->skip($tok, ']');
            $node = Node::newUnary(NodeKind::ND_DEREF, $this->newAdd($node, $idx, $start), $start);
        }

        return [$node, $tok];
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
     * primary = "(" expr ")" | "sizeof" unary | ident func-args? | str | number
     *
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @return array{0: \Pcc\Ast\Node, 1: \Pcc\Tokenizer\Token}
     */
    public function primary(Token $rest, Token $tok): array
    {
        if ($this->tokenizer->equal($tok, '(')){
            [$node, $tok] = $this->expr($tok, $tok->next);
            $rest = $this->tokenizer->skip($tok, ')');
            return [$node, $rest];
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
            if (! $var = $this->findVar($tok)){
                Console::errorTok($tok, 'undefined variable');
            }

            return [Node::newVarNode($var, $tok), $tok->next];
        }

        if ($tok->isKind(TokenKind::TK_STR)){
            $var = $this->newStringLiteral($tok->str, $tok->ty);
            return [Node::newVarNode($var, $tok), $tok->next];
        }

        if ($tok->isKind(TokenKind::TK_NUM)){
            return [Node::newNum($tok->val, $tok), $tok->next];
        }

        Console::errorTok($tok, 'expected an expression');
        return [];
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

        $this->locals = [];

        $this->createParamLVars($ty->params);
        $fn->params = $this->locals;

        $tok = $this->tokenizer->skip($tok, '{');
        [$compoundStmt, $tok] = $this->compoundStmt($tok, $tok);
        $fn->body = [$compoundStmt];
        $fn->locals = $this->locals;

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
     * program = (function-definition | global-variable)*
     *
     * @return Obj[]
     */
    public function parse(): array
    {
        $tok = $this->tokenizer->tokens[0];
        $this->globals = [];

        while (! $tok->isKind(TokenKind::TK_EOF)){
            [$basety, $tok] = $this->declspec($tok, $tok);
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