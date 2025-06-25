<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\TokenKind;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Console;
use Pcc\Ast\Parser;

class Macro
{
    public ?Macro $next;
    public string $name;
    public bool $isObjlike;
    public ?MacroParam $params = null;
    public Token $body;
    public bool $deleted;

    public function __construct(?Macro $next, string $name, bool $isObjlike, Token $body)
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

    // Copy all tokens until the next newline, terminate them with
    // an EOF token and then returns them. This function is used to
    // create a new list of tokens for `#if` arguments.
    private static function copyLine(Token &$rest, Token $tok): Token
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        while (!$tok->atBol && $tok->kind !== TokenKind::TK_EOF) {
            $cur->next = self::copyToken($tok);
            $cur = $cur->next;
            $tok = $tok->next;
        }

        $cur->next = self::newEof($tok);
        $rest = $tok;
        return $head->next;
    }

    // Read and evaluate a constant expression.
    private static function evalConstExpr(Token &$rest, Token $tok): int
    {
        $start = $tok;
        $expr = self::copyLine($rest, $tok->next);
        $expr = self::preprocess2($expr);

        if ($expr->kind === TokenKind::TK_EOF) {
            Console::errorTok($start, "no expression");
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

    private static function addMacro(string $name, bool $isObjlike, Token $body): Macro
    {
        $m = new Macro(self::$macros, $name, $isObjlike, $body);
        self::$macros = $m;
        return $m;
    }

    private static function readMacroParams(Token &$rest, Token $tok): ?MacroParam
    {
        $head = new MacroParam('');
        $cur = $head;

        while ($tok->str !== ')') {
            if ($cur !== $head) {
                if ($tok->str !== ',') {
                    Console::errorTok($tok, "expected ','");
                }
                $tok = $tok->next;
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
            $params = self::readMacroParams($tok, $tok->next);
            $m = self::addMacro($name, false, self::copyLine($rest, $tok));
            $m->params = $params;
        } else {
            // Object-like macro
            self::addMacro($name, true, self::copyLine($rest, $tok));
        }
    }

    private static function readMacroArgOne(Token &$rest, Token $tok): MacroArg
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;
        $level = 0;

        while ($level > 0 || ($tok->str !== ',' && $tok->str !== ')')) {
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

    private static function readMacroArgs(Token &$rest, Token $tok, ?MacroParam $params): ?MacroArg
    {
        $start = $tok;
        $tok = $tok->next->next; // skip identifier and '('

        $head = new MacroArg('', new Token(TokenKind::TK_EOF, '', 0));
        $cur = $head;

        $pp = $params;
        while ($pp) {
            if ($cur !== $head) {
                if ($tok->str !== ',') {
                    Console::errorTok($tok, "expected ','");
                }
                $tok = $tok->next;
            }
            $cur->next = self::readMacroArgOne($tok, $tok);
            $cur = $cur->next;
            $cur->name = $pp->name;
            $pp = $pp->next;
        }

        if ($tok->str !== ')') {
            Console::errorTok($start, "too many arguments");
        }
        $rest = $tok->next;
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
            $arg = self::findArg($args, $tok);

            // Handle a macro token. Macro arguments are completely macro-expanded
            // before they are substituted into a macro body.
            if ($arg) {
                $t = self::preprocess2($arg->tok);
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
        if (self::hidesetContains($tok->hideset, $tok->str, strlen($tok->str))) {
            return false;
        }

        $m = self::findMacro($tok);
        if (!$m) {
            return false;
        }

        // Object-like macro application
        if ($m->isObjlike) {
            $hs = self::hidesetUnion($tok->hideset, self::newHideset($m->name));
            $body = self::addHideset($m->body, $hs);
            $rest = self::append($body, $tok->next);
            return true;
        }

        // If a funclike macro token is not followed by an argument list,
        // treat it as a normal identifier.
        if (!$tok->next || $tok->next->str !== '(') {
            return false;
        }

        // Function-like macro application
        $args = self::readMacroArgs($tok, $tok, $m->params);
        $rest = self::append(self::subst($m->body, $args), $tok);
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

            // Handle #include directive
            if ($tok->str === 'include') {
                $tok = $tok->next;
                
                if ($tok->kind !== TokenKind::TK_STR) {
                    Console::errorTok($tok, "expected a filename");
                }
                
                // Extract filename from string literal (remove null terminator)
                $filename = rtrim($tok->str, "\0");
                
                if ($filename[0] === '/') {
                    $path = $filename;
                } else {
                    $dir = dirname($tok->file->name);
                    $path = $dir . '/' . $filename;
                }
                
                $tokenizer = new Tokenizer($path);
                try {
                    $tokenizer->tokenize();
                    $tok2 = $tokenizer->tok;
                } catch (\Exception $e) {
                    Console::errorTok($tok, "%s", $e->getMessage());
                }
                
                $tok = self::skipLine($tok->next);
                $tok = self::append($tok2, $tok);
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
                    Console::errorTok($start, "stray #else");
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

            // `#`のみの行は合法です。これはnull directiveと呼ばれます。
            if ($tok->atBol) {
                continue;
            }

            Console::errorTok($tok, "invalid preprocessor directive");
        }

        $cur->next = $tok;
        return $head->next;
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
        $tok->convertKeywords();
        return $tok;
    }
}