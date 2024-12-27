<?php

namespace Pcc\Ast;

enum TypeKind
{
    case TY_VOID;
    case TY_BOOL;
    case TY_CHAR;
    case TY_SHORT;
    case TY_INT;
    case TY_LONG;
    case TY_PTR;
    case TY_FUNC;
    case TY_ARRAY;
    case TY_STRUCT;
    case TY_UNION;
}
