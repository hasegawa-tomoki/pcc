<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\TokenKind;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Console;

class Preprocessor
{
    private static function isHash(Token $tok): bool
    {
        return $tok->atBol && $tok->str === '#';
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

            $tok = $tok->next;

            // Handle #include directive
            if ($tok->str === 'include') {
                $tok = $tok->next;
                
                if ($tok->kind !== TokenKind::TK_STR) {
                    Console::errorTok($tok, "expected a filename");
                }
                
                // Extract filename from string literal (remove null terminator)
                $filename = rtrim($tok->str, "\0");
                $dir = dirname($tok->file->name);
                $path = $dir . '/' . $filename;
                
                $tokenizer = new Tokenizer($path);
                try {
                    $tokenizer->tokenize();
                    $tok2 = $tokenizer->tok;
                } catch (\Exception $e) {
                    Console::errorTok($tok, "%s", $e->getMessage());
                }
                
                $tok = self::append($tok2, $tok->next);
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
        // キーワードを変換
        $tok->convertKeywords();
        return $tok;
    }
}