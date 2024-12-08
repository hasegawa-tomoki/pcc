<?php

namespace Pcc\Tokenizer;

use Pcc\Console;

class Tokenizer
{
    /** @var Token[] */
    public array $tokens;

    public function __construct(
        public readonly string $userInput,
    )
    {
    }

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
            Console::errorAt($this->userInput, $this->tokens[0]->pos, "'%s'ではありません\n", $op);
        }
        array_shift($this->tokens);
    }

    public function expectNumber(): int
    {
        if ($this->tokens[0]->kind !== TokenKind::TK_NUM) {
            Console::errorAt($this->userInput, $this->tokens[0]->pos, "数ではありません\n");
        }
        $val = $this->tokens[0]->val;
        array_shift($this->tokens);
        return $val;
    }

    public function atEof(): bool
    {
        return $this->tokens[0]->kind === TokenKind::TK_EOF;
    }

    public function tokenize(): void
    {
        $pos = 0;
        $tokens = [];
        while ($pos < strlen($this->userInput)) {
            if (ctype_space($this->userInput[$pos])) {
                $pos++;
                continue;
            }

            if (str_contains("+-*/()", $this->userInput[$pos])) {
                $tokens[] = new Token(TokenKind::TK_RESERVED, $this->userInput[$pos], $pos);
                $pos++;
                continue;
            }

            if (ctype_digit($this->userInput[$pos])) {
                $token = new Token(TokenKind::TK_NUM, $this->userInput[$pos], $pos);
                $valStr = '';
                while ($pos < strlen($this->userInput) && ctype_digit($this->userInput[$pos])) {
                    $valStr .= $this->userInput[$pos];
                    $pos++;
                }
                $token->val = intval($valStr);
                $tokens[] = $token;
                continue;
            }

            Console::errorAt($this->userInput, $pos, "トークナイズできません: %s\n", $this->userInput[$pos]);
        }

        $tokens[] = new Token(TokenKind::TK_EOF, '', $pos);
        $this->tokens = $tokens;
    }
}