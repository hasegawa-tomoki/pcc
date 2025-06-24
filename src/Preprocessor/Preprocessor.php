<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\TokenKind;
use Pcc\Console;

class Preprocessor
{
    private static function isHash(Token $tok): bool
    {
        return $tok->atBol && $tok->str === '#';
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