<?php
/**
 * File containing the ezcDocumentRstAnonymousLinkNode struct
 *
 * @package TextLine
 * @version //autogen//
 * @copyright Copyright (C) 2005-2008 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 * @access private
 */

/**
 * The anonymous link AST node
 * 
 * @package TextLine
 * @version //autogen//
 * @copyright Copyright (C) 2005-2008 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 * @access private
 */
class ezcDocumentRstAnonymousLinkNode extends ezcDocumentRstLinkNode
{
    /**
     * Construct RST document node
     * 
     * @param ezcDocumentRstToken $token
     * @return void
     */
    public function __construct( ezcDocumentRstToken $token )
    {
        parent::__construct( $token, self::LINK_ANONYMOUS );
    }

    /**
     * Set state after var_export
     * 
     * @param array $properties 
     * @return void
     * @ignore
     */
    public static function __set_state( $properties )
    {
        $node = new ezcDocumentRstAnonymousLinkNode(
            $properties['token']
        );

        $node->nodes  = $properties['nodes'];
        $node->target = $properties['target'];
        return $node;
    }
}

?>