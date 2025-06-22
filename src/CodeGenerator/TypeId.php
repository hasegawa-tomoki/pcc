<?php

namespace Pcc\CodeGenerator;

enum TypeId: int
{
    case I8  = 0;
    case I16 = 1;
    case I32 = 2;
    case I64 = 3;
    case U8  = 4;
    case U16 = 5;
    case U32 = 6;
    case U64 = 7;
    case F32 = 8;
    case F64 = 9;
}