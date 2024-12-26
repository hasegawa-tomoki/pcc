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
        'struct',
        'union',
    ];
    public Token $tok {
        get {
            return $this->tokens[0];
        }
    }
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

    public function stringLiteralEndPos(int $pos): int
    {
        for ($i = $pos; $i < strlen($this->currentInput); $i++){
            if ($this->currentInput[$i] === '"'){
                return $i;
            }
            if ($this->currentInput[$i] === '\\'){
                $i++;
            }
        }
        Console::errorAt($pos, "unclosed string literal");
    }

    /**
     * @param int $start
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readStringLiteral(int $start): array
    {
        $endPos = $this->stringLiteralEndPos($start + 1);

        $str = '';
        for ($i = $start + 1; $i < $endPos; $i++){
            if ($this->currentInput[$i] === '\\'){
                // Octal number
                if (preg_match('/\\\\([0-7]{1, 3})/', substr($this->currentInput, $i, 4), $matches)){
                    $str .= chr(octdec($matches[1]));
                    $i += strlen($matches[1]) + 1;
                    continue;
                }
                // Hexadecimal number
                if (preg_match('/\\\\x([0-9a-fA-F]+)/', substr($this->currentInput, $i, 4), $matches)){
                    $str .= chr(hexdec($matches[1]));
                    $i += strlen($matches[1]) + 2;
                    continue;
                }
                $i++;
                $str .= match ($this->currentInput[$i]){
                    'a' => chr(7),
                    'b' => chr(8),
                    't' => "\t",
                    'n' => "\n",
                    'v' => "\v",
                    'f' => "\f",
                    'r' => "\r",
                    'e' => chr(27),
                    default => $this->currentInput[$i],
                };
            } else {
                $str .= $this->currentInput[$i];
            }
        }

        $tok = new Token(TokenKind::TK_STR, $str, $start);
        $tok->ty = Type::arrayOf(Type::tyChar(), strlen($tok->str) + 1);
        return [$tok, $endPos + 1];
    }

    public function convertKeywords(): void
    {
        foreach ($this->tokens as $idx => $token){
            if (in_array($token->str, $this->keywords)){
                $this->tokens[$idx]->kind = TokenKind::TK_KEYWORD;
            }
        }
    }

    public function addLineNumbers(): void
    {
        $tok = $this->tokens[0];
        $lineNo = 1;
        for ($pos = 0; $pos < strlen($this->currentInput); $pos++){
            if ($pos === $tok->pos){
                $tok->lineNo = $lineNo;
                $tok = $tok->next;
            }
            if ($this->currentInput[$pos] === "\n") {
                $lineNo++;
            }
        }
        if ($tok->kind === TokenKind::TK_EOF){
            $tok->lineNo = $lineNo;
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

            // Skip line comments
            if (substr($this->currentInput, $pos, 2) === '//') {
                $pos += 2;
                while ($this->currentInput[$pos] !== "\n") {
                    $pos++;
                }
                continue;
            }

            // Skip block comments
            if (substr($this->currentInput, $pos, 2) === '/*') {
                $pos += 2;
                while (substr($this->currentInput, $pos, 2) !== '*/') {
                    $pos++;
                }
                $pos += 2;
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
                $tokens[] = new Token(TokenKind::TK_IDENT, substr($this->currentInput, $start, $pos - $start), $start);
                continue;
            }

            // Punctuators
            if (in_array($token = substr($this->currentInput, $pos, 2), ['==', '!=', '<=', '>=', '->', ])) {
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
        $this->addLineNumbers();
        $this->convertKeywords();
   }
}