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
    public string $currentInput;

    public function __construct(
        public readonly string $currentFilename,
    )
    {
        if ($currentFilename === '-'){
            $this->currentInput = file_get_contents('php://stdin');
        } else {
            if (! is_file($currentFilename)){
                Console::error("cannot open: %s", $currentFilename);
            }
            $this->currentInput = file_get_contents($currentFilename);
        }
        if (! str_ends_with($this->currentInput, "\n")){
            $this->currentInput .= "\n";
        }

        Console::$currentFilename = $currentFilename;
        Console::$currentInput = $this->currentInput;

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
        while ($pos < strlen($this->currentInput) and $this->currentInput[$pos] !== '"'){
            if ($this->currentInput[$pos] === "\n"){
                Console::errorAt($pos, "unclosed string literal");
            }
            $pos++;
        }
        if ($pos >= strlen($this->currentInput)){
            Console::errorAt($pos, "unclosed string literal");
        }
        $str =substr($this->currentInput, $start + 1, $pos - $start - 1);
        $tok = new Token(TokenKind::TK_STR, $str, $pos + 1);

        // Octal number
        $tok->str = preg_replace_callback('/\\\\([0-7]{1, 3})/', fn($matches) => chr(octdec($matches[1])), $tok->str);
        // Hexadecimal number
        $tok->str = preg_replace_callback('/\\\\x([0-9a-fA-F]+)/', fn($matches) => chr(hexdec($matches[1])), $tok->str);
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
        while ($pos < strlen($this->currentInput)) {
            // Skip whitespace characters
            if (ctype_space($this->currentInput[$pos])) {
                $pos++;
                continue;
            }

            // Numeric literal
            if (ctype_digit($this->currentInput[$pos])) {
                $token = new Token(TokenKind::TK_NUM, $this->currentInput[$pos], $pos);
                $valStr = '';
                while ($pos < strlen($this->currentInput) && ctype_digit($this->currentInput[$pos])) {
                    $valStr .= $this->currentInput[$pos];
                    $pos++;
                }
                $token->str = $valStr;
                $token->val = intval($valStr);
                $tokens[] = $token;
                continue;
            }

            // String literal
            if ($this->currentInput[$pos] === '"') {
                [$token, $pos] = $this->readStringLiteral($pos);
                $tokens[] = $token;
                continue;
            }

            // Identifier or keyword
            if ($this->isIdent1($this->currentInput[$pos])){
                $start = $pos;
                while ($pos < strlen($this->currentInput) && $this->isIdent2($this->currentInput[$pos])){
                    $pos++;
                }
                $tokens[] = new Token(TokenKind::TK_IDENT, substr($this->currentInput, $start, $pos - $start), $pos);
                continue;
            }

            // Punctuators
            if (in_array($token = substr($this->currentInput, $pos, 2), ['==', '!=', '<=', '>='])) {
                $tokens[] = new Token(TokenKind::TK_RESERVED, $token, $pos);
                $pos += 2;
                continue;
            }
            if (str_contains("!\"#$%&'()*+,-./:;<=>?@[\]^_`{|}~", $this->currentInput[$pos])) {
                $tokens[] = new Token(TokenKind::TK_RESERVED, $this->currentInput[$pos], $pos);
                $pos++;
                continue;
            }

            Console::errorAt($pos, "invalid token: %s\n", $this->currentInput[$pos]);
        }

        $tokens[] = new Token(TokenKind::TK_EOF, '', $pos);
        $this->tokens = $tokens;
        for ($i = 0; $i < count($this->tokens) - 1; $i++){
            $this->tokens[$i]->next = $this->tokens[$i + 1];
        }
        $this->convertKeywords();
    }
}