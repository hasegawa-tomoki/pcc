<?php
namespace Pcc;

use Pcc\CodeGenerator\CodeGenerator;
use Pcc\Tokenizer\Tokenizer;

class Pcc
{
    private static bool $optCc1 = false;
    private static bool $optHashHashHash = false;

    public static function displayHelp(): void
    {
        echo "Usage: pcc [ -o <output> ] <file>\n";
    }

    private static function parseArgs(int $argc, array $argv): array
    {
        $options = [];
        $args = [];
        
        for ($i = 1; $i < $argc; $i++) {
            if ($argv[$i] === '-###') {
                self::$optHashHashHash = true;
                continue;
            }
            
            if ($argv[$i] === '-cc1') {
                self::$optCc1 = true;
                continue;
            }
            
            if ($argv[$i] === '--help') {
                $options['help'] = true;
                continue;
            }
            
            if ($argv[$i] === '-o' and isset($argv[$i + 1])) {
                $options['o'] = $argv[$i + 1];
                $i++;
                continue;
            }
            
            $args[] = $argv[$i];
        }
        
        return [$options, $args];
    }

    private static function runSubprocess(array $argv): void
    {
        if (self::$optHashHashHash) {
            fwrite(STDERR, $argv[0]);
            for ($i = 1; $i < count($argv); $i++) {
                fwrite(STDERR, ' ' . $argv[$i]);
            }
            fwrite(STDERR, "\n");
        }

        array_unshift($argv, 'php');
        $command = implode(' ', array_map('escapeshellarg', $argv));
        $returnVar = 0;
        system($command, $returnVar);
        if ($returnVar != 0) {
            exit(1);
        }
    }

    private static function runCc1(int $argc, array $argv): void
    {
        $args = $argv;
        $args[] = '-cc1';
        self::runSubprocess($args);
    }

    private static function cc1(string $inputPath, $fpOut): int
    {
        fprintf($fpOut, ".file 1 \"%s\"\n", $inputPath);

        $tokenizer = new Tokenizer($inputPath);
        $tokenizer->tokenize();
        $parser = new Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $codeGenerator = new CodeGenerator();
        $codeGenerator->gen($prog);

        return 0;
    }

    public static function main(?int $argc = null, ?array $argv = null): int
    {
        [$options, $args] = self::parseArgs($argc, $argv);

        if (isset($options['help'])) {
            self::displayHelp();
            return 0;
        }
        
        if (isset($options['o'])) {
            $fpOut = fopen($options['o'], 'w');
        } else {
            $fpOut = fopen('php://output', 'w');
        }
        Console::$outputFile = $fpOut;

        if (!isset($args[0])) {
            self::displayHelp();
            return 1;
        }
        $inputPath = $args[0];
        
        if (self::$optCc1) {
            return self::cc1($inputPath, $fpOut);
        }
        
        self::runCc1($argc, $argv);
        return 0;
    }
}