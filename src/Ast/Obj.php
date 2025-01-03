<?php
namespace Pcc\Ast;
class Obj
{
    public string $name;
    public Type $ty;
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
    public ?string $initData = null;
    /** @var Relocation[] */
    public array $rels = [];

    // Function
    /** @var \Pcc\Ast\Obj[] */
    public array $params;
    /** @var \Pcc\Ast\Node[]  */
    public array $body;
    /** @var array<string, \Pcc\Ast\Obj> */
    public array $locals = [];
    public int $stackSize;


    public function __construct(string $name)
    {
        $this->name = $name;
    }
}