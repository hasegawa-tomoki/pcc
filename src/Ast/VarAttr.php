<?php

namespace Pcc\Ast;

class VarAttr
{
    public bool $isTypedef = false;
    public bool $isStatic = false;
    public bool $isExtern = false;
    public bool $isInline = false;
    public bool $isTls = false;
    public int $align = 0;
}