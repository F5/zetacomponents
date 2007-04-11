<?php
/**
 * ezcConsoleProgressMonitorOptionsTest class.
 * 
 * @package ConsoleTools
 * @subpackage Tests
 * @version //autogentag//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

ezcTestRunner::addFileToFilter( __FILE__ );

/**
 * Test suite for ezcConsoleProgressMonitorOptions struct.
 * 
 * @package ConsoleTools
 * @subpackage Tests
 */
class ezcConsoleProgressMonitorOptionsTest extends ezcTestCase
{

	public static function suite()
	{
		return new PHPUnit_Framework_TestSuite( "ezcConsoleProgressMonitorOptionsTest" );
	}
    
    /**
     * testConstructorNew
     * 
     * @access public
     */
    public function testConstructorNew()
    {
        $fake = new ezcConsoleProgressMonitorOptions(
            array( 
                "formatString" => "%8.1f%% %s %s",
            )
        );
        $this->assertEquals( 
            $fake,
            new ezcConsoleProgressMonitorOptions(),
            'Default values incorrect for ezcConsoleProgressMonitorOptions.'
        );
    }

    public function testNewAccess()
    {
        $opt = new ezcConsoleProgressMonitorOptions();
        $this->assertEquals( "%8.1f%% %s %s", $opt->formatString );

        $this->assertEquals( $opt["formatString"], "%8.1f%% %s %s" );
    }

}

?>
