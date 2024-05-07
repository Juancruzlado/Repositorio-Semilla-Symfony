<?php
namespace Psalm\Type\Atomic;

class T extends TString
{
    /**
     * Used to hold information as to what this refers to
     * @var string
     */
    public $typeof;

    /**
     * @param string $typeof the variable id
     */
    public function __construct($typeof)
    {
        $this->typeof = $typeof;
    }
}
