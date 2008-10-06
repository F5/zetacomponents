<?php
/**
 * File containing the ezcDocumentWikiFootnoteEndNode struct
 *
 * @package Document
 * @version //autogen//
 * @copyright Copyright (C) 2005-2008 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * Struct for Wiki document inline footnote end syntax tree nodes
 * 
 * @package Document
 * @version //autogen//
 */
class ezcDocumentWikiFootnoteEndNode extends ezcDocumentWikiInlineNode
{
    /**
     * Set state after var_export
     * 
     * @param array $properties 
     * @return void
     * @ignore
     */
    public static function __set_state( $properties )
    {
        $nodeClass = __CLASS__;
        $node = new $nodeClass( $properties['token'] );
        return $node;
    }
}

?>
