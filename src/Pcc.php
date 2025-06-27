<?php
namespace Pcc;

use Pcc\CodeGenerator\CodeGenerator;
use Pcc\Tokenizer\Tokenizer;
use Pcc\Tokenizer\Token;
use Pcc\Tokenizer\TokenKind;
use Pcc\Preprocessor\Preprocessor;

enum FileType {
    case FILE_NONE;
    case FILE_C;
    case FILE_ASM;
    case FILE_OBJ;
}

class Pcc
{
    //private static bool $optS = false;
    //private static bool $optCc1 = false;
    //private static bool $optHashHashHash = false;
    private static array $options = [];
    private static bool $optFcommon = true;
    private static FileType $optX = FileType::FILE_NONE;
    private static StringArray $optInclude;
    private static StringArray $tmpFiles;
    private static StringArray $inputPaths;
    private static StringArray $includePaths;
    private static StringArray $ldExtraArgs;

    public static function getIncludePaths(): StringArray
    {
        return self::$includePaths;
    }

    public static function getOptFcommon(): bool
    {
        return self::$optFcommon;
    }

    public static function displayHelp(): void
    {
        echo "Usage: pcc [ -o <output> ] <file>\n";
    }
    
    private static function takeArg(string $arg): bool
    {
        $x = ['-o', '-I', '-D', '-U', '-idirafter', '-include', '-x'];
        
        foreach ($x as $option) {
            if ($arg === $option) {
                return true;
            }
        }
        return false;
    }

    private static function define(string $str): void
    {
        $eq = strpos($str, '=');
        if ($eq !== false) {
            Preprocessor::defineMacro(substr($str, 0, $eq), substr($str, $eq + 1));
        } else {
            Preprocessor::defineMacro($str, '1');
        }
    }

    private static function addDefaultIncludePaths(string $argv0): void
    {
        // We expect that chibicc-specific include files are installed
        // to ./include relative to argv[0].
        self::$includePaths->push(dirname($argv0) . '/include');

        // Add standard include paths.
        self::$includePaths->push('/usr/local/include');
        self::$includePaths->push('/usr/include/x86_64-linux-gnu');
        self::$includePaths->push('/usr/include');
    }
    
    private static function endswith(string $p, string $q): bool
    {
        $len1 = strlen($p);
        $len2 = strlen($q);
        return ($len1 >= $len2) && (substr($p, $len1 - $len2) === $q);
    }

    private static function parseOptX(string $s): FileType
    {
        if ($s === 'c') {
            return FileType::FILE_C;
        }
        if ($s === 'assembler') {
            return FileType::FILE_ASM;
        }
        if ($s === 'none') {
            return FileType::FILE_NONE;
        }
        Console::error('<command line>: unknown argument for -x: %s', $s);
    }

    private static function parseArgs(int $argc, array $argv): void
    {
        $idirafter = new StringArray();
        
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
                self::displayHelp();
                exit(0);
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

            if (str_starts_with($argv[$i], '-I')){
                self::$includePaths->push(substr($argv[$i], 2));
                continue;
            }

            if ($argv[$i] === '-D' and isset($argv[$i + 1])) {
                self::define($argv[$i + 1]);
                $i++;
                continue;
            }

            if (str_starts_with($argv[$i], '-D')){
                self::define(substr($argv[$i], 2));
                continue;
            }

            if ($argv[$i] === '-U' and isset($argv[$i + 1])) {
                Preprocessor::undefMacro($argv[$i + 1]);
                $i++;
                continue;
            }

            if (str_starts_with($argv[$i], '-U')){
                Preprocessor::undefMacro(substr($argv[$i], 2));
                continue;
            }

            if ($argv[$i] === '-idirafter' and isset($argv[$i + 1])) {
                $idirafter->push($argv[$i + 1]);
                $i++;
                continue;
            }

            if ($argv[$i] === '-include' and isset($argv[$i + 1])) {
                self::$optInclude->push($argv[$i + 1]);
                $i++;
                continue;
            }

            if ($argv[$i] === '-x' and isset($argv[$i + 1])) {
                self::$optX = self::parseOptX($argv[$i + 1]);
                $i++;
                continue;
            }

            if (str_starts_with($argv[$i], '-x')) {
                self::$optX = self::parseOptX(substr($argv[$i], 2));
                continue;
            }

            if ($argv[$i] === '-S') {
                self::$options['S'] = true;
                continue;
            }
            
            if ($argv[$i] === '-E') {
                self::$options['E'] = true;
                continue;
            }
            
            if ($argv[$i] === '-c') {
                self::$options['c'] = true;
                continue;
            }

            if ($argv[$i] === '-fcommon') {
                self::$optFcommon = true;
                continue;
            }

            if ($argv[$i] === '-fno-common') {
                self::$optFcommon = false;
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
            
            // These options are ignored for now.
            if (str_starts_with($argv[$i], '-O') ||
                str_starts_with($argv[$i], '-W') ||
                str_starts_with($argv[$i], '-g') ||
                str_starts_with($argv[$i], '-std=') ||
                $argv[$i] === '-ffreestanding' ||
                $argv[$i] === '-fno-builtin' ||
                $argv[$i] === '-fno-omit-frame-pointer' ||
                $argv[$i] === '-fno-stack-protector' ||
                $argv[$i] === '-fno-strict-aliasing' ||
                $argv[$i] === '-m64' ||
                $argv[$i] === '-mno-red-zone' ||
                $argv[$i] === '-w') {
                continue;
            }
            
            if (str_starts_with($argv[$i], '-l')) {
                self::$inputPaths->push($argv[$i]);
                continue;
            }

            if ($argv[$i] === '-s') {
                self::$ldExtraArgs->push('-s');
                continue;
            }

            if (str_starts_with($argv[$i], '-') and $argv[$i] !== '-') {
                Console::error("unknown argument: {$argv[$i]}");
            }

            self::$inputPaths->push($argv[$i]);
        }
        
        // Add -idirafter paths after regular include paths
        foreach ($idirafter->getData() as $path) {
            self::$includePaths->push($path);
        }
        
        if (self::$inputPaths->getLength() === 0) {
            Console::error('no input files');
        }

        // -E implies that the input is the C macro language.
        if (self::$options['E'] ?? false) {
            self::$optX = FileType::FILE_C;
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

    /**
     * Print tokens to stdout. Used for -E.
     */
    private static function printTokens(\Pcc\Tokenizer\Token $tok): void
    {
        $output = self::$options['o'] ?? '-';
        
        if ($output === '-' || empty($output)) {
            $fpOut = fopen('php://output', 'w');
        } else {
            $fpOut = fopen($output, 'w');
        }
        
        $line = 1;
        while ($tok->kind !== \Pcc\Tokenizer\TokenKind::TK_EOF) {
            if ($line > 1 && $tok->atBol) {
                fprintf($fpOut, "\n");
            }
            if ($tok->kind === \Pcc\Tokenizer\TokenKind::TK_STR) {
                // String literal
                $str = $tok->str;
                // Remove null terminator for display
                if (strlen($str) > 0 && $str[-1] === "\0") {
                    $str = substr($str, 0, -1);
                }
                // Escape quotes and backslashes
                $str = str_replace(['\\', '"'], ['\\\\', '\\"'], $str);
                fprintf($fpOut, " \"%s\"", $str);
            } else {
                fprintf($fpOut, " %s", $tok->str);
            }
            $tok = $tok->next;
            $line++;
        }
        fprintf($fpOut, "\n");
        
        if ($output !== '-' && !empty($output)) {
            fclose($fpOut);
        }
    }

    private static function mustTokenizeFile(string $path): ?Token
    {
        $tokenizer = new Tokenizer($path);
        $tokenizer->tokenize();
        if ($tokenizer->tok === null) {
            Console::error('%s: file not found or cannot be read', $path);
        }
        return $tokenizer->tok;
    }

    private static function appendTokens(?Token $tok1, ?Token $tok2): ?Token
    {
        if ($tok1 === null || $tok1->kind === TokenKind::TK_EOF) {
            return $tok2;
        }

        $t = $tok1;
        while ($t->next->kind !== TokenKind::TK_EOF) {
            $t = $t->next;
        }
        $t->next = $tok2;
        return $tok1;
    }

    private static function cc1(): int
    {
        $baseFile = self::$options['base_file'] ?? '';
        $outputFile = self::$options['output_file'] ?? '';

        $tok = null;

        // Process -include option
        for ($i = 0; $i < self::$optInclude->getLength(); $i++) {
            $incl = self::$optInclude->getData()[$i];

            $path = null;
            if (Preprocessor::fileExists($incl)) {
                $path = $incl;
            } else {
                $path = Preprocessor::searchIncludePaths($incl);
                if ($path === null) {
                    Console::error('-include: %s: file not found', $incl);
                }
            }

            $tok2 = self::mustTokenizeFile($path);
            $tok = self::appendTokens($tok, $tok2);
        }

        // Tokenize and parse.
        $tok2 = self::mustTokenizeFile($baseFile);
        $tok = self::appendTokens($tok, $tok2);
        
        Preprocessor::setBaseFile($baseFile);
        $tok = Preprocessor::preprocess($tok);

        // If -E is given, print out preprocessed C code as a result.
        if (self::$options['E'] ?? false) {
            if ($outputFile === '-' || $outputFile === ''){
                $fpOut = fopen('php://output', 'w');
            } else {
                $fpOut = fopen($outputFile, 'w');
            }
            Console::$outputFile = $fpOut;
            self::printTokens($tok);
            return 0;
        }

        // Create a new tokenizer for parsing
        $tokenizer = new Tokenizer($baseFile);
        // Update tokenizer with preprocessed tokens
        $tokenizer->updateTokensArray($tok);
        
        $parser = new Ast\Parser($tokenizer);
        $prog = $parser->parse();

        // Open a temporary output buffer.
        $outputBuffer = fopen('php://memory', 'w+');
        Console::$outputFile = $outputBuffer;

        // Traverse the AST to emit assembly.
        $codeGenerator = new CodeGenerator();
        $codeGenerator->gen($prog);

        // Get the assembly text from buffer
        rewind($outputBuffer);
        $assemblyOutput = stream_get_contents($outputBuffer);
        fclose($outputBuffer);

        // Write the assembly text to a file.
        if ($outputFile === '-' || $outputFile === ''){
            $fpOut = fopen('php://output', 'w');
        } else {
            $fpOut = fopen($outputFile, 'w');
        }
        fwrite($fpOut, $assemblyOutput);
        fclose($fpOut);

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
        $cmd = ['as', '--noexecstack', '-c', $input, '-o', $output];
        self::runSubprocess($cmd);
    }
    
    private static function findFile(string $pattern): ?string
    {
        $files = glob($pattern);
        if (empty($files)) {
            return null;
        }
        return end($files);
    }
    
    private static function fileExists(string $path): bool
    {
        return file_exists($path);
    }
    
    private static function findLibPath(): string
    {
        if (self::fileExists('/usr/lib/x86_64-linux-gnu/crti.o')) {
            return '/usr/lib/x86_64-linux-gnu';
        }
        if (self::fileExists('/usr/lib64/crti.o')) {
            return '/usr/lib64';
        }
        Console::error('library path is not found');
    }
    
    private static function findGccLibpath(): string
    {
        $paths = [
            '/usr/lib/gcc/x86_64-linux-gnu/*/crtbegin.o',
            '/usr/lib/gcc/x86_64-pc-linux-gnu/*/crtbegin.o',
            '/usr/lib/gcc/x86_64-redhat-linux/*/crtbegin.o',
        ];
        
        foreach ($paths as $pattern) {
            $path = self::findFile($pattern);
            if ($path) {
                return dirname($path);
            }
        }
        
        Console::error('gcc library path is not found');
    }
    
    private static function runLinker(StringArray $inputs, string $output): void
    {
        $arr = new StringArray();
        
        $arr->push('ld');
        $arr->push('-o');
        $arr->push($output);
        $arr->push('-m');
        $arr->push('elf_x86_64');
        $arr->push('-dynamic-linker');
        $arr->push('/lib64/ld-linux-x86-64.so.2');
        
        $libpath = self::findLibPath();
        $gccLibpath = self::findGccLibpath();
        
        $arr->push("$libpath/crt1.o");
        $arr->push("$libpath/crti.o");
        $arr->push("$gccLibpath/crtbegin.o");
        $arr->push("-L$gccLibpath");
        $arr->push("-L$libpath");
        $arr->push("-L$libpath/..");
        $arr->push('-L/usr/lib64');
        $arr->push('-L/lib64');
        $arr->push('-L/usr/lib/x86_64-linux-gnu');
        $arr->push('-L/usr/lib/x86_64-pc-linux-gnu');
        $arr->push('-L/usr/lib/x86_64-redhat-linux');
        $arr->push('-L/usr/lib');
        $arr->push('-L/lib');
        
        foreach (self::$ldExtraArgs->getData() as $arg) {
            $arr->push($arg);
        }
        
        foreach ($inputs->getData() as $input) {
            $arr->push($input);
        }
        
        $arr->push('-lc');
        $arr->push('-lgcc');
        $arr->push('--as-needed');
        $arr->push('-lgcc_s');
        $arr->push('--no-as-needed');
        $arr->push("$gccLibpath/crtend.o");
        $arr->push("$libpath/crtn.o");
        
        self::runSubprocess($arr->getData());
    }

    private static function getFileType(string $filename): FileType
    {
        if (self::endswith($filename, '.o')) {
            return FileType::FILE_OBJ;
        }

        if (self::$optX !== FileType::FILE_NONE) {
            return self::$optX;
        }

        if (self::endswith($filename, '.c')) {
            return FileType::FILE_C;
        }
        if (self::endswith($filename, '.s')) {
            return FileType::FILE_ASM;
        }

        if ($filename === '-') {
            // stdin requires -x option
            Console::error('<command line>: -x option is required for stdin');
        }

        Console::error('<command line>: unknown file extension: %s', $filename);
    }

    public static function main(?int $argc = null, ?array $argv = null): int
    {
        self::$optInclude = new StringArray();
        self::$tmpFiles = new StringArray();
        self::$inputPaths = new StringArray();
        self::$includePaths = new StringArray();
        self::$ldExtraArgs = new StringArray();
        register_shutdown_function([self::class, 'cleanup']);
        
        Preprocessor::initMacros();
        self::parseArgs($argc, $argv);

        if (self::$options['help'] ?? false) {
            self::displayHelp();
            return 0;
        }

        if (self::$options['cc1'] ?? false){
            self::addDefaultIncludePaths($argv[0]);
            return self::cc1();
        }
        
        if (self::$inputPaths->getLength() > 1 and isset(self::$options['o']) and (self::$options['c'] ?? false or self::$options['S'] ?? false or self::$options['E'] ?? false)) {
            Console::error("cannot specify '-o' with '-c,' '-S' or '-E' with multiple files");
        }
        
        $ldArgs = new StringArray();
        
        $inputFiles = self::$inputPaths->getData();
        foreach ($inputFiles as $inputPath) {
            if (str_starts_with($inputPath, '-l')) {
                $ldArgs->push($inputPath);
                continue;
            }

            if (isset(self::$options['o'])) {
                $outputPath = self::$options['o'];
            } else if (self::$options['S'] ?? false) {
                $outputPath = self::replaceExt($inputPath, '.s');
            } else {
                $outputPath = self::replaceExt($inputPath, '.o');
            }

            $type = self::getFileType($inputPath);

            // Handle .o
            if ($type === FileType::FILE_OBJ) {
                $ldArgs->push($inputPath);
                continue;
            }
            
            // Handle .s
            if ($type === FileType::FILE_ASM) {
                if (!(self::$options['S'] ?? false)) {
                    self::assemble($inputPath, $outputPath);
                }
                continue;
            }
            
            assert($type === FileType::FILE_C);
            
            // Just preprocess
            if (self::$options['E'] ?? false) {
                self::runCc1($argc, $argv, $inputPath, null);
                continue;
            }
            
            // Just compile
            if (self::$options['S'] ?? false) {
                self::runCc1($argc, $argv, $inputPath, $outputPath);
                continue;
            }
            
            // Compile and assemble
            if (self::$options['c'] ?? false) {
                $tmp = self::createTmpfile();
                self::runCc1($argc, $argv, $inputPath, $tmp);
                self::assemble($tmp, $outputPath);
                continue;
            }
            
            // Compile, assemble and link
            $tmp1 = self::createTmpfile();
            $tmp2 = self::createTmpfile();
            self::runCc1($argc, $argv, $inputPath, $tmp1);
            self::assemble($tmp1, $tmp2);
            $ldArgs->push($tmp2);
        }
        
        if ($ldArgs->getLength() > 0) {
            $output = self::$options['o'] ?? 'a.out';
            self::runLinker($ldArgs, $output);
        }
        
        return 0;
    }
}