<?php

namespace Pcc\Tokenizer;

use Pcc\Console;

class Tokenizer
{
    /** @var Token[] */
    public array $tokens;
    public array $keywords = [
        'return',
        'if',
        'else',
        'for',
        'while',
        'int',
        'sizeof',
        'char',
    ];
    public Token $tok {
        get {
            return $this->tokens[0];
        }
    }

    public function __construct(
        public readonly string $userInput,
    )
    {
        Console::$userInput = $userInput;
    }

    public function equal(Token $tok, string $op): bool
    {
        return $tok->str === $op;
    }

    public function skip(Token $tok, string $op): Token
    {
        if ($tok->str !== $op) {
            Console::errorTok($tok, "expected '%s'", $op);
        }
        return $tok->next;
    }

    /**
     * @param \Pcc\Tokenizer\Token $tok
     * @param string $op
     * @return array{0: bool, 1: \Pcc\Tokenizer\Token}
     */
    public function consume(Token $tok, string $op): array
    {
        if ($this->equal($tok, $op)){
            return [true, $tok->next];
        }
        return [false, $tok];
    }

    public function isIdent1(string $c): bool
    {
        return preg_match('/^[a-zA-Z_]/', $c);
    }

    public function isIdent2(string $c): bool
    {
        return $this->isIdent1($c) or preg_match('/^[0-9]/', $c);
    }

    public function convertKeywords(): void
    {
        foreach ($this->tokens as $idx => $token){
            if (in_array($token->str, $this->keywords)){
                $this->tokens[$idx]->kind = TokenKind::TK_KEYWORD;
            }
        }
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

            // Identifier or keyword
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

            Console::errorAt($pos, "invalid token: %s\n", $this->userInput[$pos]);
        }

        $tokens[] = new Token(TokenKind::TK_EOF, '', $pos);
        $this->tokens = $tokens;
        for ($i = 0; $i < count($this->tokens) - 1; $i++){
            $this->tokens[$i]->next = $this->tokens[$i + 1];
        }
        $this->convertKeywords();
    }
}