<?php
namespace Pcc;

use Pcc\Ast\Node;
use Pcc\Ast\NodeKind;
use Pcc\CodeGenerator\CodeGenerator;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Tokenizer\TokenKind;

class Pcc
{
    public static function main(?int $argc = null, ?array $argv = null): int
    {
        if ($argc != 2) {
            printf("引数の個数が正しくありません\n");
            return 1;
        }

        $tokenizer = new Tokenizer($argv[1]);
        $tokenizer->tokenize();
        $parser = new Ast\Parser($tokenizer);
        $nodes = $parser->parse();

        if (! $tokenizer->isTokenKind(TokenKind::TK_EOF)){
            Console::error("extra token");
        }


        $codeGenerator = new CodeGenerator();
        $codeGenerator->gen($nodes);

        return 0;
    }
}