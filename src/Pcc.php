<?php
namespace Pcc;

use Pcc\CodeGenerator\CodeGenerator;
use Pcc\Tokenizer\Tokenizer;

class Pcc
{
    public static function displayHelp(): void
    {
        echo "Usage: pcc [ -o <output> ] <file>\n";
    }

    public static function main(?int $argc = null, ?array $argv = null): int
    {
        $options = getopt('o:', ['help', ], $rest);
        $args = array_slice($argv, $rest);

        if (isset($options['help'])) {
            self::displayHelp();
            return 0;
        }
        if (isset($options['o'])){
            $fpOut = fopen($options['o'], 'w');
        } else {
            $fpOut = fopen('php://output', 'w');
        }
        Console::$outputFile = $fpOut;

        if (! isset($args[0])){
            self::displayHelp();
            return 1;
        }
        $inputPath = $args[0];
        fprintf($fpOut, ".file 1 \"%s\"\n", $inputPath);

        $tokenizer = new Tokenizer($inputPath);
        $tokenizer->tokenize();
        $parser = new Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $codeGenerator = new CodeGenerator();
        $codeGenerator->gen($prog);

        return 0;
    }
}