<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;

class ConfigTest extends PHPUnit_Framework_TestCase
{
    /** @var \Psalm\Checker\ProjectChecker */
    protected $project_checker;

    /**
     * @return void
     */
    public function setUp()
    {
        FileChecker::clearCache();
        $this->project_checker = new \Psalm\Checker\ProjectChecker();
    }

    /**
     * @return       string[]
     * @psalm-return array<mixed, string>
     */
    public static function getAllIssues()
    {
        return array_filter(
            array_map(
                /**
                 * @param string $file_name
                 * @return string
                 */
                function ($file_name) {
                    return substr($file_name, 0, -4);
                },
                scandir(dirname(__DIR__) . '/src/Psalm/Issue')
            ),
            /**
             * @param string $issue_name
             * @return bool
             */
            function ($issue_name) {
                return !empty($issue_name) && $issue_name !== 'CodeError' && $issue_name !== 'CodeIssue';
            }
        );
    }

    /**
     * @return void
     */
    public function testBarebonesConfig()
    {
        $config = Config::loadFromXML('psalm.xml', '<?xml version="1.0"?>
<psalm>
    <projectFiles>
        <directory name="src" />
    </projectFiles>
</psalm>');

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIgnoreProjectDirectory()
    {
        $config = Config::loadFromXML('psalm.xml', '<?xml version="1.0"?>
<psalm>
    <projectFiles>
        <directory name="src" />
        <ignoreFiles>
            <directory name="src/Psalm/Checker" />
        </ignoreFiles>
    </projectFiles>
</psalm>');

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/FileChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIssueHandler()
    {
        $config = Config::loadFromXML('psalm.xml', '<?xml version="1.0"?>
<psalm>
    <projectFiles>
        <directory name="src" />
        <directory name="tests" />
    </projectFiles>

    <issueHandlers>
        <MissingReturnType errorLevel="suppress" />
    </issueHandlers>
</psalm>');

        $this->assertTrue($config->excludeIssueInFile('MissingReturnType', realpath('tests/ConfigTest.php')));
        $this->assertTrue($config->excludeIssueInFile('MissingReturnType', realpath('src/Psalm/Type.php')));
    }

    /**
     * @return void
     */
    public function testIssueHandlerWithCustomErrorLevels()
    {
        $config = Config::loadFromXML('psalm.xml', '<?xml version="1.0"?>
<psalm>
    <projectFiles>
        <directory name="src" />
        <directory name="tests" />
    </projectFiles>

    <issueHandlers>
        <MissingReturnType errorLevel="info">
            <errorLevel type="suppress">
                <directory name="tests" />
            </errorLevel>
            <errorLevel type="error">
                <directory name="src/Psalm/Checker" />
            </errorLevel>
        </MissingReturnType>
    </issueHandlers>
</psalm>');

        $this->assertTrue($config->excludeIssueInFile('MissingReturnType', realpath('tests/ConfigTest.php')));
        $this->assertFalse($config->excludeIssueInFile('MissingReturnType', realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->excludeIssueInFile('MissingReturnType', realpath('src/Psalm/Checker/FileChecker.php')));

        $this->assertSame('info', $config->getReportingLevelForFile('MissingReturnType', realpath('src/Psalm/Type.php')));
        $this->assertSame('error', $config->getReportingLevelForFile('MissingReturnType', realpath('src/Psalm/Checker/FileChecker.php')));
    }

    /**
     * @return void
     */
    public function testAllPossibleIssues()
    {
        $all_possible_handlers = implode(
            ' ',
            array_map(
                /**
                 * @param string $issue_name
                 * @return string
                 */
                function ($issue_name) {
                    return '<' . $issue_name . ' errorLevel="suppress" />' . PHP_EOL;
                },
                self::getAllIssues()
            )
        );

        $config = Config::loadFromXML('psalm.xml', '<?xml version="1.0"?>
<psalm>
    <projectFiles>
        <directory name="src" />
    </projectFiles>

    <issueHandlers>
    ' . $all_possible_handlers . '
    </issueHandlers>
</psalm>');
    }

    /**
     * @expectedException        \Psalm\Exception\ConfigException
     * @expectedExceptionMessage This element is not expected
     * @return                   void
     */
    public function testImpossibleIssue()
    {
        $config = Config::loadFromXML('psalm.xml', '<?xml version="1.0"?>
<psalm>
    <projectFiles>
        <directory name="src" />
    </projectFiles>

    <issueHandlers>
        <ImpossibleIssue errorLevel="suppress" />
    </issueHandlers>
</psalm>');
    }
}
