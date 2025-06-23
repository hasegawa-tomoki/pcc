<?php
namespace Pcc;

use Pcc\CodeGenerator\CodeGenerator;
use Pcc\Tokenizer\Tokenizer;

class Pcc
{
    //private static bool $optS = false;
    //private static bool $optCc1 = false;
    //private static bool $optHashHashHash = false;
    private static array $options = [];
    private static StringArray $tmpFiles;

    public static function displayHelp(): void
    {
        echo "Usage: pcc [ -o <output> ] <file>\n";
    }

    private static function parseArgs(int $argc, array $argv): array
    {
        $args = [];

        for ($i = 1; $i < $argc; $i++) {
            if ($argv[$i] === '-###') {
                self::$options['###'] = true;
                continue;
            }

            if ($argv[$i] === '-cc1') {
                self::$options['cc1'] = true;
                continue;
            }

            if ($argv[$i] === '--help') {
                self::$options['help'] = true;
                continue;
            }

            if ($argv[$i] === '-o' and isset($argv[$i + 1])) {
                self::$options['o'] = $argv[$i + 1];
                $i++;
                continue;
            }

            if (str_starts_with($argv[$i], '-o')){
                self::$options['o'] = substr($argv[$i], 2);
                continue;
            }

            if ($argv[$i] === '-S') {
                self::$options['S'] = true;
                continue;
            }

            $args[] = $argv[$i];
        }

        return $args;
    }

    private static function runSubprocess(array $argv): void
    {
        if (self::$options['###'] ?? false){
            fwrite(STDERR, $argv[0]);
            for ($i = 1; $i < count($argv); $i++) {
                fwrite(STDERR, ' ' . $argv[$i]);
            }
            fwrite(STDERR, "\n");
        }

        $command = implode(' ', array_map('escapeshellarg', $argv));
        $returnVar = 0;
        system($command, $returnVar);

        if ($returnVar != 0) {
            exit(1);
        }
    }

    private static function runCc1(int $argc, array $argv, ?string $inputPath = null, ?string $outputPath = null): void
    {
        $args = $argv;
        $args[] = '-cc1';

        if (! is_null($inputPath)){
            $args[] = $inputPath;
        }

        if (! is_null($outputPath)){
            $args[] = '-o';
            $args[] = $outputPath;
        }

        array_unshift($args, 'php');
        self::runSubprocess($args);
    }

    private static function cc1(string $inputPath, string $outputPath): int
    {
        $fpOut = fopen($outputPath, 'w');
        fprintf($fpOut, ".file 1 \"%s\"\n", $inputPath);
        Console::$outputFile = $fpOut;

        $tokenizer = new Tokenizer($inputPath);
        $tokenizer->tokenize();
        $parser = new Ast\Parser($tokenizer);
        $prog = $parser->parse();

        $codeGenerator = new CodeGenerator();
        $codeGenerator->gen($prog);

        return 0;
    }

    private static function replaceExt(string $tmpl, string $ext): string
    {
        $filename = basename($tmpl);
        $dot = strrpos($filename, '.');
        if ($dot !== false) {
            $filename = substr($filename, 0, $dot);
        }
        return $filename . $ext;
    }

    private static function createTmpfile(): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'pcc-');
        if ($tmpPath === false) {
            Console::error('Failed to create temporary file');
        }
        self::$tmpFiles->push($tmpPath);
        return $tmpPath;
    }

    private static function cleanup(): void
    {
        foreach (self::$tmpFiles->getData() as $file) {
            @unlink($file);
        }
    }

    private static function assemble(string $input, string $output): void
    {
        $cmd = ['as', '-c', $input, '-o', $output];
        self::runSubprocess($cmd);
    }

    public static function main(?int $argc = null, ?array $argv = null): int
    {
        self::$tmpFiles = new StringArray();
        register_shutdown_function([self::class, 'cleanup']);

        $args = self::parseArgs($argc, $argv);

        if (self::$options['help'] ?? false) {
            self::displayHelp();

            return 0;
        }

        if (! isset($args[0])){
            self::displayHelp();
            return 1;
        }
        $inputPath = $args[0];

        if (isset(self::$options['o'])) {
            $outputPath = self::$options['o'];
        } else if (self::$options['S']?? false) {
            $outputPath = self::replaceExt($inputPath, '.s');
        } else {
            $outputPath = self::replaceExt($inputPath, '.o');
        }

        if (self::$options['cc1']?? false){
            return self::cc1($inputPath, $outputPath);
        }

        // if -S is given, the assembly text is the final output
        if (self::$options['S']?? false){
            self::runCc1($argc, $argv, $inputPath, $outputPath);
            return 0;
        }

        // Otherwise, run the assembler to assemble our output.
        $tmpPath = self::createTmpfile();
        self::runCc1($argc, $argv, $inputPath, $tmpPath);
        self::assemble($tmpPath, $outputPath);
        unlink($tmpPath);
        return 0;
    }
}