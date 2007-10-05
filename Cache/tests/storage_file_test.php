<?php
/**
 * ezcCacheStorageTest 
 * 
 * @package Cache
 * @subpackage Tests
 * @version //autogen//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**;
 * Test suite for ezcStorageFile class.
 * 
 * @package Cache
 * @subpackage Tests
 */
class ezcCacheStorageFileTest extends ezcTestCase
{
    public function testGenerateIdentifier1()
    {
        $obj = new ezcCacheStorageFileArray( '/tmp' );
        $id = $obj->generateIdentifier( 'contentstructuremenu/show_content_structure-2 file:foobar' );
        $this->assertEquals( 'contentstructuremenu'.DIRECTORY_SEPARATOR.'show_content_structure-2_file:foobar-.cache', $id );
    }

    public function testGenerateIdentifier2()
    {
        $obj = new ezcCacheStorageFileArray( '/tmp' );
        $id = $obj->generateIdentifier( 'contentstructuremenu\show_content_structure-2 file:foobar' );
        $this->assertEquals( 'contentstructuremenu'.DIRECTORY_SEPARATOR.'show_content_structure-2_file:foobar-.cache', $id );
    }

    public function testGenerateIdentifier3()
    {
        $obj = new ezcCacheStorageFileArray( '/tmp', array( 'extension' => '.c' ) );
        $id = $obj->generateIdentifier( 'contentstructuremenu\show_content_structure-2 file:foobar' );
        $this->assertEquals( 'contentstructuremenu'.DIRECTORY_SEPARATOR.'show_content_structure-2_file:foobar-.c', $id );
    }

    public function testGenerateIdentifier4()
    {
        $obj = new ezcCacheStorageFileArray( '/tmp', array( 'extension' => '.c' ) );
        $id = $obj->generateIdentifier( 1 );
        $this->assertEquals( '1-.c', $id );
    }

    public function testGenerateIdentifier5()
    {
        $obj = new ezcCacheStorageFileArray( '/tmp', array( 'extension' => '.c' ) );
        $id = $obj->generateIdentifier( 1, array( "foo" => "bar", "baz" => "bam" ) );
        $this->assertEquals( '1-baz=bam-foo=bar.c', $id );
    }

    public function testInvalidConfigurationOption()
    {
        try
        {
            $obj = new ezcCacheStorageFileArray( '/tmp', array( 'eXtEnSiOn' => '.c' ) );
            $this->fail( 'Expected exception was not thrown' );
        }
        catch ( ezcBasePropertyNotFoundException $e )
        {
            return;
        }
    }

    public function testCountDataItems()
    {
        $cache = new ezcCacheStorageFileArray( $this->createTempDir( 'ezcCacheStorageFileTest' ), array( 'extension' => '.c' ) );
        $data = array( 
            array( 
                'attributes' => array( 'lang' => 'en', 'section' => 'articles' ),
                'content'    => array( 'lang' => 'en', 'section' => 'articles' ),
            ),
            array( 
                'attributes' => array( 'lang' => 'de', 'section' => 'articles' ),
                'content'    => array( 'lang' => 'de', 'section' => 'articles' ),
            ),
            array( 
                'attributes' => array( 'lang' => 'no', 'section' => 'articles' ),
                'content'    => array( 'lang' => 'no', 'section' => 'articles' ),
            )
        );
        foreach ( $data as $id => $dataArr )
        {
            $cache->store( $id, $dataArr['content'], $dataArr['attributes'] );
        }

        $this->assertEquals( $cache->countDataItems( 0 ), 1, 'Count data items failed with ID.' );
        $this->assertEquals( $cache->countDataItems( null, array( 'lang' => 'no' ) ), 1, 'Count data items failed with attribute <lang>.' );
        $this->assertEquals( $cache->countDataItems( null, array( 'section' => 'articles' ) ), 3, 'Count data items failed with attribute <articles>.' );
        $this->removeTempDir();
    }

    public function testFalseLifetime()
    {
        $cache = new ezcCacheStorageFileArray(
            $this->createTempDir( 'ezcCacheStorageFileTest' ), 
            array( 'extension' => '.c', 'ttl' => false )
        );
        $data = array( 
            'attributes' => array( 'lang' => 'en', 'section' => 'articles' ),
            'content'    => array( 'lang' => 'en', 'section' => 'articles' ),
        );

        $cache->store( 0, $data['attributes'], $data['content'] );

        $file = $cache->generateIdentifier( 0, $data['attributes'] );
        // Fake mtime and atime
        touch( $cache->getLocation() . '/' . $file, time() - 90000, time() - 90000 );
        
        $this->assertNotEquals( false, $cache->restore( 0, $data['attributes'] ) );

        $this->removeTempDir();
    }

    public function testDeleteRecursive()
    {
        $tempDir = $this->createTempDir( 'ezcCacheStorageFileTest' );
        $cache = new ezcCacheStorageFileArray( $tempDir, array( 'extension' => '.c' ) );
        $data = array( 
            "foo" => array( 
                'attributes' => array( 'lang' => 'en', 'section' => 'articles' ),
                'content'    => array( 'lang' => 'en', 'section' => 'articles' ),
            ),
            "foo/bar" => array( 
                'attributes' => array( 'lang' => 'de', 'section' => 'articles' ),
                'content'    => array( 'lang' => 'de', 'section' => 'articles' ),
            ),
            "foo/baz" => array( 
                'attributes' => array( 'lang' => 'no', 'section' => 'articles' ),
                'content'    => array( 'lang' => 'no', 'section' => 'articles' ),
            )
        );

        foreach ( $data as $id => $dataArr )
        {
            $cache->store( $id, $dataArr['content'], $dataArr['attributes'] );
        }

        $cache->delete( null, array( "section" => "articles" ) );

        $this->removeTempDir();
    }

    public function testPermissions()
    {
        $cache = new ezcCacheStorageFileArray(
            $this->createTempDir( 'ezcCacheStorageFileTest' ), 
            array( 'extension' => '.c', 'ttl' => false )
        );
        $data = array( 
            'attributes' => array( 'lang' => 'en', 'section' => 'articles' ),
            'content'    => array( 'lang' => 'en', 'section' => 'articles' ),
        );

        $cache->store( 0, $data['attributes'], $data['content'] );
        $file = $cache->getLocation() . "/" . $cache->generateIdentifier( 0, $data['attributes'] );
        
        $this->assertEquals( 0644, ( fileperms( $file ) & 0777 ) );

        $cache->options->permissions = 0777;

        $cache->store( 1, $data['attributes'], $data['content'] );
        $file = $cache->getLocation() . "/" . $cache->generateIdentifier( 1, $data['attributes'] );

        $this->assertEquals( 0777,  ( fileperms( $file ) & 0777 ) );
        
        $this->removeTempDir();
    }

    public function testRestoreWithoutSearch()
    {
        $cache = new ezcCacheStorageFileArray(
            $this->createTempDir( 'ezcCacheStorageFileTest' ), 
            array( 'extension' => '.c', 'ttl' => false )
        );

        $id = "test";
        $keys = array( 10000, 1, 10, 100, 1000 );
        
        // Store
        foreach ( $keys as $key )
        {
            // No cache may exist!
            $this->assertFalse(
                $cache->restore( $id, array( 0 => $key, 1 => "en" ), false )
            );
            $cache->store( $id, "ID=$key&LANG=en", array( 0 => $key, 1 => "en" ) );
        }

        // Restore
        foreach ( $keys as $key )
        {
            $this->assertEquals(
                $cache->restore( $id, array( 0 => $key, 1 => "en" ), false ),
                "ID=$key&LANG=en"
            );
        }

        $this->removeTempDir();
    }

    public static function suite()
    {
         return new PHPUnit_Framework_TestSuite( "ezcCacheStorageFileTest" );
    }
}
?>
