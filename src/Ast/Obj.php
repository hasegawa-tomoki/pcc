<?php
namespace Pcc\Ast;
use Pcc\Tokenizer\Token;
use Pcc\StringArray;

class Obj
{
    public string $name;
    public Type $ty;
    public Token $tok;
    // Local or global/function
    public bool $isLocal = false;
    public int $align = 0;

    // Local variable
    public int $offset;

    // Global variable or function
    public bool $isFunction = false;
    public bool $isDefinition;
    public bool $isStatic;

    // Global variable
    public bool $isTentative = false;
    public bool $isTls = false;
    public ?string $initData = null;
    /** @var Relocation[] */
    public array $rels = [];

    // Function
    public bool $isInline = false;
    /** @var \Pcc\Ast\Obj[] */
    public array $params;
    /** @var \Pcc\Ast\Node[]  */
    public array $body;
    /** @var array<string, \Pcc\Ast\Obj> */
    public array $locals = [];
    public ?Obj $vaArea = null;
    public ?Obj $allocaBottom = null;
    public int $lvarStackSize;

    // Static inline function
    public bool $isLive = false;
    public bool $isRoot = false;
    public StringArray $refs;


    public function __construct(string $name)
    {
        $this->name = $name;
        $this->refs = new StringArray();
    }
}