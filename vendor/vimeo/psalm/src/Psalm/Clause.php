<?php
namespace Psalm;

class Clause
{
    /**
     * An array of strings of the form
     * [
     *     '$a' => ['empty'],
     *     '$b' => ['!empty'],
     *     '$c' => ['!null'],
     *     '$d' => ['string', 'int']
     * ]
     *
     * representing the formula
     *
     * !$a || $b || $c !== null || is_string($d) || is_int($d)
     *
     * @var array<string, array<string>>
     */
    public $possibilities;

    /**
     * An array of things that are not true
     * [
     *     '$a' => ['!empty'],
     *     '$b' => ['empty'],
     *     '$c' => ['null'],
     *     '$d' => ['!string', '!int']
     * ]
     * represents the formula
     *
     * $a && !$b && $c === null && !is_string($d) && !is_int($d)
     *
     * @var array<string, array<string>>|null
     */
    public $impossibilities;

    /** @var bool */
    public $wedge;

    /** @var bool */
    public $reconcilable;

    /**
     * @param array<string, array<string>>  $possibilities
     * @param bool                          $wedge
     * @param bool                          $reconcilable
     */
    public function __construct(array $possibilities, $wedge = false, $reconcilable = true)
    {
        $this->possibilities = $possibilities;
        $this->wedge = $wedge;
        $this->reconcilable = $reconcilable;
    }

    /**
     * @param  Clause $other_clause
     * @return bool
     */
    public function contains(Clause $other_clause)
    {
        foreach ($other_clause->possibilities as $var => $possible_types) {
            if (!isset($this->possibilities[$var]) || count(array_diff($possible_types, $this->possibilities[$var]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets a hash of the object – will be unique if we're unable to easily reconcile this with others
     *
     * @return string
     */
    public function getHash()
    {
        ksort($this->possibilities);

        foreach ($this->possibilities as $var => &$possible_types) {
            sort($possible_types);
        }

        return md5(json_encode($this->possibilities)) .
            ($this->wedge || !$this->reconcilable ? spl_object_hash($this) : '');
    }
}
