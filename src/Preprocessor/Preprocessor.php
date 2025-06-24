<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\TokenKind;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Console;
use Pcc\Ast\Parser;

// `#if` can be nested, so we use a stack to manage nested `#if`s.
class CondIncl
{
    public ?CondIncl $next;
    public Token $tok;

    public function __construct(?CondIncl $next, Token $tok)
    {
        $this->next = $next;
        $this->tok = $tok;
    }
}

class Preprocessor
{
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
        if (isset($tok->file)) {
            $t->file = $tok->file;
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
    
    /**
     * Append tok2 to the end of tok1
     */
    private static function append(?Token $tok1, Token $tok2): Token
    {
        if (!$tok1 || $tok1->kind === TokenKind::TK_EOF) {
            return $tok2;
        }
        
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;
        
        for (; $tok1 && $tok1->kind !== TokenKind::TK_EOF; $tok1 = $tok1->next) {
            $cur->next = self::copyToken($tok1);
            $cur = $cur->next;
        }
        $cur->next = $tok2;
        return $head->next;
    }

    // Skip until next `#endif`.
    // Nested `#if` and `#endif` are skipped.
    private static function skipCondIncl(Token $tok): Token
    {
        while ($tok->kind !== TokenKind::TK_EOF) {
            if (self::isHash($tok) && $tok->next->str === 'if') {
                $tok = self::skipCondIncl($tok->next->next);
                $tok = $tok->next;
                continue;
            }
            if (self::isHash($tok) && $tok->next->str === 'endif') {
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

    private static function pushCondIncl(Token $tok): CondIncl
    {
        $ci = new CondIncl(self::$condIncl, $tok);
        self::$condIncl = $ci;
        return $ci;
    }

    /**
     * すべてのトークンを訪問し、プリプロセッサのマクロとディレクティブを評価する
     */
    private static function preprocess2(Token $tok): Token
    {
        $head = new Token(TokenKind::TK_EOF, '', 0);
        $cur = $head;

        while ($tok->kind !== TokenKind::TK_EOF) {
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

            if ($tok->str === 'if') {
                $val = self::evalConstExpr($tok, $tok);
                self::pushCondIncl($start);
                if (!$val) {
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