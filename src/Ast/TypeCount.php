<?php

namespace Pcc\Ast;

enum TypeCount: int
{
    case VOID = 1 << 0;
    case CHAR = 1 << 2;
    case SHORT = 1 << 4;
    case INT = 1 << 6;
    case LONG = 1 << 8;
    case OTHER = 1 << 10;
}
