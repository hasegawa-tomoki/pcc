<?php

namespace Pcc\Ast;

enum NodeKind
{
    case ND_NULL_EXPR;  // Do nothing
    case ND_ADD;
    case ND_SUB;
    case ND_MUL;
    case ND_DIV;
    case ND_MOD;
    case ND_BITAND;     // &
    case ND_BITOR;      // |
    case ND_BITXOR;     // ^
    case ND_SHL;        // <<
    case ND_SHR;        // >>
    case ND_EQ;
    case ND_NE;
    case ND_LT;
    case ND_LE;
    case ND_ASSIGN;
    case ND_COND;       // ?:
    case ND_COMMA;
    case ND_MEMBER;     // . (struct member access)
    case ND_ADDR;       // unary &
    case ND_DEREF;      // unary *
    case ND_NOT;
    case ND_BITNOT;
    case ND_NEG;        // unary -
    case ND_LOGAND;
    case ND_LOGOR;
    case ND_RETURN;
    case ND_IF;
    case ND_FOR;
    case ND_DO;
    case ND_SWITCH;
    case ND_CASE;
    case ND_BLOCK;
    case ND_GOTO;
    case ND_GOTO_EXPR; // "goto" labels-as-values
    case ND_LABEL;
    case ND_LABEL_VAL; // [GNU] Labels-as-values
    case ND_FUNCALL;
    case ND_EXPR_STMT;
    case ND_STMT_EXPR;
    case ND_VAR;
    case ND_VLA_PTR;    // VLA designator
    case ND_NUM;
    case ND_CAST;
    case ND_MEMZERO;    // Zero-clear a stack variable
    case ND_ASM;        // "asm"
    case ND_CAS;        // Atomic compare-and-swap
    case ND_EXCH;       // Atomic exchange
}
