<?php
namespace Pcc\Tokenizer;
enum TokenKind
{
    case TK_IDENT;
    case TK_RESERVED;
    case TK_KEYWORD;
    case TK_STR;
    case TK_NUM;
    case TK_PP_NUM;
    case TK_PMARK;
    case TK_EOF;
}