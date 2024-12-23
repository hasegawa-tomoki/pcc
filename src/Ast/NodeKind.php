<?php

namespace Pcc\Ast;

enum NodeKind
{
    case ND_ADD;
    case ND_SUB;
    case ND_MUL;
    case ND_DIV;
    case ND_NEG;
    case ND_EQ;
    case ND_NE;
    case ND_LT;
    case ND_LE;
    case ND_ASSIGN;
    case ND_ADDR;   // unary &
    case ND_DEREF;  // unary *
    case ND_RETURN;
    case ND_IF;
    case ND_FOR;
    case ND_BLOCK;
    case ND_FUNCALL;
    case ND_EXPR_STMT;
    case ND_STMT_EXPR;
    case ND_VAR;
    case ND_NUM;
}
