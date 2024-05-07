<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class NamespaceTest extends PHPUnit_Framework_TestCase
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

        $config = new TestConfig();
    }

    /**
     * @return void
     */
    public function setUp()
    {
        FileChecker::clearCache();
        $this->project_checker = new \Psalm\Checker\ProjectChecker();
    }

    /**
     * @return void
     */
    public function testEmptyNamespace()
    {
        $stmts = self::$parser->parse('<?php
        namespace A {
            /** @return void */
            function foo() {

            }

            class Bar {

            }
        }
        namespace {
            A\foo();
            \A\foo();

            (new A\Bar);
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }

    /**
     * @return void
     */
    public function testConstantReference()
    {
        $stmts = self::$parser->parse('<?php
        namespace Aye\Bee {
            const HELLO = "hello";
        }
        namespace Aye\Bee {
            /** @return void */
            function foo() {
                echo \Aye\Bee\HELLO;
            }

            class Bar {
                /** @return void */
                public function foo() {
                    echo \Aye\Bee\HELLO;
                }
            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }
}
