<?php

namespace Pcc\Tokenizer;

use Pcc\Ast\Type;
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
    public array $escapeChars = [];

    public function __construct(
        public readonly string $userInput,
    )
    {
        Console::$userInput = $userInput;

        $this->escapeChars = [
            '\a' => chr(7),
            '\b' => chr(8),
            '\t' => "\t",
            '\n' => "\n",
            '\v' => "\v",
            '\f' => "\f",
            '\r' => "\r",
            '\e' => chr(27),
        ];
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

    /**
     * @param int $start
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readStringLiteral(int $start): array
    {
        $pos = $start + 1;
        while ($pos < strlen($this->userInput) and $this->userInput[$pos] !== '"'){
            if ($this->userInput[$pos] === "\n"){
                Console::errorAt($pos, "unclosed string literal");
            }
            $pos++;
        }
        if ($pos >= strlen($this->userInput)){
            Console::errorAt($pos, "unclosed string literal");
        }
        $str =substr($this->userInput, $start + 1, $pos - $start - 1);
        $tok = new Token(TokenKind::TK_STR, $str, $pos + 1);

        // Octal number
        $tok->str = preg_replace_callback('/\\\\([0-7]{1, 3})/', fn($matches) => chr(octdec($matches[1])), $tok->str);
        // Escape chars
        foreach ($this->escapeChars as $key => $val){
            $tok->str = str_replace($key, $val, $tok->str);
        }
        // Single char
        $tok->str = preg_replace('/\\\\(.)/', '$1', $tok->str);

        $tok->ty = Type::arrayOf(Type::tyChar(), strlen($tok->str) + 1);
        return [$tok, $pos + 1];
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

            // String literal
            if ($this->userInput[$pos] === '"') {
                [$token, $pos] = $this->readStringLiteral($pos);
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