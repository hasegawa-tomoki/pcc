<?php
namespace Pcc\Ast\Scope;

class Scope
{
    /** @var VarScope[] */
    public array $vars = [];
    /** @var TagScope[] */
    public array $tags = [];
}