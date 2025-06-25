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
                // For string literals, we need to reconstruct the original quoted form
                $content = $t->str;
                // Remove null terminator
                if (strlen($content) > 0 && $content[-1] === "\0") {
                    $content = substr($content, 0, -1);
                }
                // Escape quotes and backslashes, then add quotes
                $content = str_replace(['\\', '"'], ['\\\\', '\\"'], $content);
                $buf .= '"' . $content . '"';
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
        return Tokenizer::tokenizeFile($file);
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

                $m = self::addMacro($name, true, new Token(TokenKind::TK_EOF, '', 0));
                $m->deleted = true;
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

    private static function convertKeywords(Token $tok): void
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
            }
        }
    }

    private static function defineMacro(string $name, string $buf): void
    {
        $tokenizer = new Tokenizer('<built-in>', null, true);
        $tok = $tokenizer->tokenizeString($buf);
        self::addMacro($name, true, $tok);
    }

    private static function initMacros(): void
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
        return self::newStrToken($tmpl->file->name ?? '<unknown>', $tmpl);
    }

    private static function lineMacro(Token $tmpl): Token
    {
        // Traverse back to the original token through the origin chain
        while ($tmpl->origin) {
            $tmpl = $tmpl->origin;
        }
        return self::newNumToken($tmpl->lineNo ?? 1, $tmpl);
    }

    /**
     * プリプロセッサのエントリーポイント関数
     */
    public static function preprocess(Token $tok): Token
    {
        self::initMacros();
        $tok = self::preprocess2($tok);
        if (self::$condIncl !== null) {
            Console::errorTok(self::$condIncl->tok, "unterminated conditional directive");
        }
        // キーワードを変換
        self::convertKeywords($tok);
        return $tok;
    }
}