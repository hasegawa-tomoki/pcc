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
    case ND_EXPR_STMT;
    case ND_NUM;
}
