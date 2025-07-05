<?php
namespace Pcc\Ast\Scope;

use Pcc\HashMap\HashMap;

class Scope
{
    public ?Scope $parent = null;
    public ?Scope $children = null;
    public ?Scope $siblingNext = null;
    
    /** @var \Pcc\Ast\Obj[] */
    public array $locals = [];
    
    public HashMap $vars;
    public HashMap $tags;

    public function __construct()
    {
        $this->vars = new HashMap();
        $this->tags = new HashMap();
    }
}