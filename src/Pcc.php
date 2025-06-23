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
    private static StringArray $inputPaths;

    public static function displayHelp(): void
    {
        echo "Usage: pcc [ -o <output> ] <file>\n";
    }
    
    private static function takeArg(string $arg): bool
    {
        return $arg === '-o';
    }

    private static function parseArgs(int $argc, array $argv): void
    {
        // Make sure that all command line options that take an argument
        // have an argument.
        for ($i = 1; $i < $argc; $i++) {
            if (self::takeArg($argv[$i])) {
                if (!isset($argv[$i + 1])) {
                    self::displayHelp();
                    exit(1);
                }
                $i++;
            }
        }

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
            
            if ($argv[$i] === '-cc1-input' and isset($argv[$i + 1])) {
                self::$options['base_file'] = $argv[$i + 1];
                $i++;
                continue;
            }
            
            if ($argv[$i] === '-cc1-output' and isset($argv[$i + 1])) {
                self::$options['output_file'] = $argv[$i + 1];
                $i++;
                continue;
            }
            
            if (str_starts_with($argv[$i], '-') and $argv[$i] !== '-') {
                Console::error("unknown argument: {$argv[$i]}");
            }

            self::$inputPaths->push($argv[$i]);
        }
        
        if (self::$inputPaths->getLength() === 0) {
            Console::error('no input files');
        }
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
            $args[] = '-cc1-input';
            $args[] = $inputPath;
        }

        if (! is_null($outputPath)){
            $args[] = '-cc1-output';
            $args[] = $outputPath;
        }

        array_unshift($args, 'php');
        self::runSubprocess($args);
    }

    private static function cc1(): int
    {
        $baseFile = self::$options['base_file'] ?? '';
        $outputFile = self::$options['output_file'] ?? '';
        
        $fpOut = fopen($outputFile, 'w');
        fprintf($fpOut, ".file 1 \"%s\"\n", $baseFile);
        Console::$outputFile = $fpOut;

        $tokenizer = new Tokenizer($baseFile);
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
        self::$inputPaths = new StringArray();
        register_shutdown_function([self::class, 'cleanup']);

        self::parseArgs($argc, $argv);

        if (self::$options['help'] ?? false) {
            self::displayHelp();
            return 0;
        }

        if (self::$options['cc1'] ?? false){
            return self::cc1();
        }
        
        if (self::$inputPaths->getLength() > 1 and isset(self::$options['o'])) {
            Console::error("cannot specify '-o' with multiple files");
        }
        
        $inputFiles = self::$inputPaths->getData();
        foreach ($inputFiles as $inputPath) {
            if (isset(self::$options['o'])) {
                $outputPath = self::$options['o'];
            } else if (self::$options['S'] ?? false) {
                $outputPath = self::replaceExt($inputPath, '.s');
            } else {
                $outputPath = self::replaceExt($inputPath, '.o');
            }

            // if -S is given, the assembly text is the final output
            if (self::$options['S'] ?? false){
                self::runCc1($argc, $argv, $inputPath, $outputPath);
                continue;
            }

            // Otherwise, run the assembler to assemble our output.
            $tmpPath = self::createTmpfile();
            self::runCc1($argc, $argv, $inputPath, $tmpPath);
            self::assemble($tmpPath, $outputPath);
        }
        
        return 0;
    }
}