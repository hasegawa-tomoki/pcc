<?php
namespace Pcc;

class File
{
    public string $name;
    public int $fileNo;
    public string $contents;
    
    public function __construct(string $name, int $fileNo, string $contents)
    {
        $this->name = $name;
        $this->fileNo = $fileNo;
        $this->contents = $contents;
    }
}