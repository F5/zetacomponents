<?php
/**
 * File containing the ezcSearchSolrHandler class.
 *
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 * 
 *   http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 *
 * @package Search
 * @version //autogentag//
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * Solr backend implementation
 *
 * @package Search
 * @version //autogentag//
 */
class ezcSearchSolrHandler implements ezcSearchHandler, ezcSearchIndexHandler
{
    /**
     * Holds the connection to Solr
     *
     * @var resource(stream)
     */
    public $connection;

    /**
     * Hosts the hostname of the solr server
     *
     * @var string
     */
    private $host;

    /**
     * Hosts the port number of the solr server
     *
     * @var int
     */
    private $port;

    /**
     * Hosts the location of the interface on the solr server
     *
     * @var string
     */
    private $location;

    /**
     * Stores the transaction nesting depth.
     *
     * @var integer
     */
    private $inTransaction;

    /**
     * Creates a new Solr handler connection
     *
     * @param string $host
     * @param int    $port
     * @param string $location
     */
    public function __construct( $host = 'localhost', $port = 8983, $location = '/solr' )
    {
        $this->host = $host;
        $this->port = $port;
        $this->location = $location;
        $this->inTransaction = 0;
        $this->connection = null;
    }

    /**
     * Connects to Solr
     *
     * @throws ezcSearchCanNotConnectException if a connection can not be established.
     */
    protected function connect()
    {
        if(null === $this->connection)
        {
            $this->connection = @stream_socket_client( "tcp://{$this->host}:{$this->port}" );
            if ( !$this->connection )
            {
                throw new ezcSearchCanNotConnectException( 'solr', "http://{$this->host}:{$this->port}{$this->location}" );
            }
        }
    }

    /**
     * Closes the connection, and re-connects to Solr
     *
     * @throws ezcSearchCanNotConnectException if a connection can not be established.
     */
    public function reConnect()
    {
        // Added the @ here because the connection could be in a broken state,
        // or already be closed. There is no way to check for that properly, so
        // we'll have to use the shut-up operator.
        @fclose( $this->connection );
        $this->connection = null;
        $this->connect();
    }

    /**
     * Starts a transaction for indexing.
     *
     * When using a transaction, the amount of processing that solr does
     * decreases, increasing indexing performance. Without this, the component
     * sends a commit after every document that is indexed. Transactions can be
     * nested, when commit() is called the same number of times as
     * beginTransaction(), the component sends a commit.
     */
    public function beginTransaction()
    {
        $this->inTransaction++;
    }

    /**
     * Ends a transaction and calls commit.
     *
     * As transactions can be nested, this method will only call commit when
     * all the nested transactions have been ended.
     *
     * @throws ezcSearchTransactionException if no transaction is active.
     */
    public function commit()
    {
        if ( $this->inTransaction < 1 )
        {
            throw new ezcSearchTransactionException( 'Cannot commit without a transaction.' );
        }
        $this->inTransaction--;

        if ( $this->inTransaction == 0 )
        {
            $this->runCommit();
        }
    }

    /**
     * Returns a line with a maximum length of $maxLength from the connection
     *
     * @param int $maxLength
     * @return string
     */
    private function getLine( $maxLength = false )
    {
        $this->connect();
        
        $line = ''; $data = '';
        while ( strpos( $line, "\n" ) === false )
        {
            $line = fgets( $this->connection, $maxLength ? $maxLength + 1: 512 );

            /* If solr aborts the connection, fgets() will
             * return false. We need to throw an exception here to prevent
             * the calling code from looping indefinitely. */
            if ( $line === false )
            {
                $this->connection = null;
                throw new ezcSearchNetworkException( 'Could not read from the stream. It was probably terminated by the host.' );
            }

            $data .= $line;
            if ( strlen( $data ) >= $maxLength )
            {
                break;
            }
        }
        return $data;
    }

    /**
     * Sends the raw command $type to Solr
     *
     * @param string $type
     * @param array(string=>string) $queryString
     * @return string
     * @access private
     */
    public function sendRawGetCommand( $type, $queryString = array() )
    {
        $this->connect();
        
        $statusCode = 0;
        $queryPart = '';
        if ( count( $queryString ) )
        {
            $queryPart = '/?'. $this->httpBuildQuery( $queryString );
        }
        $cmd =  "GET {$this->location}/{$type}{$queryPart} HTTP/1.1\n";
        $cmd .= "Host: {$this->host}:{$this->port}\n";
        $cmd .= "User-Agent: eZ Components Search\n";
        $cmd .= "Content-Type: text/xml; charset=utf-8\n";
        $cmd .= "\n";

        fwrite( $this->connection, $cmd );

        // read http header
        $line = '';
        $chunked = false;
        while ( $line != "\r\n" )
        {
            $line = $this->getLine();
            if ( preg_match( '@HTTP[^ ]+ +([0-9]{3}) .*@', $line, $m ) )
            {
                $statusCode = $m[1];
                $statusMessage = trim( $line );
            }
            if ( preg_match( '@Content-Length: (\d+)@', $line, $m ) )
            {
                $expectedLength = $m[1];
            }

            if ( preg_match( '@Transfer-Encoding: chunked@', $line ) )
            {
                $chunked = true;
            }
        }

        $data = '';
        $chunkLength = -1;
        // read http content with chunked encoding
        if ( $chunked )
        {
            while ( $chunkLength !== 0 )
            {
                // fetch chunk length
                $line = $this->getLine();
                $chunkLength = hexdec( $line );

                $size = 1;
                while ( $size < $chunkLength )
                {
                    $line = $this->getLine( $chunkLength );
                    $size += strlen( $line );
                    $data .= $line;
                }
                $line = $this->getLine();
            }
        }
        else // without chunked encoding
        {
            $size = 1;
            while ( $size < $expectedLength )
            {
                $line = $this->getLine( $expectedLength );
                $size += strlen( $line );
                $data .= $line;
            }
        }

        // check http status code
        if ( $statusCode >= 400 )
        {
            // Something went wrong.
            throw new ezcSearchNetworkException( "The HTTP server reported: $statusMessage", $data );
        }

        return $data;
    }

    /**
     * Sends a post command $type to Solr and reads the result
     *
     * @param string $type
     * @param array(string=>string) $queryString
     * @param string $data
     * @return string
     * @access private
     */
    public function sendRawPostCommand( $type, $queryString, $data )
    {
        $this->connect();
        
        $statusCode = 0;
        $reConnect = false;
        $queryPart = '';
        if ( count( $queryString ) )
        {
            $queryPart = '/?'. $this->httpBuildQuery( $queryString );
        }
        $length = strlen( $data );
        $cmd =  "POST {$this->location}/{$type}{$queryPart} HTTP/1.1\n";
        $cmd .= "Host: {$this->host}:{$this->port}\n";
        $cmd .= "User-Agent: eZ Components Search\n";
        $cmd .= "Content-Type: text/xml; charset=utf-8\n";
        $cmd .= "Content-Length: $length\n";
        $cmd .= "\n";
        $cmd .= $data;

        fwrite( $this->connection, $cmd );

        // read http header
        $line = '';
        $chunked = false;
        while ( $line != "\r\n" )
        {
            $line = $this->getLine();
            if ( preg_match( '@HTTP[^ ]+ +([0-9]{3}) .*@', $line, $m ) )
            {
                $statusCode = $m[1];
                $statusMessage = trim( $line );
            }
            if ( preg_match( '@Content-Length: (\d+)@', $line, $m ) )
            {
                $expectedLength = $m[1];
            }
            if ( preg_match( '@Connection: close@', $line ) )
            {
                $reConnect = true;
            }
            if ( preg_match( '@Transfer-Encoding: chunked@', $line ) )
            {
                $chunked = true;
            }
        }

        $data = '';
        $chunkLength = -1;
        // read http content with chunked encoding
        if ( $chunked )
        {
            while ( $chunkLength !== 0 )
            {
                // fetch chunk length
                $line = $this->getLine();
                $chunkLength = hexdec( $line );

                $size = 1;
                while ( $size < $chunkLength )
                {
                    $line = $this->getLine( $chunkLength );
                    $size += strlen( $line );
                    $data .= $line;
                }
                $line = $this->getLine();
            }
        }
        else // without chunked encoding
        {
            $size = 1;
            while ( $size < $expectedLength )
            {
                $line = $this->getLine( $expectedLength );
                $size += strlen( $line );
                $data .= $line;
            }
        }

        // reconnect if necessary
        if ( $reConnect )
        {
            $this->reConnect();
        }

        // check http status code
        if ( $statusCode >= 400 )
        {
            // Something went wrong.
            throw new ezcSearchNetworkException( "The HTTP server reported: $statusMessage", $data );
        }

        return $data;
    }

    /**
     * Builds query parameters from the different query fields
     *
     * @param string $queryWord
     * @param string $defaultField
     * @param array(string=>string) $searchFieldList
     * @param array(string=>string) $returnFieldList
     * @param array(string=>string) $highlightFieldList
     * @param array(string=>string) $facetFieldList
     * @param int    $limit
     * @param int    $offset
     * @param array(string=>string) $order
     * @return array
     */
    private function buildQuery( $queryWord, $defaultField, $searchFieldList = array(), $returnFieldList = array(), $highlightFieldList = array(), $facetFieldList = array(), $limit = null, $offset = false, $order = array(),$filterWord = array(), $optionalFlags = array() )
    {
        $queryFlags = array( 'wt' => 'json', 'df' => $defaultField,'q.alt' => '*:*','defType' => 'dismax' );

        if ( count( $searchFieldList ) > 0 )
        {
            $queryFlags['qf'] = implode(' ',$searchFieldList);
        }

        if(!empty($queryWord))
        {
            $queryFlags['q'] = $queryWord;
        }

        $returnFieldList[] = 'score';
        $queryFlags['fl'] = join( ' ', $returnFieldList );

        if ( count( $highlightFieldList ) )
        {
            $queryFlags['hl'] = 'true';
            $queryFlags['hl.snippets'] = 3;
            $queryFlags['hl.fl'] = join( ' ', $highlightFieldList );
            $queryFlags['hl.simple.pre'] = '<b>';
            $queryFlags['hl.simple.post'] = '</b>';
        }
        if ( count( $facetFieldList ) )
        {
            $queryFlags['facet'] = 'true';
            $queryFlags['facet.mincount'] = 1;
            $queryFlags['facet.sort'] = 'false';
            $queryFlags['facet.field'] = $facetFieldList;
        }
        if ( count( $order ) )
        {
            $sortFlags = array();
            foreach ( $order as $column => $type )
            {
                if ( $type == ezcSearchQueryTools::ASC )
                {
                    $sortFlags[] = "$column asc";
                }
                else
                {
                    $sortFlags[] = "$column desc";
                }
            }
            $queryFlags['sort'] = join( ', ', $sortFlags );
        }
        $queryFlags['start'] = $offset;
        $queryFlags['rows'] = $limit === null ? 999999 : $limit;
        if ( count($filterWord) )
        {
            $queryFlags['fq'] = $filterWord;
        }

        if( count($optionalFlags) ) $queryFlags = array_merge($optionalFlags,$queryFlags);

        return $queryFlags;
    }

    private function createDataForHit( $document, $def )
    {
        $className = $def->documentType;
        $obj = new $className;

        $attr = array();
        foreach ( $def->fields as $field )
        {
            $fieldName = $this->mapFieldType( $field->field, $field->type,$field->multi );
            if ( $field->inResult && isset( $document->$fieldName ) )
            {
                $attr[$field->field] = $this->mapFieldValuesForReturn( $field, $document->$fieldName );
            }
        }
        $obj->setState( $attr );
        return new ezcSearchResultDocument( $document->score, $obj );
    }

    private function httpBuildQuery($query)
    {
        $queryString = array();
        foreach($query as $key => $value){
            // Multiple values for the same key should be duplicated in the query string
            if(is_array($value)){
                foreach($value as $subValue)
                    $queryString[] = http_build_query(array($key=>$subValue));

                unset($query[$key]);
            }
        }

        if(!empty($query))
            $queryString[] = http_build_query($query);

        return implode("&",$queryString);
    }
    /**
     * Converts a raw solr result into a document using the definition $def
     *
     * @param ezcSearchDocumentDefinition $def
     * @param mixed $response
     * @return ezcSearchResult
     */
    private function createResponseFromData( ezcSearchDocumentDefinition $def, $response )
    {
        if ( is_string( $response ) )
        {
            // try to find the error message and return that
            $s = new ezcSearchResult();

            $dom = new DomDocument();
            @$dom->loadHtml( $response );
            $tbody = $dom->getElementsByTagName( 'body' )->item( 0 );

            $xpath = new DOMXPath($dom);
            $tocElem = $xpath->evaluate( '//pre', $tbody )->item( 0 );
            $error = $tocElem->nodeValue;

            $s->error = $error;
            return $s;
        }
        $s = new ezcSearchResult();
        $s->status = $response->responseHeader->status;
        $s->queryTime = $response->responseHeader->QTime;
        $s->resultCount = $response->response->numFound;
        $s->start = $response->response->start;

        foreach ( $response->response->docs as $document )
        {
            $resultDocument = $this->createDataForHit( $document, $def );

            $s->documents[$resultDocument->document->getId()] = $resultDocument;
        }

        // process highlighting
        if ( isset( $response->highlighting ) && count( $s->documents ) )
        {
            foreach ( $s->documents as $id => $document )
            {
                $document->highlight = array();
                if ( isset( $response->highlighting->$id ) )
                {
                    foreach ( $def->fields as $field )
                    {
                        $fieldName = $this->mapFieldType( $field->field, $field->type,$field->multi );
                        if ( $field->highlight && isset( $response->highlighting->$id->$fieldName ) )
                        {
                            $document->highlight[$field->field] = $response->highlighting->$id->$fieldName;
                        }
                    }
                }
            }
        }

        // process facets
        if ( isset( $response->facet_counts ) && isset( $response->facet_counts->facet_fields ) )
        {
            $facets = $response->facet_counts->facet_fields;
            foreach ( $def->fields as $field )
            {
                $fieldName = $this->mapFieldType( $field->field, $field->type,$field->multi );
                if ( isset( $facets->$fieldName ) )
                {
                    // sigh, stupid array format needs fixing
                    $facetValues = array();
                    $facet = $facets->$fieldName;
                    for ( $i = 0; $i < count( $facet ); $i += 2 )
                    {
                        $facetValues[$facet[$i]] = $facet[$i+1];
                    }
                    $s->facets[$field->field] = $facetValues;
                }
            }
        }

        // process facets queries
        if ( isset( $response->facet_counts ) && isset( $response->facet_counts->facet_queries ) )
        {
            $facets = $response->facet_counts->facet_queries;
            foreach ( get_object_vars($facets) as $query => $count )
            {
                $s->facet_queries[$query] = $count;
            }
        }

        // process more like this
        if ( isset( $response->moreLikeThis ) && count( $response->moreLikeThis ))
        {
            foreach($response->moreLikeThis as $key => $documents ) {
                if( isset( $documents->docs )){
                    foreach ( $documents->docs as $document )
                    {
                        $resultDocument = $this->createDataForHit( $document, $def );

                        $s->more_like_this[$key][$resultDocument->document->getId()] = $resultDocument;
                    }
                }
            }
        }

        return $s;
    }

    /**
     * Executes a search by building and sending a query and returns the raw result
     *
     * @param string $queryWord
     * @param string $defaultField
     * @param array(string=>string) $searchFieldList
     * @param array(string=>string) $returnFieldList
     * @param array(string=>string) $highlightFieldList
     * @param array(string=>string) $facetFieldList
     * @param int    $limit
     * @param int    $offset
     * @param array(string=>string) $order
     * @return stdClass
     */
    public function search( $queryWord, $defaultField, $searchFieldList = array(), $returnFieldList = array(), $highlightFieldList = array(), $facetFieldList = array(), $limit = null, $offset = 0, $order = array(),$filterWord = array(), $optionalFlags=array() )
    {
        $result = $this->sendRawGetCommand( 'select', $this->buildQuery( $queryWord, $defaultField, $searchFieldList, $returnFieldList, $highlightFieldList, $facetFieldList, $limit, $offset, $order,$filterWord,$optionalFlags ) );
        if ( ( $data = json_decode( $result ) ) === null )
        {
            throw new ezcSearchInvalidResultException( $result );
        }
        return $data;
    }

    /**
     * Returns 'solr'.
     *
     * @return string
     */
    static public function getName()
    {
        return 'solr';
    }

    /**
     * Creates a search query object with the fields from the definition filled in.
     *
     * @param string $type
     * @param ezcSearchDocumentDefinition $definition
     * @return ezcSearchFindQuery
     */
    public function createFindQuery( $type, ezcSearchDocumentDefinition $definition )
    {
        $query = new ezcSearchQuerySolr( $this, $definition );
        $query->select( 'score' );
        if ( $type )
        {
            $selectFieldNames = array();
            foreach ( $definition->getSelectFieldNames() as $docProp )
            {
                $selectFieldNames[] = $this->mapFieldType( $docProp, $definition->fields[$docProp]->type,$definition->fields[$docProp]->multi );
            }
            $highlightFieldNames = array();
            foreach ( $definition->getHighlightFieldNames() as $docProp )
            {
                $highlightFieldNames[] = $this->mapFieldType( $docProp, $definition->fields[$docProp]->type,$definition->fields[$docProp]->multi );
            }
            $query->select( $selectFieldNames );
            $query->where( $query->eq( 'ezcsearch_type', $type ),true );
            $query->highlight( $highlightFieldNames );
        }
        return $query;
    }

    /**
     * Builds the search query and returns the parsed response
     *
     * @param ezcSearchFindQuery $query
     * @return ezcSearchResult
     */
    public function find( ezcSearchFindQuery $query )
    {
        $queryWord = join( ' ', $query->whereClauses );
        $filterWord = $query->filterClauses;
        $searchFields = $query->searchFields;
        $resultFieldList = $query->resultFields;
        $highlightFieldList = $query->highlightFields;
        $facetFieldList = $query->facets;
        $limit = $query->limit;
        $offset = $query->offset;
        $order = $query->orderByClauses;
        $optionalFlags = $query->optionalFlags;

        $res = $this->search( $queryWord, '', $searchFields, $resultFieldList, $highlightFieldList, $facetFieldList, $limit, $offset, $order,$filterWord, $optionalFlags );
        return $this->createResponseFromData( $query->getDefinition(), $res );
    }

    /**
     * Returns the query as a string for debugging purposes
     *
     * @param ezcSearchQuerySolr $query
     * @return string
     * @ignore
     */
    public function getQuery( ezcSearchQuerySolr $query )
    {
        $queryWord = join( ' ', $query->whereClauses );
        $filterWord = $query->filterClauses;
        $searchFields = $query->searchFields;
        $resultFieldList = $query->resultFields;
        $highlightFieldList = $query->highlightFields;
        $facetFieldList = $query->facets;
        $limit = $query->limit;
        $offset = $query->offset;
        $order = $query->orderByClauses;
        $optionalFlags = $query->optionalFlags;

        return $this->httpBuildQuery( $this->buildQuery( $queryWord, '',$searchFields , $resultFieldList, $highlightFieldList, $facetFieldList, $limit, $offset, $order,$filterWord,$optionalFlags ) );
    }

    /**
     * Returns the query as a string for debugging purposes
     *
     * @param ezcSearchQuerySolr $query
     * @return string
     * @ignore
     */
    public function getDeleteQuery( ezcSearchDeleteQuerySolr $query )
    {
        $queryWord = join( ' AND ', $query->whereClauses );
        $query = "<delete><query>{$queryWord}</query></delete>";

        return $query;
    }

    /**
     * Returns the field name as used by solr created from the field $name and $type.
     *
     * @param string $name
     * @param string $type
     * @return string
     */
    public function mapFieldType( $name, $type,$multi = true )
    {
        $map = array(
            ezcSearchDocumentDefinition::STRING => '_s',
            ezcSearchDocumentDefinition::TEXT => '_t',
            ezcSearchDocumentDefinition::HTML => '_t',
            ezcSearchDocumentDefinition::DATE => '_dt',
            ezcSearchDocumentDefinition::INT => '_l',
            ezcSearchDocumentDefinition::FLOAT => '_d',
            ezcSearchDocumentDefinition::BOOLEAN => '_b',
        );
        return $name . $map[$type].($multi?'':'_sl');
    }

    /**
     * This method prepares a $value before it is passed to the indexer.
     *
     * Depending on the $fieldType the $value is modified so that the indexer understands the value.
     *
     * @param string $fieldType
     * @param mixed $value
     * @return mixed
     */
    public function mapFieldValueForIndex( $fieldType, $value )
    {
        switch ( $fieldType )
        {
            case ezcSearchDocumentDefinition::DATE:
                if ( is_numeric( $value ) )
                {
                    $value = new DateTime( "@$value" );
                }
                else if(!($value instanceof DateTime))
                {
                    try
                    {
                        $value = new DateTime( $value );
                    }
                    catch ( Exception $e )
                    {
                        throw new ezcSearchInvalidValueException( $type, $value );
                    }
                }

                $value->setTimezone(new DateTimeZone('UTC'));
                $value = $value->format('Y-m-d') . 'T' . $value->format('H:i:s') . 'Z';
                break;

            case ezcSearchDocumentDefinition::BOOLEAN:
                $value = $value ? 'true' : 'false';
                break;

            case ezcSearchDocumentDefinition::STRING:
            case ezcSearchDocumentDefinition::TEXT:
            case ezcSearchDocumentDefinition::HTML:
                $value = preg_replace( '/[\x00-\x09\x0B\x0C\x1E\x1F]/', '', $value );
                break;
        }
        return $value;
    }

    /**
     * This method prepares a $value before it is passed to the search handler.
     *
     * Depending on the $fieldType the $value is modified so that the search
     * handler understands the value.
     *
     * @param string $fieldType
     * @param mixed $value
     * @return mixed
     */
    public function mapFieldValueForSearch( $fieldType, $value )
    {
        switch ( $fieldType )
        {
            case ezcSearchDocumentDefinition::STRING:
            case ezcSearchDocumentDefinition::TEXT:
            case ezcSearchDocumentDefinition::HTML:
                $value = trim( $value );
                if ( strpbrk( $value, "\\" ) !== false )
                {
                    $value = str_replace( '\\', '\\\\', $value );
                }
                if ( strpbrk( $value, ' ":' ) !== false )
                {
                    $value = '"' . str_replace( '"', '\"', $value ) . '"';
                }
                break;

            case ezcSearchDocumentDefinition::INT:
            case ezcSearchDocumentDefinition::FLOAT:
                $value = '"' . $value . '"';
                break;

            case ezcSearchDocumentDefinition::DATE:
                if ( is_numeric( $value ) )
                {
                    $value = new DateTime( "@$value" );
                    $value->setTimezone(new DateTimeZone('UTC'));
                    $value = $value->format('Y-m-d') . 'T' . $value->format('H:i:s') . 'Z';
                }
                else if( '*' != $value && 'NOW' != $value)
                {
                    try
                    {
                        $value = new DateTime( $value );
                    }
                    catch ( Exception $e )
                    {
                        throw new ezcSearchInvalidValueException( $type, $value );
                    }
                    $value->setTimezone(new DateTimeZone('UTC'));
                    $value = $value->format('Y-m-d') . 'T' . $value->format('H:i:s') . 'Z';
                }

                break;

            case ezcSearchDocumentDefinition::BOOLEAN:
                $value = ($value ? 'true' : 'false');
                break;
        }
        return $value;
    }

    /**
     * This method prepares a $value before it is passed to the search handler.
     *
     * Depending on the $fieldType the $value is modified so that the search
     * handler understands the value.
     *
     * @param string $fieldType
     * @param mixed $value
     * @return mixed
     */
    public function mapFieldValueForReturn( $fieldType, $value )
    {
        switch ( $fieldType )
        {
            case ezcSearchDocumentDefinition::DATE:
                $value = DateTime::createFromFormat("Y-m-d\TH:i:s\Z",$value,new DateTimeZone("UTC"));
                break;

        }
        return $value;
    }

    /**
     * This method prepares a value or an array of $values before it is passed to the search handler.
     *
     * Depending on the $field the $values is modified so that the search
     * handler understands the value. It will also correctly deal with
     * multi-data fields in the search index.
     *
     * @throws ezcSearchInvalidValueException if an array of values is
     *         submitted, but the field has not been defined as a multi-value field.
     *
     * @param ezcSearchDocumentDefinitionField $field
     * @param mixed $values
     * @return array(mixed)
     */
    public function mapFieldValuesForSearch( $field, $values )
    {
        if ( is_array( $values ) && $field->multi == false )
        {
            throw new ezcSearchInvalidValueException( $field->type, $values, 'multi' );
        }
        if ( !is_array( $values ) )
        {
            $values = array( $values );
        }
        foreach ( $values as &$value )
        {
            $value = $this->mapFieldValueForSearch( $field->type, $value );
        }
        return $values;
    }

    /**
     * This method prepares a value or an array of $values before it is passed to the indexer.
     *
     * Depending on the $field the $values is modified so that the search
     * handler understands the value. It will also correctly deal with
     * multi-data fields in the search index.
     *
     * @throws ezcSearchInvalidValueException if an array of values is
     *         submitted, but the field has not been defined as a multi-value field.
     *
     * @param ezcSearchDocumentDefinitionField $field
     * @param mixed $values
     * @return array(mixed)
     */
    public function mapFieldValuesForIndex( $field, $values )
    {
        if ( is_array( $values ) && $field->multi == false )
        {
            throw new ezcSearchInvalidValueException( $field->type, $values, 'multi' );
        }
        if ( !is_array( $values ) )
        {
            $values = array( $values );
        }
        foreach ( $values as &$value )
        {
            $value = $this->mapFieldValueForIndex( $field->type, $value );
        }
        return $values;
    }

    /**
     * This method prepares a value or an array of $values after it has been returned by search handler.
     *
     * Depending on the $field the $values is modified.  It will also correctly
     * deal with multi-data fields in the search index.
     *
     * @param ezcSearchDocumentDefinitionField $field
     * @param mixed $values
     * @return mixed|array(mixed)
     */
    public function mapFieldValuesForReturn( $field, $values )
    {
        if ( $field->multi )
        {
            foreach ( $values as &$value )
            {
                $value = $this->mapFieldValueForReturn( $field->type, $value );
            }
        }
        else
        {
            $values = $this->mapFieldValueForReturn( $field->type, $values);
        }
        return $values;
    }

    /**
     * Runs a commit command to tell solr we're done indexing.
     */
    protected function runCommit()
    {
        $r = $this->sendRawPostCommand( 'update', array( 'wt' => 'json' ), '<commit/>' );
    }

    /**
     * Indexes the document $document using definition $definition
     *
     * @param ezcSearchDocumentDefinition $definition
     * @param mixed $document
     */
    public function index( ezcSearchDocumentDefinition $definition, $document )
    {
        $xml = new XmlWriter();
        $xml->openMemory();
        $xml->startElement( 'add' );
        $xml->startElement( 'doc' );

        $xml->startElement( 'field' );
        $xml->writeAttribute( 'name', 'ezcsearch_type_s_sl' );
        $xml->text( $definition->documentType );
        $xml->endElement();

        $xml->startElement( 'field' );
        $xml->writeAttribute( 'name', 'id' );
        $xml->text( $document[$definition->idProperty] );
        $xml->endElement();

        foreach ( $definition->fields as $field )
        {
            // Optional field, verifyState should check that we don't have any non optional field not in the document
            if(!array_key_exists($field->field,$document)) continue;

            $value = $this->mapFieldValuesForIndex( $field, $document[$field->field] );
            foreach ( $value as $fieldValue )
            {
                $xml->startElement( 'field' );
                $xml->writeAttribute( 'name', $this->mapFieldType( $field->field, $field->type,$field->multi ) );
                $xml->text( $fieldValue );
                $xml->endElement();
            }
        }
        $xml->endElement();
        $xml->endElement();
        $doc = $xml->outputMemory( true );

        $r = $this->sendRawPostCommand( 'update', array( 'wt' => 'json' ), $doc );
        if ( $this->inTransaction == 0 )
        {
            $this->runCommit();
        }
    }

    /**
     * Creates a delete query object with the fields from the definition filled in.
     *
     * @param string $type
     * @param ezcSearchDocumentDefinition $definition
     * @return ezcSearchDeleteQuerySolr
     */
    public function createDeleteQuery( $type, ezcSearchDocumentDefinition $definition )
    {
        $query = new ezcSearchDeleteQuerySolr( $this, $definition );
        if ( $type )
        {
            $selectFieldNames = array();
            $query->where( $query->eq( 'ezcsearch_type', $type ),true );
        }
        return $query;
    }

    /**
     * Deletes a document by the document's $id
     *
     * If the document with ID $id does not exist, no warning is returned.
     *
     * @param mixed $id
     * @param ezcSearchDocumentDefinition $definition
     */
    public function deleteById( $id, ezcSearchDocumentDefinition $definition )
    {
        $queryString = "<delete><id>{$id}</id></delete>";
        $this->sendRawPostCommand( 'update', null, $queryString );
    }

    /**
     * Deletes documents using the query $query.
     *
     * The $query should be created using {@link createDeleteQuery()}.
     *
     * @throws ezcSearchQueryException
     *         if the delete query failed.
     *
     * @param ezcSearchDeleteQuery $query
     */
    public function delete( ezcSearchDeleteQuery $query )
    {
        $result = $this->sendRawPostCommand( 'update', null, $query->getQuery() );
        $result = json_decode( $result );
    }

    /**
     * Finds a document by the document's $id
     *
     * @throws ezcSearchIdNotFoundException
     *         if the document with ID $id did not exist.
     *
     * @param mixed $id
     * @param ezcSearchDocumentDefinition $definition
     * @return ezcSearchResult
     */
    public function findById( $id, ezcSearchDocumentDefinition $definition )
    {
        $idProperty = $definition->idProperty;
        $fieldName = $this->mapFieldType( $definition->fields[$idProperty]->field, $definition->fields[$idProperty]->type,$definition->fields[$idProperty]->multi );
        $res = $this->search( "{$fieldName}:$id", $fieldName )->response->docs;
        if ( count( $res ) != 1 )
        {
            throw new ezcSearchIdNotFoundException( $id );
        }
        return $this->createDataForHit( $res[0], $definition );
    }
}
?>
