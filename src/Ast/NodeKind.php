<?php

namespace Pcc\Ast;

enum NodeKind
{
    case ND_ADD;
    case ND_SUB;
    case ND_MUL;
    case ND_DIV;
    case ND_NUM;
}
