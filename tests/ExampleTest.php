<?php

class ExampleTest extends \PHPUnit\Framework\TestCase
{
    public function testBaseParaProximosTest(): void
    {
        require 'index.php';
        $ar1 = [1, 2];
        $ar2 = [1, 2];
        $string1 = compareArraysBasic($ar1, $ar2);
        $string2 = "the arrays are the same!";

        $this->assertFalse($string1 === $string2);
    }
}