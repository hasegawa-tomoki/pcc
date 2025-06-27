<?php
namespace Pcc\Ast\Scope;

use Pcc\HashMap\HashMap;

class Scope
{
    public ?Scope $next = null;
    public HashMap $vars;
    public HashMap $tags;

    public function __construct()
    {
        $this->vars = new HashMap();
        $this->tags = new HashMap();
    }
}