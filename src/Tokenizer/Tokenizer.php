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

    public function equal(string $op): bool
    {
        return $this->tokens[0]->str === $op;
    }

    public function consume(string $op): bool
    {
        if (! $this->equal($op)){
            return false;
        }
        array_shift($this->tokens);
        return true;
    }

    public function getIdent(): ?Token
    {
        if ($this->tokens[0]->kind !== TokenKind::TK_IDENT){
            return null;
        }
        return array_shift($this->tokens);
    }

    public function expect(string $op): void
    {
        if ($this->tokens[0]->kind !== TokenKind::TK_RESERVED ||
            strlen($op) != $this->tokens[0]->len ||
            $this->tokens[0]->str !== $op) {
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

    public function isTokenKind(TokenKind $kind): bool
    {
        return $this->tokens[0]->kind === $kind;
    }

    public function atEof(): bool
    {
        return $this->tokens[0]->kind === TokenKind::TK_EOF;
    }

    public function isIdent1(string $c): bool
    {
        return preg_match('/^[a-zA-Z_]/', $c);
    }

    public function isIdent2(string $c): bool
    {
        return $this->isIdent1($c) or preg_match('/^[0-9]/', $c);
    }

    public function tokenize(): void
    {
        $pos = 0;
        $tokens = [];
        while ($pos < strlen($this->userInput)) {
            // Skip whitespace characters
            if (ctype_space($this->userInput[$pos])) {
                $pos++;
                continue;
            }

            // Numeric literal
            if (ctype_digit($this->userInput[$pos])) {
                $token = new Token(TokenKind::TK_NUM, $this->userInput[$pos], $pos);
                $valStr = '';
                while ($pos < strlen($this->userInput) && ctype_digit($this->userInput[$pos])) {
                    $valStr .= $this->userInput[$pos];
                    $pos++;
                }
                $token->str = $valStr;
                $token->val = intval($valStr);
                $tokens[] = $token;
                continue;
            }

            // Identifier
            if ($this->isIdent1($this->userInput[$pos])){
                $start = $pos;
                while ($pos < strlen($this->userInput) && $this->isIdent2($this->userInput[$pos])){
                    $pos++;
                }
                $tokens[] = new Token(TokenKind::TK_IDENT, substr($this->userInput, $start, $pos - $start), $pos);
                continue;
            }

            // Punctuators
            if (in_array($token = substr($this->userInput, $pos, 2), ['==', '!=', '<=', '>='])) {
                $tokens[] = new Token(TokenKind::TK_RESERVED, $token, $pos);
                $pos += 2;
                continue;
            }
            if (str_contains("!\"#$%&'()*+,-./:;<=>?@[\]^_`{|}~", $this->userInput[$pos])) {
                $tokens[] = new Token(TokenKind::TK_RESERVED, $this->userInput[$pos], $pos);
                $pos++;
                continue;
            }

            Console::errorAt($this->userInput, $pos, "トークナイズできません: %s\n", $this->userInput[$pos]);
        }

        $tokens[] = new Token(TokenKind::TK_EOF, '', $pos);
        $this->tokens = $tokens;
    }
}