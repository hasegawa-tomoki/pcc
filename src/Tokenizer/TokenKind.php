<?php
namespace Pcc\Tokenizer;
enum TokenKind
{
    case TK_RESERVED;
    case TK_NUM;
    case TK_EOF;
}