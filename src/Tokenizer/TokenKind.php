<?php
namespace Pcc\Tokenizer;
enum TokenKind
{
    case TK_IDENT;
    case TK_RESERVED;
    case TK_KEYWORD;
    case TK_STR;
    case TK_NUM;
    case TK_EOF;
}