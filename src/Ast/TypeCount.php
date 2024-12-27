<?php

namespace Pcc\Ast;

enum TypeCount: int
{
    case VOID = 1 << 0;
    case BOOL = 1 << 2;
    case CHAR = 1 << 4;
    case SHORT = 1 << 6;
    case INT = 1 << 8;
    case LONG = 1 << 10;
    case OTHER = 1 << 12;
}
