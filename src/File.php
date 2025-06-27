<?php
namespace Pcc;

class File
{
    public string $name;
    public int $fileNo;
    public string $contents;
    
    // For #line directive
    public string $displayName;
    public int $lineDelta = 0;
    
    private static array $inputFiles = [];
    
    public function __construct(string $name, int $fileNo, string $contents)
    {
        $this->name = $name;
        $this->displayName = $name;
        $this->fileNo = $fileNo;
        $this->contents = $contents;
        
        self::$inputFiles[] = $this;
    }
    
    public static function getInputFiles(): array
    {
        return self::$inputFiles;
    }
}