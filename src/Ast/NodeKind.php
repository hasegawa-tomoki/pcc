<?php

namespace Pcc\Ast;

enum NodeKind
{
    case ND_ADD;
    case ND_SUB;
    case ND_MUL;
    case ND_DIV;
    case ND_MOD;
    case ND_BITAND;
    case ND_BITOR;
    case ND_BITXOR;
    case ND_EQ;
    case ND_NE;
    case ND_LT;
    case ND_LE;
    case ND_ASSIGN;
    case ND_COMMA;
    case ND_MEMBER; // . (struct member access)
    case ND_ADDR;   // unary &
    case ND_DEREF;  // unary *
    case ND_NOT;
    case ND_BITNOT;
    case ND_RETURN;
    case ND_IF;
    case ND_FOR;
    case ND_BLOCK;
    case ND_FUNCALL;
    case ND_EXPR_STMT;
    case ND_STMT_EXPR;
    case ND_VAR;
    case ND_NUM;
    case ND_CAST;
}
