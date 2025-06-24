<?php
namespace Pcc\Preprocessor;

use Pcc\Tokenizer\Token;

class Preprocessor
{
    /**
     * プリプロセッサのエントリーポイント関数
     */
    public static function preprocess(Token $tok): Token
    {
        // キーワードを変換
        $tok->convertKeywords();
        return $tok;
    }
}