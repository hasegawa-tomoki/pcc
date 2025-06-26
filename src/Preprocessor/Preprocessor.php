<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\TokenKind;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Console;
use Pcc\Ast\Parser;
use Pcc\Ast\Type;

class Macro
{
    public ?Macro $next;
    public string $name;
    public bool $isObjlike;
    public ?MacroParam $params = null;
    public bool $isVariadic = false;
    public ?Token $body;
    public bool $deleted;
    public $handler = null; // callable|null for dynamic macros

    public function __construct(?Macro $next, string $name, bool $isObjlike, ?Token $body)
    {
        $this->next = $next;
        $this->name = $name;
        $this->isObjlike = $isObjlike;
        $this->body = $body;
        $this->deleted = false;
    }
}

// `#if` can be nested, so we use a stack to manage nested `#if`s.
class CondIncl
{
    public const IN_THEN = 0;
    public const IN_ELIF = 1;
    public const IN_ELSE = 2;

    public ?CondIncl $next;
    public int $ctx;
    public Token $tok;
    public bool $included;

    public function __construct(?CondIncl $next, Token $tok, bool $included)
    {
        $this->next = $next;
        $this->ctx = self::IN_THEN;
        $this->tok = $tok;
        $this->included = $included;
    }
}

class Preprocessor
{
    private static ?Macro $macros = null;
    private static ?CondIncl $condIncl = null;

    private static function isHash(Token $tok): bool
    {
        return $tok->atBol && $tok->str === '#';
    }

    // Returns true if a given file exists.
    private static function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Some preprocessor directives such as #include allow extraneous
     * tokens before newline. This function skips such tokens.
     */
    private static function skipLine(Token $tok): Token
    {
        if ($tok->atBol) {
            return $tok;
        }
        Console::warnTok($tok, "extra token");
        while ($tok !== null && !$tok->atBol && $tok->kind !== TokenKind::TK_EOF) {
            $tok = $tok->next;
        }
        return $tok ?? new Token(TokenKind::TK_EOF, '', 0);
    }

    /**
     * Skip a token if it matches the expected string
     */
    private static function skip(Token &$tok, string $expected): void
    {
        if ($tok->str !== $expected) {
            Console::errorTok($tok, "expected '" . $expected . "'");
        }
        $tok = $tok->next;
    }

    /**
     * Copy a token
     */
    private static function copyToken(Token $tok): Token
    {
        $t = new Token($tok->kind, $tok->str, $tok->pos);
        if (isset($tok->val)) {
            $t->val = $tok->val;
        }
        if (isset($tok->gmpVal)) {
            $t->gmpVal = $tok->gmpVal;
        }
        if (isset($tok->fval)) {
            $t->fval = $tok->fval;
        }
        if (isset($tok->ty)) {
            $t->ty = $tok->ty;
        }
        if (isset($tok->lineNo)) {
            $t->lineNo = $tok->lineNo;
        }
        $t->atBol = $tok->atBol;
        $t->hasSpace = $tok->hasSpace;
        if (isset($tok->file)) {
            $t->file = $tok->file;
        }
        if (isset($tok->hideset)) {
            $t->hideset = $tok->hideset;
        }
        if (isset($tok->origin)) {
            $t->origin = $tok->origin;
        }
        if (isset($tok->originalStr)) {
            $t->originalStr = $tok->originalStr;
        }
        // nextプロパティは呼び出し側で設定する
        return $t;
    }

    private static function newEof(Token $tok): Token
    {
        $t = self::copyToken($tok);
        $t->kind = TokenKind::TK_EOF;
        $t->str = '';
        return $t;
    }

    private static function newHideset(string $name): Hideset
    {
        return new Hideset($name);
    }

    private static function hidesetUnion(?Hideset $hs1, ?Hideset $hs2): ?Hideset
    {
        $head = new Hideset('');
        $cur = $head;

        for (; $hs1; $hs1 = $hs1->next) {
            $cur->next = self::newHideset($hs1->name);
            $cur = $cur->next;
        }
        $cur->next = $hs2;
        return $head->next;
    }

    private static function hidesetContains(?Hideset $hs, string $s, int $len): bool
    {
        for (; $hs; $hs = $hs->next) {
            if (strlen($hs->name) === $len && substr($hs->name, 0, $len) === $s) {
                return true;
            }
        }
        return false;
    }

    private static function hidesetIntersection(?Hideset $hs1, ?Hideset $hs2): ?Hideset
    {
        $head = new Hideset('');
        $cur = $head;

        for (; $hs1; $hs1 = $hs1->next) {
            if (self::hidesetContains($hs2, $hs1->name, strlen($hs1->name))) {
                $cur->next = self::newHideset($hs1->name);
                $cur = $cur->next;
            }
        }
        return $head->next;
    }

    private static function addHideset(Token $tok, ?Hideset $hs): Token
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        for (; $tok; $tok = $tok->next) {
            $t = self::copyToken($tok);
            $t->hideset = self::hidesetUnion($t->hideset, $hs);
            $cur->next = $t;
            $cur = $t;
        }
        return $head->next;
    }
    
    /**
     * Append tok2 to the end of tok1
     */
    private static function append(Token $tok1, Token $tok2): Token
    {
        if ($tok1->kind === TokenKind::TK_EOF) {
            return $tok2;
        }
        
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;
        
        for (; $tok1->kind !== TokenKind::TK_EOF; $tok1 = $tok1->next) {
            $cur->next = self::copyToken($tok1);
            $cur = $cur->next;
        }
        $cur->next = $tok2;
        return $head->next;
    }

    private static function skipCondIncl2(Token $tok): Token
    {
        while ($tok->kind !== TokenKind::TK_EOF) {
            if (self::isHash($tok) && 
                ($tok->next->str === 'if' or $tok->next->str === 'ifdef' or
                 $tok->next->str === 'ifndef')) {
                $tok = self::skipCondIncl2($tok->next->next);
                continue;
            }
            if (self::isHash($tok) && $tok->next->str === 'endif') {
                return $tok->next->next;
            }
            $tok = $tok->next;
        }
        return $tok;
    }

    // Skip until next `#else`, `#elif` or `#endif`.
    // Nested `#if` and `#endif` are skipped.
    private static function skipCondIncl(Token $tok): Token
    {
        while ($tok->kind !== TokenKind::TK_EOF) {
            if (self::isHash($tok) && 
                ($tok->next->str === 'if' or $tok->next->str === 'ifdef' or
                 $tok->next->str === 'ifndef')) {
                $tok = self::skipCondIncl2($tok->next->next);
                continue;
            }

            if (self::isHash($tok) and
                ($tok->next->str === 'elif' or $tok->next->str === 'else' or
                 $tok->next->str === 'endif')) {
                break;
            }
            $tok = $tok->next;
        }
        return $tok;
    }

    // Double-quote a given string and returns it.
    private static function quoteString(string $str): string
    {
        $buf = '';
        $buf .= '"';
        for ($i = 0; $i < strlen($str); $i++) {
            if ($str[$i] === '\\' || $str[$i] === '"') {
                $buf .= '\\';
            }
            $buf .= $str[$i];
        }
        $buf .= '"';
        return $buf;
    }

    private static function newStrToken(string $str, Token $tmpl): Token
    {
        // Use the tokenizer to properly parse the quoted string
        $quotedStr = self::quoteString($str);
        $file = Tokenizer::newFile($tmpl->file->name, $tmpl->file->fileNo, $quotedStr);
        $token = Tokenizer::tokenizeFile($file);
        
        // Find the string token
        while ($token && $token->kind !== TokenKind::TK_STR && $token->kind !== TokenKind::TK_EOF) {
            $token = $token->next;
        }
        
        if ($token && $token->kind === TokenKind::TK_STR) {
            $token->atBol = $tmpl->atBol;
            $token->hasSpace = $tmpl->hasSpace;
            $token->next = null;
            return $token;
        }
        
        // Fallback
        $strContent = $str . "\0";
        $strToken = new Token(TokenKind::TK_STR, $strContent, $tmpl->pos);
        $strToken->file = $tmpl->file;
        $strToken->lineNo = $tmpl->lineNo ?? 1;
        $strToken->atBol = $tmpl->atBol;
        $strToken->hasSpace = $tmpl->hasSpace;
        $strToken->ty = Type::arrayOf(Type::tyChar(), strlen($strContent));
        $strToken->next = null;
        return $strToken;
    }

    // Concatenates all tokens in `tok` and returns a new string.
    private static function joinTokens(Token $tok, ?Token $end = null): string
    {
        $buf = '';

        // Copy token texts.
        for ($t = $tok; $t !== $end && $t && $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
            if ($t !== $tok && $t->hasSpace) {
                $buf .= ' ';
            }
            
            if ($t->kind === TokenKind::TK_STR) {
                // Use original string representation if available
                if ($t->originalStr !== null) {
                    $buf .= $t->originalStr;
                } else {
                    // Fallback to reconstructed form
                    $content = $t->str;
                    if (strlen($content) > 0 && $content[-1] === "\0") {
                        $content = substr($content, 0, -1);
                    }
                    $content = str_replace(['\\', '"'], ['\\\\', '\\"'], $content);
                    $buf .= '"' . $content . '"';
                }
            } else {
                $buf .= $t->str;
            }
        }
        return $buf;
    }

    // Concatenates all tokens in `arg` and returns a new string token.
    // This function is used for the stringizing operator (#).
    private static function stringize(Token $hash, Token $arg): Token
    {
        // Create a new string token. We need to set some value to its
        // source location for error reporting function, so we use a macro
        // name token as a template.
        $s = self::joinTokens($arg);
        return self::newStrToken($s, $hash);
    }

    // Concatenate two tokens to create a new token.
    private static function paste(Token $lhs, Token $rhs): Token
    {
        // Paste the two tokens.
        $buf = $lhs->str . $rhs->str;
        
        // Tokenize the resulting string.
        $file = Tokenizer::newFile($lhs->file->name, $lhs->file->fileNo, $buf);
        $tok = Tokenizer::tokenizeFile($file);
        
        if ($tok->next && $tok->next->kind !== TokenKind::TK_EOF) {
            Console::errorTok($lhs, "pasting forms '%s', an invalid token", $buf);
        }
        return $tok;
    }

    private static function newNumToken(int $val, Token $tmpl): Token
    {
        $buf = sprintf("%d\n", $val);
        $file = Tokenizer::newFile($tmpl->file->name, $tmpl->file->fileNo, $buf);
        $tok = Tokenizer::tokenizeFile($file);
        self::convertPpTokens($tok);
        return $tok;
    }

    // Copy all tokens until the next newline, terminate them with
    // an EOF token and then returns them. This function is used to
    // create a new list of tokens for `#if` arguments.
    private static function copyLine(Token &$rest, Token $tok): Token
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        // Copy tokens until at_bol becomes true (start of next line)
        while ($tok->kind !== TokenKind::TK_EOF && !$tok->atBol) {
            $cur->next = self::copyToken($tok);
            $cur = $cur->next;
            $tok = $tok->next;
        }

        $cur->next = self::newEof($tok);
        $rest = $tok;
        return $head->next;
    }

    // Read an #include argument.
    private static function readIncludeFilename(Token &$rest, Token $tok, bool &$isDquote): string
    {
        // Pattern 1: #include "foo.h"
        if ($tok->kind === TokenKind::TK_STR) {
            // A double-quoted filename for #include is a special kind of
            // token, and we don't want to interpret any escape sequences in it.
            // For example, "\f" in "C:\foo" is not a formfeed character but
            // just two non-control characters, backslash and f.
            // So we don't want to use token->str.
            $isDquote = true;
            $rest = self::skipLine($tok->next);
            
            // Extract filename from string literal (remove null terminator)
            $filename = rtrim($tok->str, "\0");
            return $filename;
        }

        // Pattern 2: #include <foo.h>
        if ($tok->str === '<') {
            // Reconstruct a filename from a sequence of tokens between
            // "<" and ">".
            $start = $tok;

            // Find closing ">".
            for (; $tok->str !== '>'; $tok = $tok->next) {
                if ($tok->atBol || $tok->kind === TokenKind::TK_EOF) {
                    Console::errorTok($tok, "expected '>'");
                }
            }

            $isDquote = false;
            $rest = self::skipLine($tok->next);
            return self::joinTokens($start->next, $tok);
        }

        // Pattern 3: #include FOO
        // In this case FOO must be macro-expanded to either
        // a single string token or a sequence of "<" ... ">".
        if ($tok->kind === TokenKind::TK_IDENT) {
            $tok2 = self::preprocess2(self::copyLine($rest, $tok));
            $dummyRest = $tok2;
            return self::readIncludeFilename($dummyRest, $tok2, $isDquote);
        }

        Console::errorTok($tok, "expected a filename");
        return ""; // This line should never be reached
    }

    private static function searchIncludePaths(string $filename): ?string
    {
        if ($filename[0] === '/') {
            return $filename;
        }

        // Search a file from the include paths.
        $includePaths = \Pcc\Pcc::getIncludePaths();
        foreach ($includePaths->getData() as $path) {
            $fullPath = $path . '/' . $filename;
            if (self::fileExists($fullPath)) {
                return $fullPath;
            }
        }
        
        // For testing purposes, also search in the current directory and TestCase directory
        if (self::fileExists($filename)) {
            return $filename;
        }
        
        // Also search in TestCase directory for tests
        $testCasePath = 'test/' . $filename;
        if (self::fileExists($testCasePath)) {
            return $testCasePath;
        }
        
        return null;
    }

    private static function includeFile(Token $tok, string $path, Token $filenameTok): Token
    {
        // Check if file exists first
        if (!self::fileExists($path)) {
            Console::errorTok($filenameTok, "%s: cannot open file", $path);
        }
        
        // Create a new tokenizer for the file
        $tokenizer = new Tokenizer($path);
        $tokenizer->tokenize();
        $tok2 = $tokenizer->tok;
        
        return self::append($tok2, $tok);
    }

    private static function readConstExpr(Token &$rest, Token $tok): Token
    {
        $tok = self::copyLine($rest, $tok);

        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        while ($tok->kind !== TokenKind::TK_EOF) {
            // "defined(foo)" or "defined foo" becomes "1" if macro "foo"
            // is defined. Otherwise "0".
            if ($tok->str === 'defined') {
                $start = $tok;
                $tok = $tok->next;
                $hasParen = false;
                
                if ($tok->str === '(') {
                    $hasParen = true;
                    $tok = $tok->next;
                }

                if ($tok->kind !== TokenKind::TK_IDENT) {
                    Console::errorTok($start, "macro name must be an identifier");
                }
                
                $m = self::findMacro($tok);
                $tok = $tok->next;

                if ($hasParen) {
                    if ($tok->str !== ')') {
                        Console::errorTok($tok, "expected ')'");
                    }
                    $tok = $tok->next;
                }

                $cur->next = self::newNumToken($m ? 1 : 0, $start);
                $cur = $cur->next;
                continue;
            }

            $cur->next = $tok;
            $cur = $cur->next;
            $tok = $tok->next;
        }

        $cur->next = $tok;
        return $head->next;
    }

    // Read and evaluate a constant expression.
    private static function evalConstExpr(Token &$rest, Token $tok): int
    {
        $start = $tok;
        $expr = self::readConstExpr($rest, $tok->next);
        $expr = self::preprocess2($expr);

        if ($expr->kind === TokenKind::TK_EOF) {
            Console::errorTok($start, "no expression");
        }

        // Convert pp-numbers to regular numbers
        self::convertPpTokens($expr);

        // [https://www.sigbus.info/n1570#6.10.1p4] The standard requires
        // we replace remaining non-macro identifiers with "0" before
        // evaluating a constant expression. For example, `#if foo` is
        // equivalent to `#if 0` if foo is not defined.
        for ($t = $expr; $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
            if ($t->kind === TokenKind::TK_IDENT) {
                $next = $t->next;
                $zeroToken = self::newNumToken(0, $t);
                $t->kind = $zeroToken->kind;
                $t->str = $zeroToken->str;
                $t->val = $zeroToken->val;
                if (isset($zeroToken->gmpVal)) {
                    $t->gmpVal = $zeroToken->gmpVal;
                }
                if (isset($zeroToken->ty)) {
                    $t->ty = $zeroToken->ty;
                }
                $t->next = $next;
            }
        }

        $parser = new Parser(new Tokenizer('', $expr));
        [$val, $rest2] = $parser->constExpr($expr, $expr);
        if ($rest2->kind !== TokenKind::TK_EOF) {
            Console::errorTok($rest2, "extra token");
        }
        return gmp_intval($val);
    }

    private static function pushCondIncl(Token $tok, bool $included): CondIncl
    {
        $ci = new CondIncl(self::$condIncl, $tok, $included);
        self::$condIncl = $ci;
        return $ci;
    }

    private static function findMacro(Token $tok): ?Macro
    {
        if ($tok->kind !== TokenKind::TK_IDENT) {
            return null;
        }

        for ($m = self::$macros; $m; $m = $m->next) {
            if (strlen($m->name) === strlen($tok->str) && $m->name === $tok->str) {
                return $m->deleted ? null : $m;
            }
        }
        return null;
    }

    private static function addMacro(string $name, bool $isObjlike, ?Token $body): Macro
    {
        $m = new Macro(self::$macros, $name, $isObjlike, $body);
        self::$macros = $m;
        return $m;
    }

    private static function readMacroParams(Token &$rest, Token $tok, bool &$isVariadic): ?MacroParam
    {
        $head = new MacroParam('');
        $cur = $head;

        while ($tok->str !== ')') {
            if ($cur !== $head) {
                if ($tok->str !== ',') {
                    Console::errorTok($tok, "expected ',' in macro parameters");
                }
                $tok = $tok->next;
            }

            if ($tok->str === '...') {
                $isVariadic = true;
                $rest = $tok->next->next; // skip "..." and ")"
                return $head->next;
            }

            if ($tok->kind !== TokenKind::TK_IDENT) {
                Console::errorTok($tok, "expected an identifier");
            }
            $m = new MacroParam($tok->str);
            $cur->next = $m;
            $cur = $m;
            $tok = $tok->next;
        }
        $rest = $tok->next;
        return $head->next;
    }

    private static function readMacroDefinition(Token &$rest, Token $tok): void
    {
        if ($tok->kind !== TokenKind::TK_IDENT) {
            Console::errorTok($tok, "macro name must be an identifier");
        }
        $name = $tok->str;
        $tok = $tok->next;

        if (!$tok->hasSpace && $tok->str === '(') {
            // Function-like macro
            $isVariadic = false;
            $params = self::readMacroParams($tok, $tok->next, $isVariadic);
            $m = self::addMacro($name, false, self::copyLine($rest, $tok));
            $m->params = $params;
            $m->isVariadic = $isVariadic;
        } else {
            // Object-like macro
            self::addMacro($name, true, self::copyLine($rest, $tok));
        }
    }

    private static function readMacroArgOne(Token &$rest, Token $tok, bool $readRest): MacroArg
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;
        $level = 0;

        while (true) {
            if ($level === 0 && $tok->str === ')') {
                break;
            }
            if ($level === 0 && !$readRest && $tok->str === ',') {
                break;
            }

            if ($tok->kind === TokenKind::TK_EOF) {
                Console::errorTok($tok, "premature end of input");
            }

            if ($tok->str === '(') {
                $level++;
            } elseif ($tok->str === ')') {
                $level--;
            }

            $cur->next = self::copyToken($tok);
            $cur = $cur->next;
            $tok = $tok->next;
        }

        $cur->next = self::newEof($tok);

        $arg = new MacroArg('', $head->next);
        $rest = $tok;
        return $arg;
    }

    private static function readMacroArgs(Token &$rest, Token $tok, ?MacroParam $params, bool $isVariadic): ?MacroArg
    {
        $start = $tok;
        $tok = $tok->next->next; // skip identifier and '('

        $head = new MacroArg('', new Token(TokenKind::TK_EOF, '', 0));
        $cur = $head;

        $pp = $params;
        for (; $pp; $pp = $pp->next) {
            if ($cur !== $head) {
                if ($tok->str !== ',') {
                    Console::errorTok($tok, "expected ',' in macro arguments");
                }
                $tok = $tok->next;
            }
            $arg = self::readMacroArgOne($tok, $tok, false);
            $cur->next = $arg;
            $cur = $cur->next;
            $cur->name = $pp->name;
        }

        if ($isVariadic) {
            if ($tok->str === ')') {
                $arg = new MacroArg('__VA_ARGS__', self::newEof($tok));
            } else {
                if ($pp !== $params) {
                    self::skip($tok, ',');
                }
                $arg = self::readMacroArgOne($tok, $tok, true);
                $arg->name = '__VA_ARGS__';
            }
            $cur->next = $arg;
        } elseif ($pp) {
            Console::errorTok($start, "too many arguments");
        }

        self::skip($tok, ')');
        $rest = $tok;
        return $head->next;
    }

    private static function findArg(?MacroArg $args, Token $tok): ?MacroArg
    {
        for ($ap = $args; $ap; $ap = $ap->next) {
            if (strlen($tok->str) === strlen($ap->name) && $tok->str === $ap->name) {
                return $ap;
            }
        }
        return null;
    }

    // Replace func-like macro parameters with given arguments.
    private static function subst(Token $tok, ?MacroArg $args): Token
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        while ($tok->kind !== TokenKind::TK_EOF) {
            // "#" followed by a parameter is replaced with stringized actuals.
            if ($tok->str === '#') {
                $arg = self::findArg($args, $tok->next);
                if (!$arg) {
                    Console::errorTok($tok->next, "'#' is not followed by a macro parameter");
                }
                $stringToken = self::stringize($tok, $arg->tok);
                $cur->next = $stringToken;
                $cur = $stringToken;
                $tok = $tok->next->next;
                continue;
            }

            if ($tok->str === '##') {
                if ($cur === $head) {
                    Console::errorTok($tok, "'##' cannot appear at start of macro expansion");
                }

                if ($tok->next->kind === TokenKind::TK_EOF) {
                    Console::errorTok($tok, "'##' cannot appear at end of macro expansion");
                }

                $arg = self::findArg($args, $tok->next);
                if ($arg) {
                    if ($arg->tok->kind !== TokenKind::TK_EOF) {
                        $pastedToken = self::paste($cur, $arg->tok);
                        // Replace current token with pasted result
                        $cur->kind = $pastedToken->kind;
                        $cur->str = $pastedToken->str;
                        if (isset($pastedToken->val)) {
                            $cur->val = $pastedToken->val;
                        }
                        if (isset($pastedToken->gmpVal)) {
                            $cur->gmpVal = $pastedToken->gmpVal;
                        }
                        if (isset($pastedToken->fval)) {
                            $cur->fval = $pastedToken->fval;
                        }
                        if (isset($pastedToken->ty)) {
                            $cur->ty = $pastedToken->ty;
                        }
                        // Add remaining tokens from the argument
                        for ($t = $arg->tok->next; $t && $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
                            $cur->next = self::copyToken($t);
                            $cur = $cur->next;
                        }
                    }
                    $tok = $tok->next->next;
                    continue;
                }

                $pastedToken = self::paste($cur, $tok->next);
                // Replace current token with pasted result
                $cur->kind = $pastedToken->kind;
                $cur->str = $pastedToken->str;
                if (isset($pastedToken->val)) {
                    $cur->val = $pastedToken->val;
                }
                if (isset($pastedToken->gmpVal)) {
                    $cur->gmpVal = $pastedToken->gmpVal;
                }
                if (isset($pastedToken->fval)) {
                    $cur->fval = $pastedToken->fval;
                }
                if (isset($pastedToken->ty)) {
                    $cur->ty = $pastedToken->ty;
                }
                $tok = $tok->next->next;
                continue;
            }

            $arg = self::findArg($args, $tok);

            if ($arg && $tok->next && $tok->next->str === '##') {
                $rhs = $tok->next->next;

                if ($arg->tok->kind === TokenKind::TK_EOF) {
                    $arg2 = self::findArg($args, $rhs);
                    if ($arg2) {
                        for ($t = $arg2->tok; $t && $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
                            $cur->next = self::copyToken($t);
                            $cur = $cur->next;
                        }
                    } else {
                        $cur->next = self::copyToken($rhs);
                        $cur = $cur->next;
                    }
                    $tok = $rhs->next;
                    continue;
                }

                for ($t = $arg->tok; $t && $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
                    $cur->next = self::copyToken($t);
                    $cur = $cur->next;
                }
                $tok = $tok->next;
                continue;
            }

            // Handle a macro token. Macro arguments are completely macro-expanded
            // before they are substituted into a macro body.
            if ($arg) {
                $t = self::preprocess2($arg->tok);
                $t->atBol = $tok->atBol;
                $t->hasSpace = $tok->hasSpace;
                while ($t->kind !== TokenKind::TK_EOF) {
                    $cur->next = self::copyToken($t);
                    $cur = $cur->next;
                    $t = $t->next;
                }
                $tok = $tok->next;
                continue;
            }

            // Handle a non-macro token.
            $cur->next = self::copyToken($tok);
            $cur = $cur->next;
            $tok = $tok->next;
        }

        $cur->next = $tok;
        return $head->next;
    }

    // If tok is a macro, expand it and return true.
    // Otherwise, do nothing and return false.
    private static function expandMacro(Token &$rest, Token $tok): bool
    {
        // Check hideset to prevent infinite recursion
        if (isset($tok->hideset) && self::hidesetContains($tok->hideset, $tok->str, strlen($tok->str))) {
            return false;
        }

        $m = self::findMacro($tok);
        if (!$m || $m->deleted) {
            return false;
        }

        // Built-in dynamic macro application such as __LINE__
        if ($m->handler !== null) {
            $rest = call_user_func($m->handler, $tok);
            $rest->next = $tok->next;
            $rest->atBol = $tok->atBol;
            $rest->hasSpace = $tok->hasSpace;
            return true;
        }

        // Object-like macro application
        if ($m->isObjlike) {
            $hs = self::newHideset($m->name);
            $body = self::addHideset($m->body, self::hidesetUnion($tok->hideset ?? null, $hs));
            
            // Set origin for all tokens in the body
            for ($t = $body; $t && $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
                $t->origin = $tok;
            }
            
            $rest = self::append($body, $tok->next);
            $rest->atBol = $tok->atBol;
            $rest->hasSpace = $tok->hasSpace;
            return true;
        }

        // If a funclike macro token is not followed by an argument list,
        // treat it as a normal identifier.
        if (!$tok->next || $tok->next->str !== '(') {
            return false;
        }

        // Function-like macro application
        $macroToken = $tok;
        $args = self::readMacroArgs($tok, $tok, $m->params, $m->isVariadic);
        $rparen = $tok;

        // Tokens that consist a func-like macro invocation may have different
        // hidesets, and if that's the case, it's not clear what the hideset
        // for the new tokens should be. We take the intersection of the
        // macro token and the closing parenthesis and use it as a new hideset
        // as explained in the Dave Prossor's algorithm.
        $hs = self::hidesetIntersection($macroToken->hideset ?? null, $rparen->hideset ?? null);
        $hs = self::hidesetUnion($hs, self::newHideset($m->name));

        $body = self::subst($m->body, $args);
        $body = self::addHideset($body, $hs);
        
        // Set origin for all tokens in the body
        for ($t = $body; $t && $t->kind !== TokenKind::TK_EOF; $t = $t->next) {
            $t->origin = $macroToken;
        }
        
        $rest = self::append($body, $tok);
        $rest->atBol = $macroToken->atBol;
        $rest->hasSpace = $macroToken->hasSpace;
        return true;
    }

    // Read #line arguments
    private static function readLineMarker(Token &$rest, Token $tok): void
    {
        $start = $tok;
        $tok = self::copyLine($rest, $tok);

        if ($tok->kind !== TokenKind::TK_PP_NUM) {
            Console::errorTok($tok, "invalid line marker");
        }
        
        // Convert PP_NUM to NUM
        self::convertPpNumber($tok);
        
        if ($tok->kind !== TokenKind::TK_NUM || $tok->ty->kind !== \Pcc\Ast\TypeKind::TY_INT) {
            Console::errorTok($tok, "invalid line marker");
        }
        $start->file->lineDelta = $tok->val - $start->lineNo;

        $tok = $tok->next;
        if ($tok->kind === TokenKind::TK_EOF) {
            return;
        }

        if ($tok->kind !== TokenKind::TK_STR) {
            Console::errorTok($tok, "filename expected");
        }
        $start->file->displayName = rtrim($tok->str, "\0"); // remove null terminator
    }

    /**
     * すべてのトークンを訪問し、プリプロセッサのマクロとディレクティブを評価する
     */
    private static function preprocess2(Token $tok): Token
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        while ($tok->kind !== TokenKind::TK_EOF) {
            // If it is a macro, expand it.
            if (self::expandMacro($tok, $tok)) {
                continue;
            }
            
            // "#"でない場合はそのまま通す
            if (!self::isHash($tok)) {
                $tok->lineDelta = $tok->file->lineDelta;
                $tok->filename = $tok->file->displayName;
                $cur->next = $tok;
                $cur = $tok;
                $tok = $tok->next;
                continue;
            }

            $start = $tok;
            $tok = $tok->next;
            

            if ($tok->str === 'include') {
                $isDquote = false;
                $restTok = $tok; // Initialize with current token
                $filename = self::readIncludeFilename($restTok, $tok->next, $isDquote);

                if ($filename[0] !== '/' && $isDquote) {
                    $path = dirname($start->file->name) . '/' . $filename;
                    if (self::fileExists($path)) {
                        $tok = self::includeFile($restTok, $path, $start->next->next);
                        continue;
                    }
                }

                $path = self::searchIncludePaths($filename);
                $tok = self::includeFile($restTok, $path ? $path : $filename, $start->next->next);
                continue;
            }

            if ($tok->str === 'define') {
                self::readMacroDefinition($tok, $tok->next);
                continue;
            }

            if ($tok->str === 'undef') {
                $tok = $tok->next;
                if ($tok->kind !== TokenKind::TK_IDENT) {
                    Console::errorTok($tok, "macro name must be an identifier");
                }
                $name = $tok->str;
                $tok = self::skipLine($tok->next);

                self::undefMacro($name);
                continue;
            }

            if ($tok->str === 'if') {
                $val = self::evalConstExpr($tok, $tok);
                self::pushCondIncl($start, $val);
                if (!$val) {
                    $tok = self::skipCondIncl($tok);
                }
                continue;
            }

            if ($tok->str === 'ifdef') {
                $macroName = $tok->next->str ?? 'unknown';
                $defined = self::findMacro($tok->next) !== null;
                self::pushCondIncl($tok, $defined);
                $tok = self::skipLine($tok->next->next);
                if (!$defined) {
                    $tok = self::skipCondIncl($tok);
                }
                continue;
            }

            if ($tok->str === 'ifndef') {
                $defined = self::findMacro($tok->next) !== null;
                self::pushCondIncl($tok, !$defined);
                $tok = self::skipLine($tok->next->next);
                if ($defined) {
                    $tok = self::skipCondIncl($tok);
                }
                continue;
            }

            if ($tok->str === 'elif') {
                if (!self::$condIncl or self::$condIncl->ctx === CondIncl::IN_ELSE) {
                    Console::errorTok($start, "stray #elif");
                }
                self::$condIncl->ctx = CondIncl::IN_ELIF;

                if (!self::$condIncl->included && self::evalConstExpr($tok, $tok)) {
                    self::$condIncl->included = true;
                } else {
                    $tok = self::skipCondIncl($tok);
                }
                continue;
            }

            if ($tok->str === 'else') {
                if (!self::$condIncl or self::$condIncl->ctx === CondIncl::IN_ELSE) {
                    $stackEmpty = self::$condIncl === null ? "stack empty" : "already in else";
                    Console::errorTok($start, "stray #else ($stackEmpty)");
                }
                self::$condIncl->ctx = CondIncl::IN_ELSE;
                $tok = self::skipLine($tok->next);

                if (self::$condIncl->included) {
                    $tok = self::skipCondIncl($tok);
                }
                continue;
            }

            if ($tok->str === 'endif') {
                if (self::$condIncl === null) {
                    Console::errorTok($start, "stray #endif");
                }
                self::$condIncl = self::$condIncl->next;
                $tok = self::skipLine($tok->next);
                continue;
            }

            if ($tok->str === 'line') {
                self::readLineMarker($tok, $tok->next);
                continue;
            }

            if ($tok->kind === TokenKind::TK_PP_NUM) {
                self::readLineMarker($tok, $tok);
                continue;
            }

            if ($tok->str === 'error') {
                Console::errorTok($tok, "error");
            }

            // `#`のみの行は合法です。これはnull directiveと呼ばれます。
            if ($tok->atBol) {
                continue;
            }

            Console::errorTok($tok, "invalid preprocessor directive");
        }

        $cur->next = $tok;
        return $head->next;
    }

    private static function convertPpTokens(Token $tok): void
    {
        $keywords = [
            'return', 'if', 'else', 'for', 'while', 'int', 'sizeof', 'char',
            'struct', 'union', 'short', 'long', 'void', 'typedef', '_Bool',
            'enum', 'static', 'goto', 'break', 'continue', 'switch', 'case',
            'default', 'extern', '_Alignof', '_Alignas', 'do', 'signed',
            'unsigned', 'float', 'double',
        ];
        
        for ($t = $tok; $t; $t = $t->next) {
            if ($t->kind === TokenKind::TK_IDENT && in_array($t->str, $keywords)) {
                $t->kind = TokenKind::TK_KEYWORD;
            } elseif ($t->kind === TokenKind::TK_PP_NUM) {
                self::convertPpNumber($t);
            }
        }
    }

    private static function convertPpNumber(Token $tok): void
    {
        // Try to parse as an integer constant first
        if (self::convertPpInt($tok)) {
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

    private static function convertPpInt(Token $tok): bool
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

    public static function defineMacro(string $name, string $buf): void
    {
        $tokenizer = new Tokenizer('<built-in>', null, true);
        $tok = $tokenizer->tokenizeString($buf);
        self::addMacro($name, true, $tok);
    }

    public static function undefMacro(string $name): void
    {
        $m = self::addMacro($name, true, null);
        $m->deleted = true;
    }

    public static function initMacros(): void
    {
        // Define predefined macros
        self::defineMacro('_LP64', '1');
        self::defineMacro('__C99_MACRO_WITH_VA_ARGS', '1');
        self::defineMacro('__ELF__', '1');
        self::defineMacro('__LP64__', '1');
        self::defineMacro('__SIZEOF_DOUBLE__', '8');
        self::defineMacro('__SIZEOF_FLOAT__', '4');
        self::defineMacro('__SIZEOF_INT__', '4');
        self::defineMacro('__SIZEOF_LONG_DOUBLE__', '8');
        self::defineMacro('__SIZEOF_LONG_LONG__', '8');
        self::defineMacro('__SIZEOF_LONG__', '8');
        self::defineMacro('__SIZEOF_POINTER__', '8');
        self::defineMacro('__SIZEOF_PTRDIFF_T__', '8');
        self::defineMacro('__SIZEOF_SHORT__', '2');
        self::defineMacro('__SIZEOF_SIZE_T__', '8');
        self::defineMacro('__SIZE_TYPE__', 'unsigned long');
        self::defineMacro('__STDC_HOSTED__', '1');
        self::defineMacro('__STDC_NO_ATOMICS__', '1');
        self::defineMacro('__STDC_NO_COMPLEX__', '1');
        self::defineMacro('__STDC_NO_THREADS__', '1');
        self::defineMacro('__STDC_NO_VLA__', '1');
        self::defineMacro('__STDC_UTF_16__', '1');
        self::defineMacro('__STDC_UTF_32__', '1');
        self::defineMacro('__STDC_VERSION__', '201112L');
        self::defineMacro('__STDC__', '1');
        self::defineMacro('__USER_LABEL_PREFIX__', '');
        self::defineMacro('__alignof__', '_Alignof');
        self::defineMacro('__amd64', '1');
        self::defineMacro('__amd64__', '1');
        self::defineMacro('__chibicc__', '1');
        self::defineMacro('__const__', 'const');
        self::defineMacro('__gnu_linux__', '1');
        self::defineMacro('__inline__', 'inline');
        self::defineMacro('__linux', '1');
        self::defineMacro('__linux__', '1');
        self::defineMacro('__signed__', 'signed');
        self::defineMacro('__typeof__', 'typeof');
        self::defineMacro('__unix', '1');
        self::defineMacro('__unix__', '1');
        self::defineMacro('__volatile__', 'volatile');
        self::defineMacro('__x86_64', '1');
        self::defineMacro('__x86_64__', '1');
        self::defineMacro('linux', '1');
        self::defineMacro('unix', '1');
        
        self::addBuiltin('__FILE__', [self::class, 'fileMacro']);
        self::addBuiltin('__LINE__', [self::class, 'lineMacro']);
        self::addBuiltin('__COUNTER__', [self::class, 'counterMacro']);
        self::addBuiltin('__TIMESTAMP__', [self::class, 'timestampMacro']);
        
        // Add __DATE__ and __TIME__ macros
        $now = time();
        self::defineMacro('__DATE__', self::formatDate($now));
        self::defineMacro('__TIME__', self::formatTime($now));
    }

    // __DATE__ is expanded to the current date, e.g. "May 17 2020".
    private static function formatDate(int $timestamp): string
    {
        $monthNames = [
            'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
        ];
        
        $month = $monthNames[date('n', $timestamp) - 1];
        $day = date('j', $timestamp);
        $year = date('Y', $timestamp);
        
        return sprintf('"%s %2d %d"', $month, $day, $year);
    }

    // __TIME__ is expanded to the current time, e.g. "13:34:03".
    private static function formatTime(int $timestamp): string
    {
        return sprintf('"%02d:%02d:%02d"', date('H', $timestamp), date('i', $timestamp), date('s', $timestamp));
    }

    private static function addBuiltin(string $name, callable $fn): Macro
    {
        $m = self::addMacro($name, true, null);
        $m->handler = $fn;
        return $m;
    }

    private static function fileMacro(Token $tmpl): Token
    {
        while ($tmpl->origin) {
            $tmpl = $tmpl->origin;
        }
        return self::newStrToken($tmpl->file->displayName ?? '<unknown>', $tmpl);
    }

    private static function lineMacro(Token $tmpl): Token
    {
        // Traverse back to the original token through the origin chain
        while ($tmpl->origin) {
            $tmpl = $tmpl->origin;
        }
        $i = $tmpl->lineNo + $tmpl->file->lineDelta;
        return self::newNumToken($i, $tmpl);
    }

    // __COUNTER__ is expanded to serial values starting from 0.
    private static function counterMacro(Token $tmpl): Token
    {
        static $i = 0;
        return self::newNumToken($i++, $tmpl);
    }

    // __TIMESTAMP__ is expanded to a string describing the last
    // modification time of the current file. E.g.
    // "Fri Jul 24 01:32:50 2020"
    private static function timestampMacro(Token $tmpl): Token
    {
        while ($tmpl->origin) {
            $tmpl = $tmpl->origin;
        }
        
        $filename = $tmpl->file->name ?? '<unknown>';
        if (!file_exists($filename)) {
            return self::newStrToken("??? ??? ?? ??:??:?? ????", $tmpl);
        }
        
        $mtime = filemtime($filename);
        if ($mtime === false) {
            return self::newStrToken("??? ??? ?? ??:??:?? ????", $tmpl);
        }
        
        // Format like "Fri Jul 24 01:32:50 2020"
        $timestamp = date('D M j H:i:s Y', $mtime);
        return self::newStrToken($timestamp, $tmpl);
    }

    /**
     * String kind enumeration
     */
    private const STR_NONE = 0;
    private const STR_UTF8 = 1;
    private const STR_UTF16 = 2;
    private const STR_UTF32 = 3;
    private const STR_WIDE = 4;

    private static function getStringKind(Token $tok): int
    {
        $loc = $tok->originalStr ?? $tok->str;
        if (str_starts_with($loc, 'u8"')) {
            return self::STR_UTF8;
        }
        
        switch ($loc[0] ?? '') {
            case '"': return self::STR_NONE;
            case 'u': return self::STR_UTF16;
            case 'U': return self::STR_UTF32;
            case 'L': return self::STR_WIDE;
        }
        return self::STR_NONE;
    }

    /**
     * Concatenate adjacent string literals into a single string literal
     * as per the C spec.
     */
    private static function joinAdjacentStringLiterals(Token $tok): void
    {
        // First pass: If regular string literals are adjacent to wide
        // string literals, regular string literals are converted to a wide
        // type before concatenation. In this pass, we do the conversion.
        $tok1 = $tok;
        while ($tok1->kind !== TokenKind::TK_EOF) {
            if ($tok1->kind !== TokenKind::TK_STR || $tok1->next->kind !== TokenKind::TK_STR) {
                $tok1 = $tok1->next;
                continue;
            }

            $kind = self::getStringKind($tok1);
            $basety = $tok1->ty->base;

            for ($t = $tok1->next; $t->kind === TokenKind::TK_STR; $t = $t->next) {
                $k = self::getStringKind($t);
                if ($kind === self::STR_NONE) {
                    $kind = $k;
                    $basety = $t->ty->base;
                } elseif ($k !== self::STR_NONE && $kind !== $k) {
                    Console::errorTok($t, "unsupported non-standard concatenation of string literals");
                }
            }

            if ($basety->size > 1) {
                for ($t = $tok1; $t->kind === TokenKind::TK_STR; $t = $t->next) {
                    if ($t->ty->base->size === 1) {
                        $newTok = \Pcc\Tokenizer\Tokenizer::tokenizeStringLiteral($t, $basety);
                        $t->ty = $newTok->ty;
                        $t->str = $newTok->str;
                    }
                }
            }

            while ($tok1->kind === TokenKind::TK_STR) {
                $tok1 = $tok1->next;
            }
        }

        // Second pass: concatenate adjacent string literals.
        $tok1 = $tok;
        while ($tok1->kind !== TokenKind::TK_EOF) {
            if ($tok1->kind !== TokenKind::TK_STR || $tok1->next->kind !== TokenKind::TK_STR) {
                $tok1 = $tok1->next;
                continue;
            }

            $tok2 = $tok1->next;
            while ($tok2->kind === TokenKind::TK_STR) {
                $tok2 = $tok2->next;
            }

            $len = $tok1->ty->arrayLen;
            for ($t = $tok1->next; $t !== $tok2; $t = $t->next) {
                $len = $len + $t->ty->arrayLen - 1;
            }

            $buf = str_repeat("\0", $tok1->ty->base->size * $len);

            $i = 0;
            for ($t = $tok1; $t !== $tok2; $t = $t->next) {
                $copyLen = $t->ty->size;
                for ($j = 0; $j < $copyLen; $j++) {
                    $buf[$i + $j] = $t->str[$j] ?? "\0";
                }
                $i = $i + $copyLen - $t->ty->base->size;
            }

            $tok1->ty = Type::arrayOf($tok1->ty->base, $len);
            $tok1->str = $buf;
            $tok1->next = $tok2;
            $tok1 = $tok2;
        }
    }

    /**
     * プリプロセッサのエントリーポイント関数
     */
    public static function preprocess(Token $tok): Token
    {
        $tok = self::preprocess2($tok);
        if (self::$condIncl !== null) {
            Console::errorTok(self::$condIncl->tok, "unterminated conditional directive");
        }
        // キーワードを変換
        self::convertPpTokens($tok);
        self::joinAdjacentStringLiterals($tok);

        // Update line numbers with line delta
        for ($t = $tok; $t !== null; $t = $t->next) {
            $t->lineNo += $t->lineDelta;
        }

        return $tok;
    }
}