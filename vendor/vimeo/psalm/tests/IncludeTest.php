<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;

class IncludeTest extends PHPUnit_Framework_TestCase
{
    /** @var \PhpParser\Parser */
    protected static $parser;

    /** @var \Psalm\Checker\ProjectChecker */
    protected $project_checker;

    /**
     * @return void
     */
    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    }

    /**
     * @return void
     */
    public function setUp()
    {
        $config = new TestConfig();
        $config->throw_exception = true;
        FileChecker::clearCache();
        $this->project_checker = new \Psalm\Checker\ProjectChecker();
    }

    /**
     * @return void
     */
    public function testBasicRequire()
    {
        $this->project_checker->registerFile(
            getcwd() . DIRECTORY_SEPARATOR . 'file1.php',
            '<?php
            class A{
            }
            '
        );

        $file2_checker = new FileChecker(
            getcwd() . DIRECTORY_SEPARATOR . 'file2.php',
            $this->project_checker,
            self::$parser->parse('<?php
            require("file1.php");

            class B {
                public function foo() : void {
                    (new A);
                }
            }
            ')
        );

        $file2_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testNestedRequire()
    {
        $this->project_checker->registerFile(
            getcwd() . DIRECTORY_SEPARATOR . 'file1.php',
            '<?php
            class A{
                public function fooFoo() : void {

                }
            }
            '
        );

        $this->project_checker->registerFile(
            getcwd() . DIRECTORY_SEPARATOR . 'file2.php',
            '<?php
            require("file1.php");

            class B extends A{
            }
            '
        );

        $file2_checker = new FileChecker(
            getcwd() . DIRECTORY_SEPARATOR . 'file3.php',
            $this->project_checker,
            self::$parser->parse('<?php
            require("file2.php");

            class C extends B {
                public function doFoo() : void {
                    $this->fooFoo();
                }
            }
            ')
        );

        $file2_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testRequireNamespace()
    {
        $this->project_checker->registerFile(
            getcwd() . DIRECTORY_SEPARATOR . 'file1.php',
            '<?php
            namespace Foo;

            class A{
            }
            '
        );

        $file2_checker = new FileChecker(
            getcwd() . DIRECTORY_SEPARATOR . 'file2.php',
            $this->project_checker,
            self::$parser->parse('<?php
            require("file1.php");

            class B {
                public function foo() : void {
                    (new Foo\A);
                }
            }
            ')
        );

        $file2_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testRequireFunction()
    {
        $this->project_checker->registerFile(
            getcwd() . DIRECTORY_SEPARATOR . 'file1.php',
            '<?php
            function fooFoo() : void {

            }
            '
        );

        $file2_checker = new FileChecker(
            getcwd() . DIRECTORY_SEPARATOR . 'file2.php',
            $this->project_checker,
            self::$parser->parse('<?php
            require("file1.php");

            fooFoo();
            ')
        );

        $file2_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testRequireNamespaceWithUse()
    {
        $this->project_checker->registerFile(
            getcwd() . DIRECTORY_SEPARATOR . 'file1.php',
            '<?php
            namespace Foo;

            class A{
            }
            '
        );

        $file2_checker = new FileChecker(
            getcwd() . DIRECTORY_SEPARATOR . 'file2.php',
            $this->project_checker,
            self::$parser->parse('<?php
            require("file1.php");

            use Foo\A;

            class B {
                public function foo() : void {
                    (new A);
                }
            }
            ')
        );

        $file2_checker->visitAndAnalyzeMethods();
    }
}
