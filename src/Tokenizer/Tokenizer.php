<?php

namespace Pcc\Tokenizer;

use GMP;
use Pcc\Ast\Type;
use Pcc\Ast\Type\PccGMP;
use Pcc\Console;
use Pcc\Clib\Stdlib;

class Tokenizer
{
    /** @var Token[] */
    public array $tokens;
    public array $keywords = [
        'return', 'if', 'else', 'for', 'while', 'int', 'sizeof', 'char',
        'struct', 'union', 'short', 'long', 'void', 'typedef', '_Bool',
        'enum', 'static', 'goto', 'break', 'continue', 'switch', 'case',
        'default', 'extern', '_Alignof', '_Alignas', 'do', 'signed',
        'unsigned', 'float', 'double',
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
     * @param \Pcc\Tokenizer\Token $rest
     * @param \Pcc\Tokenizer\Token $tok
     * @param string $op
     * @return array{0: bool, 1: \Pcc\Tokenizer\Token}
     */
    public function consume(Token $rest, Token $tok, string $op): array
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
     * @param int $pos
     * @return array{0: string, 1: int}
     */
    public function readEscapedChar(int $pos): array
    {
        // Octal number
        if (preg_match('/^([0-7]{1, 3})/', substr($this->currentInput, $pos), $matches)){
            $c = chr(octdec($matches[1]));
            $pos += strlen($matches[1]);
            return [$c, $pos];
        }
        // Hexadecimal number
        if (preg_match('/^x([0-9a-fA-F]+)/', substr($this->currentInput, $pos), $matches)){
            $c = chr(hexdec($matches[1]));
            $pos += strlen($matches[1]) + 1;
            return [$c, $pos];
        }

        $c = match ($this->currentInput[$pos]){
            'a' => chr(7),
            'b' => chr(8),
            't' => "\t",
            'n' => "\n",
            'v' => "\v",
            'f' => "\f",
            'r' => "\r",
            'e' => chr(27),
            default => $this->currentInput[$pos],
        };
        $pos++;
        return [$c, $pos];
    }

    /**
     * @param int $start
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readStringLiteral(int $start): array
    {
        $endPos = $this->stringLiteralEndPos($start + 1);

        $str = '';
        $len = 0;
        for ($i = $start + 1; $i < $endPos; ){
            if ($this->currentInput[$i] === '\\'){
                [$c, $i] = $this->readEscapedChar($i + 1);
                $str .= $c;
            } else {
                $str .= $this->currentInput[$i];
                $i++;
            }
            $len++;
        }

        $tok = new Token(TokenKind::TK_STR, $str."\0", $start);
        $tok->ty = Type::arrayOf(Type::tyChar(), $len + 1);
        return [$tok, $endPos + 1];
    }

    /**
     * @param int $start
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readCharLiteral(int $start): array
    {
        $pos = $start + 1;
        if ($this->currentInput[$pos] === "\0"){
            Console::errorAt($start, 'unclosed char literal');
        }

        if ($this->currentInput[$pos] === '\\'){
            [$c, $pos] = $this->readEscapedChar($pos + 1);
        } else {
            $c = $this->currentInput[$pos];
            $pos++;
        }

        $end = strpos(substr($this->currentInput, $pos), "'");
        if ($end === false){
            Console::errorAt($start, 'unclosed char literal');
        }

        $str = substr($this->currentInput, $start + 1, ($pos + $end) - ($start + 1));
        $tok = new Token(TokenKind::TK_NUM, $str, $start, );

        $val = ord($c);
        if ($val & 0x80){
            // Sign bit is set (negative number)
            $signedVal = $val - 0x100;
        } else {
            // Sign bit is not set (positive number)
            $signedVal = $val;
        }
        $tok->val = $signedVal;
        $tok->gmpVal = gmp_init($signedVal);
        $tok->ty = Type::tyInt();
        return [$tok, $pos + $end + 1];
    }

    /**
     * @param int $start
     * @return \Pcc\Tokenizer\Token
     */
    public function readIntLiteral(int $start): Token
    {
        $p = $start;

        $base = 10;
        if (strtolower(substr($this->currentInput, $p, 2)) === '0x' and ctype_xdigit($this->currentInput[$p + 2])){
            $p += 2;
            $base = 16;
        } elseif (strtolower(substr($this->currentInput, $p, 2)) === '0b' and ($this->currentInput[$p + 2] === '0' or $this->currentInput[$p + 2] === '1')){
            $p += 2;
            $base = 2;
        } elseif ($this->currentInput[$p] === '0'){
            $base = 8;
        }

        /** @var \GMP $gmpVal */
        [$gmpVal, $end] = Stdlib::strtoul(substr($this->currentInput, $p), $base);
        $p += $end;

        // Read U, L or LL suffixes
        $l = false;
        $u = false;

        if (in_array(strtolower(substr($this->currentInput, $p, 3)), ['llu', 'ull', ])){
            $p += 3;
            $l = $u = true;
        } elseif (in_array(strtolower(substr($this->currentInput, $p, 2)), ['lu', 'ul', ])) {
            $p += 2;
            $l = $u = true;
        } elseif (strtolower(substr($this->currentInput, $p, 2)) === 'll'){
            $p += 2;
            $l = true;
        } elseif (strtolower($this->currentInput[$p]) === 'l'){
            $p++;
            $l = true;
        } elseif (strtolower($this->currentInput[$p]) === 'u'){
            $p++;
            $u = true;
        }

        // Infer a type
        if ($base === 10){
            if ($l and $u){
                $ty = Type::tyULong();
            } elseif ($l){
                $ty = Type::tyLong();
            } elseif ($u){
                $ty = (PccGMP::isTrue(PccGMP::shiftRArithmetic($gmpVal, 32)))? Type::tyULong(): Type::tyUInt();
            } else {
                $ty = (PccGMP::isTrue(PccGMP::shiftRArithmetic($gmpVal, 31)))? Type::tyLong(): Type::tyInt();
            }
        } else {
            if ($l and $u){
                $ty = Type::tyULong();
            } elseif ($l){
                $target = PccGMP::shiftRArithmetic($gmpVal, 63);
                $ty = (PccGMP::isTrue($target))? Type::tyULong(): Type::tyLong();
            } elseif ($u){
                $ty = (PccGMP::isTrue(PccGMP::shiftRArithmetic($gmpVal, 32)))? Type::tyULong(): Type::tyUInt();
            } elseif (PccGMP::isTrue(PccGMP::shiftRArithmetic($gmpVal, 63))){
                $ty = Type::tyULong();
            } elseif (PccGMP::isTrue(PccGMP::shiftRArithmetic($gmpVal, 32))){
                $ty = Type::tyLong();
            } elseif (PccGMP::isTrue(PccGMP::shiftRArithmetic($gmpVal, 31))){
                $ty = Type::tyUInt();
            } else {
                $ty = Type::tyInt();
            }
        }

        $tok = new Token(TokenKind::TK_NUM, substr($this->currentInput, $start, $p - $start), $start);
        $tok->val = PccGMP::toPHPInt($gmpVal);
        $tok->gmpVal = $gmpVal;
        $tok->ty = $ty;

        return $tok;
    }

    /**
     * @param int $start
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readNumber(int $start): array
    {
        // Try to parse as integer first
        $tok = $this->readIntLiteral($start);
        $end = $start + $tok->len;
        
        // If no decimal point, 'e'/'E' exponent, or 'f' suffix, return integer
        if ($end >= strlen($this->currentInput) || ! in_array(strtolower($this->currentInput[$end]), ['.', 'e', 'f'])){
            return [$tok, $end];
        }

        // Use C's strtod() for floating-point parsing
        [$val, $consumed] = Stdlib::strtod(substr($this->currentInput, $start));
        $end = $start + $consumed;
        
        // Check for suffix
        if ($end < strlen($this->currentInput) && strtolower($this->currentInput[$end]) === 'f'){
            $ty = Type::tyFloat();
            $end++;
        } elseif ($end < strlen($this->currentInput) && strtolower($this->currentInput[$end]) === 'l') {
            $ty = Type::tyDouble();
            $end++;
        } else {
            $ty = Type::tyDouble();
        }

        $tok = new Token(TokenKind::TK_NUM, substr($this->currentInput, $start, $end), $start);
        $tok->fval = $val;
        $tok->ty = $ty;

        return [$tok, $end];
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
        $atBol = true;
        
        while ($pos < strlen($this->currentInput)) {
            // Skip newline
            if ($this->currentInput[$pos] === "\n") {
                $pos++;
                $atBol = true;
                continue;
            }
            
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
            if (ctype_digit($this->currentInput[$pos]) or ($this->currentInput[$pos] === '.' and ctype_digit($this->currentInput[$pos + 1])) or 
                (substr($this->currentInput, $pos, 2) === '0x' or substr($this->currentInput, $pos, 2) === '0X') or
                preg_match('/^0[xX][0-9a-fA-F]*\\.?[0-9a-fA-F]*[pP][+-]?[0-9]+/', substr($this->currentInput, $pos))){
                [$token, $pos] = $this->readNumber($pos);
                $token->atBol = $atBol;
                $atBol = false;
                $tokens[] = $token;
                continue;
            }

            // String literal
            if ($this->currentInput[$pos] === '"') {
                [$token, $pos] = $this->readStringLiteral($pos);
                $token->atBol = $atBol;
                $atBol = false;
                $tokens[] = $token;
                continue;
            }

            // Character literal
            if ($this->currentInput[$pos] === "'") {
                [$token, $pos] = $this->readCharLiteral($pos);
                $token->atBol = $atBol;
                $atBol = false;
                $tokens[] = $token;
                continue;
            }

            // Identifier or keyword
            if ($this->isIdent1($this->currentInput[$pos])){
                $start = $pos;
                while ($pos < strlen($this->currentInput) && $this->isIdent2($this->currentInput[$pos])){
                    $pos++;
                }
                $token = new Token(TokenKind::TK_IDENT, substr($this->currentInput, $start, $pos - $start), $start);
                $token->atBol = $atBol;
                $atBol = false;
                $tokens[] = $token;
                continue;
            }

            // Three-letter punctuators
            if (in_array($token = substr($this->currentInput, $pos, 3), ['<<=', '>>=', '...', ])){
                $tok = new Token(TokenKind::TK_RESERVED, $token, $pos);
                $tok->atBol = $atBol;
                $atBol = false;
                $tokens[] = $tok;
                $pos += 3;
                continue;
            }

            // Two-letter punctuators
            if (in_array($token = substr($this->currentInput, $pos, 2), ['==', '!=', '<=', '>=', '->', '+=', '-=', '*=', '/=', '++', '--', '%=', '&=', '|=', '^=', '&&', '||', '<<', '>>', ])) {
                $tok = new Token(TokenKind::TK_RESERVED, $token, $pos);
                $tok->atBol = $atBol;
                $atBol = false;
                $tokens[] = $tok;
                $pos += 2;
                continue;
            }
            if (str_contains("!\"#$%&'()*+,-./:;<=>?@[\]^_`{|}~", $this->currentInput[$pos])) {
                $tok = new Token(TokenKind::TK_RESERVED, $this->currentInput[$pos], $pos);
                $tok->atBol = $atBol;
                $atBol = false;
                $tokens[] = $tok;
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
   }
}