<?php

namespace Pcc\Ast;

enum TypeKind
{
    case TY_INT;
    case TY_PTR;
    case TY_FUNC;
    case TY_ARRAY;
}
