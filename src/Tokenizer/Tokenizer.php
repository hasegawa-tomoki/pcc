<?php

namespace Pcc\Tokenizer;

use GMP;
use Pcc\Ast\Type;
use Pcc\Ast\Type\PccGMP;
use Pcc\Console;
use Pcc\Clib\Stdlib;
use Pcc\File;

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
    private File $currentFile;
    private static array $inputFiles = [];
    private static int $fileNo = 0;

    public function __construct(
        public readonly string $currentFilename,
        ?Token $tok = null,
        bool $skipFileRead = false
    )
    {
        if ($tok !== null) {
            // Initialize from token
            $this->tokens = [$tok];
            $this->currentInput = '';
            $this->currentFile = $tok->file ?? new File('', 0, '');
            return;
        }
        
        if ($skipFileRead) {
            // Initialize without reading file - will be set manually
            $this->currentInput = '';
            $this->currentFile = new File($currentFilename, 0, '');
            return;
        }
        
        $this->currentInput = $this->readFile($currentFilename);
        if ($this->currentInput === null) {
            Console::error("cannot open: %s", $currentFilename);
        }
        
        // Canonicalize newlines, remove backslash-newline sequences, and convert universal chars
        $canonicalized = self::canonicalizeNewline($this->currentInput);
        $backslashRemoved = self::removeBackslashNewline($canonicalized);
        $this->currentInput = self::convertUniversalChars($backslashRemoved);
        
        self::$fileNo++;
        $this->currentFile = new File($currentFilename, self::$fileNo, $this->currentInput);
        
        // Save the file for .file directive
        self::$inputFiles[] = $this->currentFile;
        
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
     * @param bool $isWide
     * @return array{0: int, 1: int}
     */
    public function readEscapedChar(int $pos, bool $isWide = false): array
    {
        // Octal number
        if (preg_match('/^([0-7]{1,3})/', substr($this->currentInput, $pos), $matches)){
            $c = octdec($matches[1]);
            $pos += strlen($matches[1]);
            return [$c, $pos];
        }
        // Hexadecimal number
        if (preg_match('/^x([0-9a-fA-F]+)/', substr($this->currentInput, $pos), $matches)){
            $c = hexdec($matches[1]);
            $pos += strlen($matches[1]) + 1;
            return [$c, $pos];
        }

        $c = match ($this->currentInput[$pos]){
            'a' => 7,
            'b' => 8,
            't' => 9,
            'n' => 10,
            'v' => 11,
            'f' => 12,
            'r' => 13,
            'e' => 27,
            default => ord($this->currentInput[$pos]),
        };
        $pos++;
        return [$c, $pos];
    }

    /**
     * @param int $start
     * @param int $quote
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readStringLiteral(int $start, int $quote): array
    {
        $endPos = $this->stringLiteralEndPos($quote + 1);

        $str = '';
        $len = 0;
        for ($i = $quote + 1; $i < $endPos; ){
            if ($this->currentInput[$i] === '\\'){
                [$c, $i] = $this->readEscapedChar($i + 1);
                $str .= chr($c);
            } else {
                $str .= $this->currentInput[$i];
                $i++;
            }
            $len++;
        }

        $tok = new Token(TokenKind::TK_STR, $str."\0", $start);
        $tok->originalStr = substr($this->currentInput, $start, ($endPos + 1) - $start);
        $tok->ty = Type::arrayOf(Type::tyChar(), $len + 1);
        return [$tok, $endPos + 1];
    }

    /**
     * Read a UTF-8-encoded string literal and transcode it in UTF-16.
     * 
     * UTF-16 is yet another variable-width encoding for Unicode. Code
     * points smaller than U+10000 are encoded in 2 bytes. Code points
     * equal to or larger than that are encoded in 4 bytes. Each 2 bytes
     * in the 4 byte sequence is called "surrogate", and a 4 byte sequence
     * is called a "surrogate pair".
     * @param int $start
     * @param int $quote
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readUtf16StringLiteral(int $start, int $quote): array
    {
        $endPos = $this->stringLiteralEndPos($quote + 1);
        $buf = [];
        $len = 0;

        for ($i = $quote + 1; $i < $endPos; ){
            if ($this->currentInput[$i] === '\\'){
                [$c, $i] = $this->readEscapedChar($i + 1);
                $buf[] = $c;
                $len++;
                continue;
            }

            [$c, $i] = self::decodeUtf8($this->currentInput, $i);
            if ($c < 0x10000) {
                // Encode a code point in 2 bytes.
                $buf[] = $c;
                $len++;
            } else {
                // Encode a code point in 4 bytes.
                $c -= 0x10000;
                $buf[] = 0xd800 + (($c >> 10) & 0x3ff);
                $buf[] = 0xdc00 + ($c & 0x3ff);
                $len += 2;
            }
        }

        // Add null terminator
        $buf[] = 0;
        $len++;

        // Convert to binary string representation for C memory layout
        $str = '';
        foreach ($buf as $val) {
            $str .= pack('v', $val); // little-endian 16-bit
        }

        $tok = new Token(TokenKind::TK_STR, $str, $start);
        $tok->originalStr = substr($this->currentInput, $start, ($endPos + 1) - $start);
        $tok->ty = Type::arrayOf(Type::tyUshort(), $len);
        return [$tok, $endPos + 1];
    }

    /**
     * @param int $start
     * @param \Pcc\Ast\Type $ty
     * @return array{0: \Pcc\Tokenizer\Token, 1: int}
     */
    public function readCharLiteral(int $start, ?\Pcc\Ast\Type $ty = null): array
    {
        // Find the quote position (handle prefixes like u', U', L')
        $quotePos = $start;
        while ($quotePos < strlen($this->currentInput) && $this->currentInput[$quotePos] !== "'") {
            $quotePos++;
        }
        
        if ($quotePos >= strlen($this->currentInput)) {
            Console::errorAt($start, 'unclosed char literal');
        }

        $pos = $quotePos + 1;
        if ($this->currentInput[$pos] === "\0"){
            Console::errorAt($start, 'unclosed char literal');
        }

        if ($this->currentInput[$pos] === '\\'){
            [$c, $pos] = $this->readEscapedChar($pos + 1);
        } else {
            [$c, $pos] = self::decodeUtf8($this->currentInput, $pos);
        }

        $end = strpos(substr($this->currentInput, $pos), "'");
        if ($end === false){
            Console::errorAt($start, 'unclosed char literal');
        }

        $originalStr = substr($this->currentInput, $start, ($pos + $end + 1) - $start);
        $tok = new Token(TokenKind::TK_NUM, $originalStr, $start, );

        // For multibyte characters, c is already an integer value (Unicode code point)
        $tok->val = $c;
        $tok->gmpVal = gmp_init($c);
        $tok->ty = $ty ?? Type::tyInt();
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
        } elseif ($p < strlen($this->currentInput) && strtolower($this->currentInput[$p]) === 'l'){
            $p++;
            $l = true;
        } elseif ($p < strlen($this->currentInput) && strtolower($this->currentInput[$p]) === 'u'){
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
                // For decimal literals, always use signed types - never promote to unsigned
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

    public function convertPpTokens(): void
    {
        foreach ($this->tokens as $idx => $token){
            if (in_array($token->str, $this->keywords)){
                $this->tokens[$idx]->kind = TokenKind::TK_KEYWORD;
            } elseif ($token->kind === TokenKind::TK_PP_NUM) {
                $this->convertPpNumber($this->tokens[$idx]);
            }
        }
    }

    public function convertPpNumber(Token $tok): void
    {
        // Try to parse as an integer constant first
        if ($this->convertPpInt($tok)) {
            return;
        }

        // If it's not an integer, it must be a floating point constant
        $val = floatval($tok->str);
        
        $str = $tok->str;
        $ty = Type::tyDouble();
        
        if (str_ends_with(strtolower($str), 'f')) {
            $ty = Type::tyFloat();
        } elseif (str_ends_with(strtolower($str), 'l')) {
            $ty = Type::tyDouble();
        }

        $tok->kind = TokenKind::TK_NUM;
        $tok->fval = $val;
        $tok->ty = $ty;
    }

    private function convertPpInt(Token $tok): bool
    {
        $p = $tok->str;
        $base = 10;
        
        if (str_starts_with($p, '0x') || str_starts_with($p, '0X')) {
            $p = substr($p, 2);
            $base = 16;
        } elseif (str_starts_with($p, '0b') || str_starts_with($p, '0B')) {
            $p = substr($p, 2);
            $base = 2;
        } elseif (str_starts_with($p, '0') && strlen($p) > 1 && ctype_digit($p[1])) {
            $base = 8;
        }

        $val = 0;
        $original_p = $p;
        
        for ($i = 0; $i < strlen($p); $i++) {
            $c = $p[$i];
            
            if ($c === 'u' || $c === 'U' || $c === 'l' || $c === 'L') {
                $p = substr($p, 0, $i);
                break;
            }
            
            $digit = -1;
            if (ctype_digit($c)) {
                $digit = ord($c) - ord('0');
            } elseif ($c >= 'a' && $c <= 'f') {
                $digit = ord($c) - ord('a') + 10;
            } elseif ($c >= 'A' && $c <= 'F') {
                $digit = ord($c) - ord('A') + 10;
            }
            
            if ($digit < 0 || $digit >= $base) {
                return false;
            }
            
            $val = $val * $base + $digit;
        }

        // Check suffix and determine type
        $suffix = strtolower(substr($tok->str, strlen($p) + ($base == 16 ? 2 : ($base == 2 ? 2 : 0))));
        $isLong = false;
        $isUnsigned = false;
        
        // Parse suffix
        for ($i = 0; $i < strlen($suffix); $i++) {
            if ($suffix[$i] === 'l') {
                $isLong = true;
            } elseif ($suffix[$i] === 'u') {
                $isUnsigned = true;
            }
        }
        
        // Create GMP value for accurate comparisons
        $gmpVal = gmp_init($p, $base);
        
        // Determine type based on suffix, value, and base
        if ($isLong && $isUnsigned) {
            $ty = Type::tyULong();
        } elseif ($isLong) {
            // For hexadecimal, if value doesn't fit in signed long, make it unsigned
            if ($base == 16 && gmp_cmp($gmpVal, gmp_init("9223372036854775807")) > 0) {
                $ty = Type::tyULong();
            } else {
                $ty = Type::tyLong();
            }
        } elseif ($isUnsigned) {
            // Check if value fits in unsigned int
            if (gmp_cmp($gmpVal, gmp_init("4294967295")) > 0) {
                $ty = Type::tyULong();
            } else {
                $ty = Type::tyUInt();
            }
        } else {
            // For decimal, follow standard promotion rules
            if ($base == 10) {
                if (gmp_cmp($gmpVal, gmp_init("2147483647")) > 0) {
                    // For decimal literals, always use signed long even if value doesn't fit
                    // This matches C standard behavior where decimal literals never become unsigned
                    $ty = Type::tyLong();
                } else {
                    $ty = Type::tyInt();
                }
            } else {
                // For hex/octal/binary, check unsigned ranges first
                if (gmp_cmp($gmpVal, gmp_init("2147483647")) > 0) {
                    if (gmp_cmp($gmpVal, gmp_init("4294967295")) <= 0) {
                        $ty = Type::tyUInt();
                    } elseif (gmp_cmp($gmpVal, gmp_init("9223372036854775807")) > 0) {
                        $ty = Type::tyULong();
                    } else {
                        $ty = Type::tyLong();
                    }
                } else {
                    $ty = Type::tyInt();
                }
            }
        }

        $tok->kind = TokenKind::TK_NUM;
        $tok->val = (int)$val;
        $tok->gmpVal = $gmpVal;
        $tok->ty = $ty;
        
        return true;
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
        $hasSpace = false;
        
        while ($pos < strlen($this->currentInput)) {
            // Skip newline
            if ($this->currentInput[$pos] === "\n") {
                $pos++;
                $atBol = true;
                $hasSpace = false;
                continue;
            }
            
            // Skip whitespace characters
            if (ctype_space($this->currentInput[$pos])) {
                $pos++;
                $hasSpace = true;
                continue;
            }

            // Skip line comments
            if (substr($this->currentInput, $pos, 2) === '//') {
                $pos += 2;
                while ($this->currentInput[$pos] !== "\n") {
                    $pos++;
                }
                $hasSpace = true;
                continue;
            }

            // Skip block comments
            if (substr($this->currentInput, $pos, 2) === '/*') {
                $pos += 2;
                while (substr($this->currentInput, $pos, 2) !== '*/') {
                    $pos++;
                }
                $pos += 2;
                $hasSpace = true;
                continue;
            }

            // Numeric literal (pp-number)
            if (ctype_digit($this->currentInput[$pos]) or ($this->currentInput[$pos] === '.' and isset($this->currentInput[$pos + 1]) and ctype_digit($this->currentInput[$pos + 1]))){
                $start = $pos;
                $pos++;
                
                // Read pp-number: more permissive than final number format
                while ($pos < strlen($this->currentInput)) {
                    $c = $this->currentInput[$pos];
                    
                    // Handle exponential notation with sign
                    if (($pos + 1) < strlen($this->currentInput) && 
                        ($c === 'e' || $c === 'E' || $c === 'p' || $c === 'P') &&
                        ($this->currentInput[$pos + 1] === '+' || $this->currentInput[$pos + 1] === '-')) {
                        $pos += 2;
                        continue;
                    }
                    
                    // Continue if alphanumeric or period
                    if (ctype_alnum($c) || $c === '.') {
                        $pos++;
                        continue;
                    }
                    
                    break;
                }
                
                $token = new Token(TokenKind::TK_PP_NUM, substr($this->currentInput, $start, $pos - $start), $start);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                continue;
            }

            // String literal
            if ($this->currentInput[$pos] === '"') {
                [$token, $newPos] = $this->readStringLiteral($pos, $pos);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                $pos = $newPos;
                continue;
            }

            // UTF-8 string literal
            if (substr($this->currentInput, $pos, 3) === 'u8"') {
                [$token, $newPos] = $this->readStringLiteral($pos, $pos + 2);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                $pos = $newPos;
                continue;
            }

            // UTF-16 string literal
            if (substr($this->currentInput, $pos, 2) === 'u"') {
                [$token, $newPos] = $this->readUtf16StringLiteral($pos, $pos + 1);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                $pos = $newPos;
                continue;
            }

            // Character literal
            if ($this->currentInput[$pos] === "'") {
                [$token, $pos] = $this->readCharLiteral($pos);
                // Cast to signed char (-128 to 127)
                if ($token->val > 127) {
                    $token->val = $token->val - 256;
                }
                $token->gmpVal = gmp_init($token->val);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                continue;
            }

            // UTF-16 character literal
            if (substr($this->currentInput, $pos, 2) === "u'") {
                [$token, $newPos] = $this->readCharLiteral($pos, Type::tyUshort());
                $token->val &= 0xffff;
                $token->gmpVal = gmp_init($token->val);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                $pos = $newPos;
                continue;
            }

            // Wide character literal
            if (substr($this->currentInput, $pos, 2) === "L'") {
                [$token, $newPos] = $this->readCharLiteral($pos);
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                $pos = $newPos;
                continue;
            }

            // UTF-32 character literal
            if (substr($this->currentInput, $pos, 2) === "U'") {
                [$token, $newPos] = $this->readCharLiteral($pos, Type::tyUInt());
                $token->atBol = $atBol;
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                $pos = $newPos;
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
                $token->hasSpace = $hasSpace;
                $token->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $token;
                continue;
            }

            // Three-letter punctuators
            if (in_array($token = substr($this->currentInput, $pos, 3), ['<<=', '>>=', '...', ])){
                $tok = new Token(TokenKind::TK_RESERVED, $token, $pos);
                $tok->atBol = $atBol;
                $tok->hasSpace = $hasSpace;
                $tok->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $tok;
                $pos += 3;
                continue;
            }

            // Two-letter punctuators
            if (in_array($token = substr($this->currentInput, $pos, 2), ['==', '!=', '<=', '>=', '->', '+=', '-=', '*=', '/=', '++', '--', '%=', '&=', '|=', '^=', '&&', '||', '<<', '>>', '##', ])) {
                $tok = new Token(TokenKind::TK_RESERVED, $token, $pos);
                $tok->atBol = $atBol;
                $tok->hasSpace = $hasSpace;
                $tok->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $tok;
                $pos += 2;
                continue;
            }
            if (str_contains("!\"#$%&'()*+,-./:;<=>?@[\]^_`{|}~", $this->currentInput[$pos])) {
                $tok = new Token(TokenKind::TK_RESERVED, $this->currentInput[$pos], $pos);
                $tok->atBol = $atBol;
                $tok->hasSpace = $hasSpace;
                $tok->file = $this->currentFile;
                $atBol = $hasSpace = false;
                $tokens[] = $tok;
                $pos++;
                continue;
            }

            Console::errorAt($pos, "invalid token: %s\n", $this->currentInput[$pos]);
        }

        $eofToken = new Token(TokenKind::TK_EOF, '', $pos);
        $eofToken->file = $this->currentFile;
        $tokens[] = $eofToken;
        $this->tokens = $tokens;
        for ($i = 0; $i < count($this->tokens) - 1; $i++){
            $this->tokens[$i]->next = $this->tokens[$i + 1];
        }
        $this->addLineNumbers();
   }
   
    private function readFile(string $path): ?string
    {
        if ($path === '-') {
            $contents = file_get_contents('php://stdin');
        } else {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                return null;
            }
        }
        
        if (!str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }
        
        return $contents;
    }
    
    public static function getInputFiles(): array
    {
        return self::$inputFiles;
    }
    
    public static function newFile(string $name, int $fileNo, string $contents): File
    {
        return new File($name, $fileNo, $contents);
    }

    // Read a UTF-8-encoded Unicode code point from a source file.
    // We assume that source files are always in UTF-8.
    private static function decodeUtf8(string $p, int $pos): array
    {
        if (ord($p[$pos]) < 128) {
            return [ord($p[$pos]), $pos + 1];
        }

        $start = $pos;
        $len = 0;
        $c = 0;

        if (ord($p[$pos]) >= 0b11110000) {
            $len = 4;
            $c = ord($p[$pos]) & 0b111;
        } elseif (ord($p[$pos]) >= 0b11100000) {
            $len = 3;
            $c = ord($p[$pos]) & 0b1111;
        } elseif (ord($p[$pos]) >= 0b11000000) {
            $len = 2;
            $c = ord($p[$pos]) & 0b11111;
        } else {
            Console::errorAt($pos, $p, "invalid UTF-8 sequence");
        }

        for ($i = 1; $i < $len; $i++) {
            if (($pos + $i) >= strlen($p) || (ord($p[$pos + $i]) >> 6) != 0b10) {
                Console::errorAt($start, $p, "invalid UTF-8 sequence");
            }
            $c = ($c << 6) | (ord($p[$pos + $i]) & 0b111111);
        }

        return [$c, $pos + $len];
    }

    // Encode a given character in UTF-8.
    private static function encodeUtf8(int $c): string
    {
        if ($c <= 0x7F) {
            return chr($c);
        }

        if ($c <= 0x7FF) {
            return chr(0b11000000 | ($c >> 6)) . 
                   chr(0b10000000 | ($c & 0b00111111));
        }

        if ($c <= 0xFFFF) {
            return chr(0b11100000 | ($c >> 12)) . 
                   chr(0b10000000 | (($c >> 6) & 0b00111111)) . 
                   chr(0b10000000 | ($c & 0b00111111));
        }

        return chr(0b11110000 | ($c >> 18)) . 
               chr(0b10000000 | (($c >> 12) & 0b00111111)) . 
               chr(0b10000000 | (($c >> 6) & 0b00111111)) . 
               chr(0b10000000 | ($c & 0b00111111));
    }

    private static function readUniversalChar(string $p, int $len): int
    {
        $c = 0;
        for ($i = 0; $i < $len; $i++) {
            if (!ctype_xdigit($p[$i])) {
                return 0;
            }
            $c = ($c << 4) | hexdec($p[$i]);
        }
        return $c;
    }

    // Replace \u or \U escape sequences with corresponding UTF-8 bytes.
    private static function convertUniversalChars(string $p): string
    {
        $result = '';
        $i = 0;
        $len = strlen($p);

        while ($i < $len) {
            if ($i < $len - 5 && substr($p, $i, 2) === '\\u') {
                $c = self::readUniversalChar(substr($p, $i + 2, 4), 4);
                if ($c > 0) {
                    $i += 6;
                    $result .= self::encodeUtf8($c);
                } else {
                    $result .= $p[$i++];
                }
            } elseif ($i < $len - 9 && substr($p, $i, 2) === '\\U') {
                $c = self::readUniversalChar(substr($p, $i + 2, 8), 8);
                if ($c > 0) {
                    $i += 10;
                    $result .= self::encodeUtf8($c);
                } else {
                    $result .= $p[$i++];
                }
            } elseif ($p[$i] === '\\' && $i + 1 < $len) {
                $result .= $p[$i++];
                $result .= $p[$i++];
            } else {
                $result .= $p[$i++];
            }
        }

        return $result;
    }

    // Replaces \r or \r\n with \n.
    private static function canonicalizeNewline(string $p): string
    {
        $result = '';
        $i = 0;
        $len = strlen($p);

        while ($i < $len) {
            if ($i < $len - 1 && $p[$i] === "\r" && $p[$i + 1] === "\n") {
                // \r\n -> \n
                $i += 2;
                $result .= "\n";
            } elseif ($p[$i] === "\r") {
                // \r -> \n
                $i++;
                $result .= "\n";
            } else {
                $result .= $p[$i];
                $i++;
            }
        }

        return $result;
    }

    // Removes backslashes followed by a newline.
    private static function removeBackslashNewline(string $p): string
    {
        $i = 0;
        $j = 0;
        $result = '';
        $len = strlen($p);

        // We want to keep the number of newline characters so that
        // the logical line number matches the physical one.
        // This counter maintain the number of newlines we have removed.
        $n = 0;

        while ($i < $len) {
            if ($i < $len - 1 && $p[$i] === '\\' && $p[$i + 1] === "\n") {
                $i += 2;
                $n++;
            } elseif ($p[$i] === "\n") {
                $result .= $p[$i];
                $i++;
                for (; $n > 0; $n--) {
                    $result .= "\n";
                }
            } else {
                $result .= $p[$i];
                $i++;
            }
        }

        for (; $n > 0; $n--) {
            $result .= "\n";
        }

        return $result;
    }
    
    public static function tokenizeFile(File $file): Token
    {
        $tokenizer = new self($file->name, null, true);
        // Override file content initialization
        $tokenizer->currentFile = $file;
        // Canonicalize newlines, remove backslash-newline sequences, and convert universal chars before tokenizing
        $canonicalized = self::canonicalizeNewline($file->contents);
        $backslashRemoved = self::removeBackslashNewline($canonicalized);
        $tokenizer->currentInput = self::convertUniversalChars($backslashRemoved);
        Console::$currentFilename = $file->name;
        Console::$currentInput = $tokenizer->currentInput;
        $tokenizer->tokenize();
        return $tokenizer->tok;
    }
    
    public function updateTokensArray(Token $newTok): void
    {
        $this->tokens = [];
        $current = $newTok;
        while ($current && $current->kind !== TokenKind::TK_EOF) {
            $this->tokens[] = $current;
            $current = $current->next;
        }
        if ($current) {
            $this->tokens[] = $current; // Add EOF token
        }
    }
    
    public function tokenizeString(string $input): Token
    {
        $this->currentInput = $input;
        $this->currentFile = new File('<built-in>', 1, $input);
        $this->tokenize();
        return $this->tok;
    }
}