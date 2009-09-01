<?php
/**
 * File containing the ezcDocumentOdt class
 *
 * @package Document
 * @version //autogen//
 * @copyright Copyright (C) 2005-2009 eZ Systems AS. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * The document handler for Open Document Text (ODT) documents.
 *
 * @package Document
 * @version //autogen//
 */
class ezcDocumentOdt extends ezcDocumentXmlBase /* implements ezcDocumentValidation */
{
    const NS_ODT_CONFIG  = 'urn:oasis:names:tc:opendocument:xmlns:config:1.0';
    const NS_ODT_DRAWING = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    const NS_ODT_META    = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';
    const NS_ODT_NUMBER  = 'urn:oasis:names:tc:opendocument:xmlns:data style:1.0';
    const NS_ODT_OFFICE  = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    const NS_ODT_SCRIPT  = 'urn:oasis:names:tc:opendocument:xmlns:script:1.0';
    const NS_ODT_STYLE   = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    const NS_ODT_TABLE   = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    const NS_ODT_TEXT    = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    const NS_EZC = 'http://ezcomponents.org/Document/Odt';

    /**
     * Array with filter objects for the input ODT document.
     *
     * @var array(ezcDocumentOdtFilter)
     */
    protected $filters;

    /**
     * Construct ODT document.
     *
     * @ignore
     * @param ezcDocumentOdtOptions $options
     * @return void
     */
    public function __construct( ezcDocumentOdtOptions $options = null )
    {
        parent::__construct( $options === null ?
            new ezcDocumentOdtOptions() :
            $options );

        $this->filters = array(
            new ezcDocumentOdtElementFilter(),
        );
    }

    /**
     * Create document from input string.
     *
     * Create a document of the current type handler class and parse it into a
     * usable internal structure.
     *
     * @param string $string
     * @return void
     */
    public function loadString( $string )
    {
        // Use internal error handling to handle XML errors manually.
        $oldXmlErrorHandling = libxml_use_internal_errors( true );
        libxml_clear_errors();

        // Load XML document
        $this->document = new DOMDocument();
        $this->document->registerNodeClass( 'DOMElement', 'ezcDocumentOdtDomElement' );

        // Use the loadHtml method here, as it for example convers tag names
        // and attribute names to lower case, and handles some more errors
        // common in HTML documents.
        $this->document->loadXml( $string );

        $errors = ( $this->options->failOnError ?
            libxml_get_errors() :
            null );

        libxml_clear_errors();
        libxml_use_internal_errors( $oldXmlErrorHandling );

        // If there are errors and the error handling is activated throw an
        // exception with the occured errors.
        if ( $errors )
        {
            throw new ezcDocumentErroneousXmlException( $errors );
        }
    }

    /**
     * Set filters
     *
     * Set an array with filter objects, which extract the sematic
     * information from the given ODT document.
     *
     * @param array $filters
     * @return void
     */
    public function setFilters( array $filters )
    {
        $this->filters = $filters;
    }

    /**
     * Build docbook document out of annotated ODT document
     *
     * @param DOMDocument $document
     * @return DOMDocument
     */
    protected function buildDocbookDocument( DOMDocument $document )
    {
        $docbook = new DOMDocument( '1.0', 'utf-8' );
        $docbook->preserveWhiteSpace = false;
        $docbook->formatOutput = true;

        $root = $docbook->createElementNs( 'http://docbook.org/ns/docbook', 'article' );
        $docbook->appendChild( $root );

        $xpath = new DOMXPath( $document );
        $xpath->registerNamespace( 'office', self::NS_ODT_OFFICE );
        // @TODO: Process meta data
        $body = $xpath->query( '//office:body' )->item( 0 );
        $this->transformToDocbook( $body, $root );

        return $docbook;
    }

    /**
     * Recursively transform annotated ODT elements to docbook
     *
     * @param DOMElement $odt
     * @param DOMElement $docbook
     * @param bool $significantWhitespace
     * @return void
     */
    protected function transformToDocbook( DOMElement $odt, DOMElement $docbook, $significantWhitespace = false )
    {
        if ( ( $tagName = $odt->getProperty( 'type' ) ) !== false )
        {
            $node = new DOMElement( $tagName );
            $docbook->appendChild( $node );
            $docbook = $node;

            if ( ( $attributes = $odt->getProperty( 'attributes' ) ) !== false )
            {
                foreach ( $attributes as $name => $value )
                {
                    // @TODO: Doesn't DOM handle this?
                    $node->setAttribute( $name, htmlspecialchars( $value ) );
                }
            }
        }

        foreach ( $odt->childNodes as $child )
        {
            switch ( $child->nodeType )
            {
                case XML_ELEMENT_NODE:
                    $this->transformToDocbook(
                        $child,
                        $docbook,
                        $significantWhitespace || $odt->getProperty( 'whitespace' ) === 'significant'
                    );
                    break;

                case XML_TEXT_NODE:
                    // Skip pure whitespace text nodes, except for
                    // intentionally converted <br> elements.
                    if ( ( trim( $text = $child->data ) === '' ) &&
                         ( !$significantWhitespace ) &&
                         ( $odt->getProperty( 'whitespace' ) !== 'significant' ) )
                    {
                        continue;
                    }

                    if ( ( $odt->getProperty( 'whitespace' ) === 'significant' ) ||
                         ( $significantWhitespace ) )
                    {
                        // Don't normalize inside nodes with significant whitespaces.
                        $text = new DOMText( $text );
                        $docbook->appendChild( $text );
                    }
                    else
                    {
                        $text = new DOMText( preg_replace( '(\s+)', ' ', $text ) );
                        $docbook->appendChild( $text );
                    }
                    break;

                case XML_CDATA_SECTION_NODE:
//                    $data = new DOMCharacterData();
//                    $data->appendData( $child->data );
//                    $docbook->appendChild( $data );
                    break;

                case XML_ENTITY_NODE:
                    // Seems not required, as entities in the source document
                    // are automatically transformed back to their text
                    // targets.
                    break;

                case XML_COMMENT_NODE:
                    // Ignore comments
                    break;

                    $comment = new DOMElement( 'comment', $child->data );
                    $docbook->appendChild( $comment );
                    break;
            }
        }
    }

    /**
     * Return document compiled to the docbook format
     *
     * The internal document structure is compiled to the docbook format and
     * the resulting docbook document is returned.
     *
     * This method is required for all formats to have one central format, so
     * that each format can be compiled into each other format using docbook as
     * an intermediate format.
     *
     * You may of course just call an existing converter for this conversion.
     *
     * @return ezcDocumentDocbook
     */
    public function getAsDocbook()
    {
        foreach ( $this->filters as $filter )
        {
            $filter->filter( $this->document );
        }

        $docbook = new ezcDocumentDocbook();
        $docbook->setDomDocument(
            $this->buildDocbookDocument( $this->document )
        );
        $docbook->setPath( $this->path );
        return $docbook;
    }

    /**
     * Create document from docbook document
     *
     * A document of the docbook format is provided and the internal document
     * structure should be created out of this.
     *
     * This method is required for all formats to have one central format, so
     * that each format can be compiled into each other format using docbook as
     * an intermediate format.
     *
     * You may of course just call an existing converter for this conversion.
     *
     * @param ezcDocumentDocbook $document
     * @return void
     */
    public function createFromDocbook( ezcDocumentDocbook $document )
    {
        /*
         * @TODO
        if ( $this->options->validate &&
             $document->validateString( $document ) !== true )
        {
            $this->triggerError( E_WARNING, "You try to convert an invalid docbook document. This may lead to invalid output." );
        }

        $this->path = $document->getPath();

        $converter = new ezcDocumentDocbookToHtmlConverter();
        $converter->options->errorReporting = $this->options->errorReporting;
        $doc = $converter->convert( $document );
        $this->document = $doc->getDomDocument();
        */
    }

    /**
     * Return document as string
     *
     * Serialize the document to a string an return it.
     *
     * @return string
     */
    public function save()
    {
        $source = $this->document->saveXml( $this->document, LIBXML_NOEMPTYTAG );
        return $source;
    }

    /**
     * Validate the input file
     *
     * Validate the input file against the specification of the current
     * document format.
     *
     * Returns true, if the validation succeded, and an array with
     * ezcDocumentValidationError objects otherwise.
     *
     * @param string $file
     * @return mixed
     */
    public function validateFile( $file )
    {
        /*
         * @TODO
        $oldSetting = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $document = new DOMDocument();
        $document->load( $file );
        $document->schemaValidate( dirname( __FILE__ ) . '/xhtml/schema/xhtml1-transitional.xsd' );

        // Get all errors
        $xmlErrors = libxml_get_errors();
        $errors = array();
        foreach ( $xmlErrors as $error )
        {
            $errors[] = new ezcDocumentValidationError( $error );
        }
        libxml_clear_errors();
        libxml_use_internal_errors( $oldSetting );

        return ( count( $errors ) ? $errors : true );
        */
    }

    /**
     * Validate the input string
     *
     * Validate the input string against the specification of the current
     * document format.
     *
     * Returns true, if the validation succeded, and an array with
     * ezcDocumentValidationError objects otherwise.
     *
     * @param string $string
     * @return mixed
     */
    public function validateString( $string )
    {
        /*
         * @TODO
        $oldSetting = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $document = new DOMDocument();
        $document->loadXml( $string );
        $document->schemaValidate( dirname( __FILE__ ) . '/xhtml/schema/xhtml1-transitional.xsd' );

        // Get all errors
        $xmlErrors = libxml_get_errors();
        $errors = array();
        foreach ( $xmlErrors as $error )
        {
            $errors[] = ezcDocumentValidationError::createFromLibXmlError( $error );
        }
        libxml_clear_errors();
        libxml_use_internal_errors( $oldSetting );

        return ( count( $errors ) ? $errors : true );
        */
    }
}

?>