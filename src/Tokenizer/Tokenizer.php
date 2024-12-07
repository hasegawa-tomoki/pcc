<?php

namespace Pcc\Tokenizer;

class Tokenizer
{
    /** @var Token[] */
    public array $tokens;

    public function consume(string $op): bool
    {
        if ($this->tokens[0]->kind !== TokenKind::TK_RESERVED || $this->tokens[0]->str !== $op) {
            return false;
        }
        array_shift($this->tokens);
        return true;
    }

    public function expect(string $op): void
    {
        if ($this->tokens[0]->kind !== TokenKind::TK_RESERVED || $this->tokens[0]->str !== $op) {
            fprintf(STDERR, "'%s'ではありません\n", $op);
            exit(1);
        }
        array_shift($this->tokens);
    }

    public function expectNumber(): int
    {
        if ($this->tokens[0]->kind !== TokenKind::TK_NUM) {
            fprintf(STDERR, "数ではありません\n");
            exit(1);
        }
        $val = $this->tokens[0]->val;
        array_shift($this->tokens);
        return $val;
    }

    public function atEof(): bool
    {
        return $this->tokens[0]->kind === TokenKind::TK_EOF;
    }

    public function tokenize(string $code): void
    {
        $pos = 0;
        $tokens = [];
        while ($pos < strlen($code)) {
            if (ctype_space($code[$pos])) {
                $pos++;
                continue;
            }

            if (str_contains("+-", $code[$pos])) {
                $tokens[] = new Token(TokenKind::TK_RESERVED, $code[$pos]);
                $pos++;
                continue;
            }

            if (ctype_digit($code[$pos])) {
                $token = new Token(TokenKind::TK_NUM, $code[$pos]);
                $valStr = '';
                while ($pos < strlen($code) && ctype_digit($code[$pos])) {
                    $valStr .= $code[$pos];
                    $pos++;
                }
                $token->val = intval($valStr);
                $tokens[] = $token;
                continue;
            }

            fprintf(STDERR, "トークナイズできません: %s\n", $code[$pos]);
            exit(1);
        }

        $tokens[] = new Token(TokenKind::TK_EOF, '');
        $this->tokens = $tokens;
    }
}