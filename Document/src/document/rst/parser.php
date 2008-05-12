<?php
/**
 * File containing the ezcDocumentRstParser
 *
 * @package Document
 * @version //autogen//
 * @copyright Copyright (C) 2005-2008 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * Parser for RST documents
 * 
 * @package Document
 * @version //autogen//
 * @copyright Copyright (C) 2005-2008 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */
class ezcDocumentRstParser extends ezcDocumentParser
{
    /**
     * Current indentation of a paragraph / lsit item.
     * 
     * @var int
     */
    protected $indentation = 0;

    /**
     * For the special case of dense bullet lists we need to update the
     * indetation right after we created a new paragraph in one action. We
     * store the indetation to update past the paragraph creation in this case
     * in this variable.
     * 
     * @var int
     */
    protected $postIndentation = null;

    /**
     * Array containing simplified shift ruleset
     * 
     * We cannot express the RST syntax as a usual grammar using a BNF. With
     * the pumping lemma for context free grammars [1] you can easily prove,
     * that the word a^n b c^n d e^n is not a context free grammar, and this is
     * what the title definitions are.
     *
     * This structure contains an array with callbacks implementing the shift
     * rules for all tokens. There may be multiple rules for one single token. 
     *
     * The callbacks itself create syntax elements and push them to the
     * document stack. After each push the reduction callbacks will be called
     * for the pushed elements.
     *
     * The array should look like:
     * <code>
     *  array(
     *      WHITESPACE => array(
     *          reductionMethod,
     *          ...
     *      ),
     *      ...
     *  )
     * </code>
     *
     * [1] http://en.wikipedia.org/wiki/Pumping_lemma_for_context-free_languages
     *
     * @var array
     */
    protected $shifts = array(
        ezcDocumentRstToken::WHITESPACE => array(

            // This should always be the last rule in this section: We shift
            // the whitespace, which could not be recognized as something else,
            // as text.
            'shiftWhitespaceAsText',
        ),
        ezcDocumentRstToken::NEWLINE => array(
            'shiftParagraph',
            'updateIndentation',
            'shiftAsWhitespace',
        ),
        ezcDocumentRstToken::BACKSLASH => array(
            'shiftBackslash',
        ),
        ezcDocumentRstToken::SPECIAL_CHARS => array(
            'shiftTitle',
            'shiftTransition',
            'shiftLineBlock',
            'shiftInlineMarkup',
            'shiftReference',
            'shiftAnonymousHyperlinks',
            'shiftExternalReference',
            'shiftBlockquoteAnnotation',
            'shiftBulletList',
            'shiftEnumeratedList',
            'shiftLiteralBlock',
            'shiftComment',
            'shiftAnonymousReference',
            'shiftFieldList',
            'shiftSimpleTable',
            'shiftGridTable',

            // This should always be the last rule in this section: We shift
            // special character groups, which could not be recognized as
            // something else, as text.
            'shiftSpecialCharsAsText',
        ),
        ezcDocumentRstToken::TEXT_LINE => array(
            'shiftEnumeratedList',
            'shiftText',
        ),
        ezcDocumentRstToken::EOF => array(
            'shiftDocument',
        ),
    );

    /**
     * Array containing simplified reduce ruleset
     *
     * We cannot express the RST syntax as a usual grammar using a BNF. This
     * structure implements a pseudo grammar by assigning a number of callbacks
     * for internal methods implementing reduction rules for a detected syntax
     * element.
     *
     * <code>
     *  array(
     *      ezcDocumentRstNode::DOCUMENT => 'reduceDocument'
     *      ...
     *  )
     * </code>
     * 
     * @var array
     */
    protected $reductions = array(
        ezcDocumentRstNode::DOCUMENT            => array( 
//            'reduceParagraph',
            'reduceSection',
        ),
        ezcDocumentRstNode::SECTION             => array(
            'reduceSection',
        ),
        ezcDocumentRstNode::TITLE               => array(
            'reduceTitle',
        ),
        ezcDocumentRstNode::PARAGRAPH           => array(
            'reduceParagraph',
            'reduceBulletListParagraph',
            'reduceEnumeratedListParagraph',
            'reduceBlockquoteAnnotationParagraph',
            'reduceBlockquote',
        ),
        ezcDocumentRstNode::BULLET_LIST         => array(
            'reduceBulletList',
        ),
        ezcDocumentRstNode::ENUMERATED_LIST     => array(
            'reduceEnumeratedList',
        ),

        ezcDocumentRstNode::ANNOTATION          => array(
            'reduceBlockquoteAnnotation',
        ),

        ezcDocumentRstNode::MARKUP_EMPHASIS     => array(
            'reduceMarkup',
        ),
        ezcDocumentRstNode::MARKUP_STRONG       => array(
            'reduceMarkup',
        ),
        ezcDocumentRstNode::MARKUP_INTERPRETED  => array(
            'reduceMarkup',
            'reduceInternalTarget',
        ),
        ezcDocumentRstNode::MARKUP_LITERAL      => array(
            'reduceMarkup',
        ),
        ezcDocumentRstNode::MARKUP_SUBSTITUTION => array(
            'reduceMarkup',
        ),

        ezcDocumentRstNode::REFERENCE           => array(
            'reduceReference',
        ),

        ezcDocumentRstNode::LINK_ANONYMOUS      => array(
            'reduceLink',
        ),
        ezcDocumentRstNode::LINK_REFERENCE      => array(
            'reduceLink',
        ),
    );

    /**
     * List of node types, which can be considered as inline text nodes.
     * 
     * @var array
     */
    protected $textNodes = array(
        ezcDocumentRstNode::TEXT_LINE,
        ezcDocumentRstNode::MARKUP_EMPHASIS,
        ezcDocumentRstNode::MARKUP_STRONG,
        ezcDocumentRstNode::MARKUP_INTERPRETED,
        ezcDocumentRstNode::MARKUP_LITERAL,
        ezcDocumentRstNode::MARKUP_SUBSTITUTION,
        ezcDocumentRstNode::LINK_ANONYMOUS,
        ezcDocumentRstNode::LINK_REFERENCE,
        ezcDocumentRstNode::REFERENCE,
        ezcDocumentRstNode::TARGET,
    );

    /**
     * Contains a list of detected syntax elements.
     *
     * At the end of a successfull parsing process this should only contain one
     * document syntax element. During the process it may contain a list of
     * elements, which are up to reduction.
     *
     * Each element in the stack has to be an object extending from
     * ezcDocumentRstNode, which may again contain any amount such objects.
     * This way an abstract syntax tree is constructed.
     * 
     * @var array
     */
    protected $documentStack = array();

    /**
     * Array with title levels used by the document author in their order.
     * 
     * @var array
     */
    protected $titleLevels = array();

    /**
     * List of builtin directives, which do not aggregate more text the
     * parameters and options. User defined directives always aggregate
     * following indeted text.
     * 
     * @var array
     */
    protected $shortDirectives = array(
        'note',
        'notice',
        'warning',
        'danger',
        'image',
    );

    /**
     * Shift- / reduce-parser for RST token stack
     * 
     * @param array $tokens 
     * @return void
     */
    public function parse( array $tokens )
    {
        /* DEBUG
        echo "\n\nStart parser\n============\n\n";
        // /DEBUG */

        while ( ( $token = array_shift( $tokens ) ) !== null )
        {
            /* DEBUG
            echo "[T] Token: " . ezcDocumentRstToken::getTokenName( $token->type ) . " ({$token->type}) at {$token->line}:{$token->position}.\n";
            // /DEBUG */

            // First shift given token by the defined reduction methods
            foreach ( $this->shifts[$token->type] as $method )
            {
                /* DEBUG
                echo " [S] Try $method\n";
                // /DEBUG */
                if ( ( $node = $this->$method( $token, $tokens ) ) === false )
                {
                    // The shift method cannot handle the token, go to next
                    continue;
                }
                /* DEBUG
                echo " [=> Skip used.\n";
                // /DEBUG */

                if ( $node === true )
                {
                    // The shift method handled the token, but did not return a
                    // new node, we just go to the next token...
                    continue 2;
                }

                // Call reduce methods for nodes as long the reduction methods
                // recreate some node
                $ruleNumber = 0;
                $ruleType = 0;
                do {
                    // Reset the rule counter on changes of the node type
                    if ( $ruleType !== $node->type )
                    {
                        $ruleNumber = 0;
                    }

                    if ( !isset( $this->reductions[$node->type] ) ||
                         !isset( $this->reductions[$node->type][$ruleNumber] ) )
                    {
                        // If there are no reduction rules for the node, just
                        // add it to the stack
                        /* DEBUG
                        echo "  [R] Add '" . ezcDocumentRstNode::getTokenName( $node->type ) . "' to stack (" . ( count( $this->documentStack ) + 1 ) . " elements).\n";
                        // /DEBUG */
            
                        array_unshift( $this->documentStack, $node );

                        // Show current document stack for debugging
                        if ( $node->token !== null )
                        {
                            $doc = new ezcDocumentRstSectionNode( $node->token );
                            $doc->nodes = $this->documentStack;
//                            echo "\nIntermediate document:\n", $doc->dump(), "\n";
                        }

                        break;
                    }

                    $ruleType = $node->type;
                    $reduction = $this->reductions[$node->type][$ruleNumber++];
                    /* DEBUG
                    echo "  [R] Reduce with $reduction.\n";
                    // /DEBUG */
                    $node = $this->$reduction( $node );
                } while ( $node !== null );

                // We found a matching rule, so that we can leave the loop
                break;
            }
        }

        // Check if we successfully reduced the document stack
        if ( ( count( $this->documentStack ) !== 1 ) ||
             ( !( $document = reset( $this->documentStack ) ) instanceof ezcDocumentRstDocumentNode ) )
        {
            $node = isset( $document ) ? $document : reset( $this->documentStack );
            $this->triggerError(    
                ezcDocumentParser::FATAL,
                'Expected end of file, got: ' . ezcDocumentRstNode::getTokenName( $node->type ) . ".",
                null, null, null
            );
        }

        /* DEBUG
        echo "\nResulting document:\n\n", $document->dump(), "\nTest result: ";
        // /DEBUG */
        return $document;
    }

    /**
     * Reenter parser with a list of tokens
     * 
     * Returns a parsed document created from the given tokens. With optional,
     * but default, reindetation of the tokens relative to the first token.
     *
     * @param array $tokens 
     * @param bool $reindent
     * @return ezcDocumentRstDocumentNode
     */
    protected function reenterParser( array $tokens, $reindent = true )
    {
        if ( count( $tokens ) < 1 )
        {
            return array();
        }

        /* DEBUG
        static $c = 0;
        file_put_contents( "tokens-reentered-$c.php", "<?php\n\n return " . var_export( $tokens, true ) . ";\n\n" );
        // /DEBUG */

        // Fix indentation for all cell tokens, as they were a single document.
        if ( $reindent )
        {
            $firstToken = reset( $tokens );
            $offset = $firstToken->position + 
                ( $firstToken->type === ezcDocumentRstToken::WHITESPACE ? 1 : -1 );
            $fixedTokens = array();
            foreach ( $tokens as $nr => $token )
            {
                if ( ( $token->type === ezcDocumentRstToken::WHITESPACE ) &&
                     isset( $tokens[$nr + 1] ) &&
                     ( $tokens[$nr + 1]->type === ezcDocumentRstToken::WHITESPACE ) )
                {
                    // Skip multiple whitespace tokens in a row.
                    continue;
                }

                if ( ( $token->type === ezcDocumentRstToken::WHITESPACE ) &&
                     ( $token->position < $offset ) )
                {
                    if ( strlen( $token->content ) <= 1 )
                    {
                        // Just skip token, completely out of tokens bounds
                        continue;
                    }
                    else
                    {
                        // Shorten starting whitespace token
                        $token = clone $token;
                        $token->position = 0;
                        $token->content = substr( $token->content, 1 );
                        $fixedTokens[] = $token;
                    }
                }
                else
                {
                    $token = clone $token;
                    $token->position -= $offset;
                    $fixedTokens[] = $token;
                }
            }

            // If required add a second newline, if the provided token array does
            // not contain any newlines at the end.
            if ( $token->type !== ezcDocumentRstToken::NEWLINE )
            {
                $fixedTokens[] = new ezcDocumentRstToken( ezcDocumentRstToken::NEWLINE, "\n", null, null );
            }
        }
        else
        {
            $fixedTokens = $tokens;
        }

        $fixedTokens[] = new ezcDocumentRstToken( ezcDocumentRstToken::NEWLINE, "\n", null, null );
        $fixedTokens[] = new ezcDocumentRstToken( ezcDocumentRstToken::EOF, null, null, null );

        /* DEBUG
        file_put_contents( "tokens-reentered-$c-fixed.php", "<?php\n\n return " . var_export( $fixedTokens, true ) . ";\n\n" );
        ++$c;
        // /DEBUG */

        $parser = new ezcDocumentRstParser();
        return $parser->parse( $fixedTokens );
    }

    /**
     * Update the current indentation after each newline.
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return bool
     */
    protected function updateIndentation( ezcDocumentRstToken $token, array &$tokens )
    {
        // Indentation Whitespaces right after a title line are irrelevant and
        // should just be skipped as text, so ignore this rule for them:
        if ( ( isset( $this->documentStack[0] ) ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::TITLE ) )
        {
            return false;
        }

        if ( isset( $tokens[0] ) &&
             ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) )
        {
            // Remove the whitespace from the stack, as it is only for
            // indentation and should not be converted to text.
            $whitespace = array_shift( $tokens );

            if ( isset( $tokens[0] ) &&
                 ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) )
            {
                // This is just a blank line
                /* DEBUG
                echo '   -> Empty line.';
                // /DEBUG */
                return false;
            }

            $indentation = strlen( $whitespace->content );
        }
        elseif ( isset( $tokens[0] ) &&
                 ( $tokens[0]->position > 1 ) )
        {
            // While reparsing table cell contents we may miss some whitespaces
            // and directly get an indented non-whitespace node, which is
            // sufficant to also determine the indetation.
            $indentation = $tokens[0]->position - 1;
            $whitespace = false;
        }
        else
        {
            // No whitespace means an indentation level of 0
            $indentation = 0;
        }

        // This is the special case for dense bullet lists. In case of bullet
        // lists the indentation may also change right between two lines
        // without an additional newline.
        if ( isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) &&
             ( isset( $tokens[0] ) ) &&
             ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
             ( in_array( $tokens[0]->content, array(
                    '*', '-', '+',
                    "\xe2\x80\xa2", "\xe2\x80\xa3", "\xe2\x81\x83"
               ) ) ) &&
             isset( $tokens[1] ) &&
             ( $tokens[1]->type === ezcDocumentRstToken::WHITESPACE ) )
        {
            // We update the indentation in this case and add a paragraph node
            // to close the prior paragraph.
            $this->postIndentation = $indentation;
            /* DEBUG
            echo "   -> Bullet special paragraph update\n";
            echo "   => Updated indentation to {$indentation}.\n";
            // /DEBUG */
            $paragraph = new ezcDocumentRstParagraphNode( $token );
//            $paragraph->indentation = $tokens[0]->position + strlen( $tokens[1]->content );
            /* DEBUG
            echo "   => Paragraph indentation set to {$paragraph->indentation}.\n";
            // /DEBUG */
            return $paragraph;
        }

        // If the current indentation is 0 and the indentation increased, with
        // text line nodes as last items on the stack we got a definition list.
        if ( ( $this->indentation === 0 ) &&
             ( $indentation > $this->indentation ) &&
             ( isset( $this->documentStack[0] ) ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) )
        {
            /* DEBUG
            echo "  => Definition list detected.\n";
            // /DEBUG */
            // Put indetation token back into the token stack.
            if ( $whitespace !== false )
            {
                array_unshift( $tokens, $whitespace );
            }
            return $this->shiftDefinitionList( $token, $tokens );
        }

        // The indentation may only change after we reduced a paragraph. There
        // are other spcial cases, which are handled elsewhere.
        if ( ( $this->indentation !== $indentation ) &&
             isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::PARAGRAPH ) &&
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::BLOCKQUOTE ) &&
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::DIRECTIVE ) &&
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::BULLET_LIST ) &&
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::ENUMERATED_LIST ) )
        {
            $this->triggerError(
                ezcDocumentParser::FATAL,
                "Unexpected indentation change from level {$this->indentation} to {$indentation}.",
                null, $token->line, $token->position
            );
        }
 
        // Otherwise indentation changes are fine, and we just update the
        // current indentation level for later checks
        $this->indentation = $indentation;
        $this->postIndentation = null;
        /* DEBUG
        echo "   => Updated indentation to {$indentation}.\n";
        // /DEBUG */
        return false;
    }

    /**
     * Create new document node
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstDocumentNode
     */
    protected function shiftDocument( ezcDocumentRstToken $token, array &$tokens )
    {
        // If there are any tokens left after the end of the file, something
        // went seriously wrong in the tokenizer.
        if ( count( $tokens ) )
        {
            $this->triggerError(
                ezcDocumentParser::FATAL,
                'Unexpected end of file.',
                null, $token->line, $token->position
            );
        }

        return new ezcDocumentRstDocumentNode();
    }

    /**
     * Escaping of special characters
     *
     * A backslash is used for character escaping, as defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#escaping-mechanism
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTitleNode
     */
    protected function shiftBackslash( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( isset( $tokens[0] ) )
        {
            switch ( $tokens[0]->type )
            {
                case ezcDocumentRstToken::NEWLINE:
                case ezcDocumentRstToken::WHITESPACE:
                    // Escaped whitespace characters are just removed, just
                    // like the backslash itself.
                    array_shift( $tokens );
                    /* DEBUG
                    echo "  -> Remove escaped whitespace.\n";
                    // /DEBUG */
                    return true;

                case  ezcDocumentRstToken::BACKSLASH:
                    // A double backslash results in a backslash text node.
                    $tokens[0]->type = ezcDocumentRstToken::TEXT_LINE;
                    $tokens[0]->escaped = true;
                    /* DEBUG
                    echo "  -> Transformed backslash to text.\n";
                    // /DEBUG */
                    return true;

                case ezcDocumentRstToken::SPECIAL_CHARS:
                case ezcDocumentRstToken::TEXT_LINE:
                    // A special character group is always escaped by a
                    // preceeding backslash
                    if ( strlen( $tokens[0]->content ) > 1 )
                    {
                        // Long special character group, so that we need to
                        // split it up.
                        $newToken = new ezcDocumentRstToken(
                            ezcDocumentRstToken::TEXT_LINE,
                            $tokens[0]->content[0], $tokens[0]->line, $tokens[0]->position
                        );
                        $newToken->escaped = true;

                        // Remove extracted part from old token
                        $tokens[0]->content = substr( $tokens[0]->content, 1 );
                        ++$tokens[0]->position;

                        // Add new token to the beginning of the token stack.
                        /* DEBUG
                        echo "  -> Add new split token.\n";
                        // /DEBUG */
                        array_unshift( $tokens, $newToken );
                        return true;
                    }
                    else
                    {
                        // Just convert token into a simple text node
                        /* DEBUG
                        echo "  -> Transformed token into escaped text.\n";
                        // /DEBUG */
                        $tokens[0]->type = ezcDocumentRstToken::TEXT_LINE;
                        $tokens[0]->escaped = true;
                        return true;
                    }
            }
        }
    }

    /**
     * Create new title node from titles with a top and bottom line
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTitleNode
     */
    protected function shiftTitle( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->position !== 1 ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) )
        {
            // This is not a title line at all
            return false;
        }

        // Handle literal block markers differently, they are followed by two
        // newlines (maybe with whitespaces inbetween).
        if ( ( ( $tokens[1]->type === ezcDocumentRstToken::NEWLINE ) ||
               ( ( $tokens[1]->type === ezcDocumentRstToken::WHITESPACE ) && 
                 ( $tokens[2]->type === ezcDocumentRstToken::NEWLINE ) ) ||
               ( strlen( $tokens[1]->content ) > strlen( $token->content ) ) ) &&
             isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::TEXT_LINE ) )
        {
            // This seems to be something else, like a liteal block marker.
            return false;
        }

        return new ezcDocumentRstTitleNode(
            $token
        );
    }

    /**
     * Shift transistions, which are separators in the document.
     *
     * Transitions are specified here:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#transitions
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTitleNode
     */
    protected function shiftTransition( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->position !== 1 ) ||
             ( $token->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( strlen( $token->content ) < 4 ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ||
             ( !isset( $tokens[1] ) ) ||
             ( $tokens[1]->type !== ezcDocumentRstToken::NEWLINE ) )
        {
            // This is not a transistion
            return false;
        }

        return new ezcDocumentRstTransitionNode(
            $token
        );
    }

    /**
     * Shift line blocks
     *
     * Shift line blocks, which are specified at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#line-blocks
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTitleNode
     */
    protected function shiftLineBlock( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->position !== ( $this->indentation + 1 ) ) ||
             ( $token->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $token->content !== '|' ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE ) )
        {
            // This is not a line block
            return false;
        }

        // Put everything back into the token list, as this makes it easier for
        // us to read
        $lines = array();
        array_unshift( $tokens, $token );
        if ( $this->indentation > 0 )
        {
            array_unshift( $tokens, new ezcDocumentRstToken(
                ezcDocumentRstToken::WHITESPACE,
                str_repeat( ' ', $this->indentation ),
                $token->line, 1
            ) );
        }

        // Each line is introduced by '| ', optionally with the proper current
        // indentation.
        while ( ( ( $this->indentation === 0 ) ||
                  ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) &&
                    ( strlen( $tokens[0]->content ) === $this->indentation ) ) &&
                    ( $tokens[1]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                    ( $tokens[1]->content === '|' ) &&
                    ( ( $tokens[2]->type === ezcDocumentRstToken::WHITESPACE ) ||
                      ( $tokens[2]->type === ezcDocumentRstToken::NEWLINE ) ) ) &&
                ( ( $this->indentation > 0 ) ||
                  ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                    ( $tokens[0]->content === '|' ) &&
                    ( ( $tokens[1]->type === ezcDocumentRstToken::WHITESPACE ) ||
                      ( $tokens[1]->type === ezcDocumentRstToken::NEWLINE ) ) ) ) )
        {
            /* DEBUG
            echo "  -> Next line: {$tokens[0]->line}\n";
            // /DEBUG */
            if ( $this->indentation > 0 )
            {
                // Skip the indentation token, which length has already been
                // checked.
                array_shift( $tokens );
            }

            // Shift the line block marker
            $line = array( array_shift( $tokens ) );

            $whitespace = array_shift( $tokens );
            if ( $whitespace->type === ezcDocumentRstToken::NEWLINE )
            {
                // Properly handle empty line in line blocks.
                /* DEBUG
                echo "   -> Skip empty line.\n";
                // /DEBUG */
                $lines[] = $line;
                continue;
            }
                
            // Remove the leading space from the following whitespace token
            if ( $whitespace->content !== ' ' )
            {
                /* DEBUG
                echo "   -> Shorten indentation.\n";
                // /DEBUG */
                $whitespace->content = substr( $whitespace->content, 1 );
                $line[] = $whitespace;
            }

            // Read all tokens in current line und following lines.
            /* DEBUG
            echo "   -> Read line block line tokens\n";
            // /DEBUG */
            do {
                /* DEBUG
                echo "    -> Read tokens: ";
                // /DEBUG */
                do {
                    $line[] = $token = array_shift( $tokens );
                    /* DEBUG
                    echo ".";
                    // /DEBUG */
                } while ( ( $token->type !== ezcDocumentRstToken::NEWLINE ) &&
                          isset( $tokens[0] ) );
                /* DEBUG
                echo "\n";
                // /DEBUG */

            } while ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) &&
                      ( strlen( $tokens[0]->content ) >= ( $this->indentation + 2 ) ) &&
                      ( array_shift( $tokens ) ) );
            $lines[] = $line;
        }

        // Transform aggregated tokens in proper AST structures
        $node = new ezcDocumentRstLineBlockNode( $token );
        $node->indentation = $this->indentation;
        foreach ( $lines as $line )
        {
            $lineNode = new ezcDocumentRstLineBlockLineNode( array_shift( $line ) );
            foreach ( $line as $token )
            {
                $lineNode->nodes[] = new ezcDocumentRstLiteralNode( $token );
            }
            $node->nodes[] = $lineNode;
        }

        // Rest the indentation and exit
        $this->indentation = 0;
        return $node;
    }

    /**
     * Just keep text as text nodes
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTitleNode
     */
    protected function shiftText( ezcDocumentRstToken $token, array &$tokens )
    {
        return new ezcDocumentRstTextLineNode(
            $token
        );
    }
    
    /**
     * Shift a paragraph node on two newlines
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTitleNode
     */
    protected function shiftParagraph( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( !isset( $tokens[0] ) ||
               ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ) &&
             ( !isset( $tokens[0] ) || !isset( $tokens[1] ) ||
               ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE ) ||
               ( $tokens[1]->type !== ezcDocumentRstToken::NEWLINE ) ) )
        {
            // For now we only check for paragraphs closed with two newlines.
            /* DEBUG
            echo "  -> No following newline.\n";
            // /DEBUG */
            return false;
        }

        // Remove all following newlines except the last one.
        while ( ( isset( $tokens[1] ) &&
                  ( $tokens[1]->type === ezcDocumentRstToken::NEWLINE ) ) ||
                ( isset( $tokens[1] ) && isset( $tokens[2] ) &&
                  ( $tokens[1]->type === ezcDocumentRstToken::WHITESPACE ) &&
                  ( $tokens[2]->type === ezcDocumentRstToken::NEWLINE ) ) )
        {
            array_shift( $tokens );
        }

        return new ezcDocumentRstParagraphNode(
            $token
        );
    }

    /**
     * Detect inline markup
     *
     * As defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftInlineMarkup( ezcDocumentRstToken $token, array &$tokens )
    {
        switch ( $token->content )
        {
            case '*':
                $class = 'ezcDocumentRstMarkupEmphasisNode';
                break;
            case '**':
                $class = 'ezcDocumentRstMarkupStrongEmphasisNode';
                break;
            case '`':
                $class = 'ezcDocumentRstMarkupInterpretedTextNode';
                break;
            case '``':
                $class = 'ezcDocumentRstMarkupInlineLiteralNode';
                break;
            case '|':
                $class = 'ezcDocumentRstMarkupSubstitutionNode';
                break;
            default:
                // The found group of special characters are no inline markup,
                // but maybe just text...
                return false;
        }

        /* DEBUG
        echo "   -> Class: $class\n";
        // /DEBUG */

        // For a user readable list of the following rules, see:
        // http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup

        if ( // Rule 1
             ( ( !isset( $this->documentStack[0] ) ) ||
               ( ( $this->documentStack[0]->token->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                 ( strpos( '\'"([{<-/:_', $this->documentStack[0]->token->content[0] ) !== false ) ) ||
               ( $this->documentStack[0]->token->type === ezcDocumentRstToken::WHITESPACE ) ||
               ( $token->position <= ( $this->indentation + 1 ) ) ) &&
             // Rule 2
             ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE ) &&
             // Rule 5
             ( ( !isset( $this->documentStack[0] ) ) ||
               ( ( ( $this->documentStack[0]->token->content !== '"' ) || ( $tokens[0]->content !== '"' ) ) &&
                 ( ( $this->documentStack[0]->token->content !== '\'' ) || ( $tokens[0]->content !== '\'' ) ) &&
                 ( ( $this->documentStack[0]->token->content !== '(' ) || ( $tokens[0]->content !== ')' ) ) &&
                 ( ( $this->documentStack[0]->token->content !== '[' ) || ( $tokens[0]->content !== ']' ) ) &&
                 ( ( $this->documentStack[0]->token->content !== '{' ) || ( $tokens[0]->content !== '}' ) ) &&
                 ( ( $this->documentStack[0]->token->content !== '<' ) || ( $tokens[0]->content !== '>' ) ) ) ) )
        {
            // Create a markup open tag
            /* DEBUG
            echo "   => Create opening tag: $class\n";
            // /DEBUG */
            return new $class( $token, true );
        }

        // For a user readable list of the following rules, see:
        // http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup

        if ( // Rule 3
             ( isset( $this->documentStack[0] ) ) &&
             ( $this->documentStack[0]->token->type !== ezcDocumentRstToken::WHITESPACE ) &&
             ( $token->position > ( $this->indentation + 1 ) ) &&
             // Rule 4
             ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) ||
               ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
               ( strpos( '\'")]}>-/:.,;!?\\_', $tokens[0]->content[0] ) !== false ) ) )
        {
            // Create a markup close tag
            /* DEBUG
            echo "   => Create closing tag: $class\n";
            // /DEBUG */
            return new $class( $token, false );
        }

        // - Rule 6 is implicitely given by the tokenizer.
        // - Rule 7 is ensured by the escaping rules, defined in the shiftBackslash method.

        // In other preconditions this is no inline markup, but maybe just text.
        return false;
    }

    /**
     * Detect inline markup
     *
     * As defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftAnonymousHyperlinks( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->content !== '__' ) ||
             ( $token->position === 1 ) )
        {
            // __ is the anonymous hyperlink token, skip all other cheks for
            // other special char tokens.
            return false;
        }

        // For a user readable list of the following rules, see:
        // http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
        //
        // For the anonymous hyperlink marker the same rules apply as for a
        // common end marker.
        if ( // Rule 3
             ( $this->documentStack[0]->token->type !== ezcDocumentRstToken::WHITESPACE ) &&
             // Rule 4
             ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) ||
               ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
               ( strpos( '\'")]}>-/:.,;!?\\', $tokens[0]->content[0] ) !== false ) ) )
        {
            // Create a markup close tag
            return new ezcDocumentRstAnonymousLinkNode( $token );
        }

        // In other preconditions this is no inline markup, but maybe just text.
        return false;
    }

    /**
     * Detect reference
     *
     * As defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftReference( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( $token->content !== '_' )
        {
            // __ is the anonymous hyperlink token, skip all other cheks for
            // other special char tokens.
            return false;
        }

        // For a user readable list of the following rules, see:
        // http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
        //
        // For the anonymous hyperlink marker the same rules apply as for a
        // common end marker.
        if ( // Custom rule to detect citation and footnote references
             ( ( $this->documentStack[0]->token->content === ']' ) &&
               ( $this->documentStack[0]->token->type === ezcDocumentRstToken::SPECIAL_CHARS ) ) &&
             // Rule 4
             ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) ||
               ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
               ( strpos( '\'")]}>-/:.,;!?\\', $tokens[0]->content[0] ) !== false ) ) )
        {
            // Create a markup close tag
            return new ezcDocumentRstReferenceNode( $token );
        }

        // In other preconditions this is no inline markup, but maybe just text.
        return false;
    }

    /**
     * Detect inline markup
     *
     * As defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftExternalReference( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( $token->content !== '_' )
        {
            // __ is the anonymous hyperlink token, skip all other cheks for
            // other special char tokens.
            return false;
        }

        // For a user readable list of the following rules, see:
        // http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
        //
        // For the anonymous hyperlink marker the same rules apply as for a
        // common end marker.
        if ( // Rule 3
             ( $this->documentStack[0]->token->type !== ezcDocumentRstToken::WHITESPACE ) &&
             // Rule 4
             ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) ||
               ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
               ( strpos( '\'")]}>-/:.,;!?\\', $tokens[0]->content[0] ) !== false ) ) )
        {
            // Create a markup close tag
            return new ezcDocumentRstExternalReferenceNode( $token );
        }

        // In other preconditions this is no inline markup, but maybe just text.
        return false;
    }

    /**
     * Blockquote annotations
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftBlockquoteAnnotation( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->content !== '--' ) &&
             ( $token->content !== '---' ) &&
             // Also the unicode character form EM-Dash is allowed
             ( $token->content !== "\x20\x14" ) )
        {
            // The special character group is not one of the allowed annotation markers
            return false;
        }

        if ( !isset( $this->documentStack[0] ) ||
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::BLOCKQUOTE ) || 
             ( $this->indentation === 0 ) )
        {
            // Annotations only follow blockquotes.
            /* DEBUG
            echo "   -> Annotation not preceeded by blockquote.\n";
            // /DEBUG */
            return false;
        }

        // The section on blockquote annotations
        // http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#block-quotes
        // does not tell anything about the stuff, which may be used in there.
        // We assume everything is possible like in normal paragraphs. The text
        // is added during blockquote reduction.
        return new ezcDocumentRstBlockquoteAnnotationNode( $token );
    }

    /**
     * Bullet point lists
     *
     * As defined at
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#bullet-lists
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftEnumeratedList( ezcDocumentRstToken $token, array &$tokens )
    {
        // The bullet list should always start at the very beginning of a line
        // / paragraph, so that the char postion should match the current
        // identation level.
        if ( $token->position !== ( $this->indentation + 1 ) )
        {
            /* DEBUG
            echo "   -> Indentation mismatch ({$token->position} <> {$this->indentation})\n";
            // /DEBUG */
            return false;
        }

        // This pattern matches upper and lowercase roman numbers up 4999,
        // normal integers to any limit and alphabetic chracters.
        $enumeratedListPattern = '(^(?:m{0,4}d?c{0,3}l?x{0,3}v{0,3}i{0,3}v?x?l?c?d?m?|M{0,4}D?C{0,3}L?X{0,3}V{0,3}I{0,3}V?X?L?C?D?M?|[1-9]+[0-9]*|[a-z]|[A-Z])$)';

        if ( ( ( $token->type === ezcDocumentRstToken::TEXT_LINE ) &&
               !preg_match( $enumeratedListPattern, $token->content ) ) ||
             ( ( $token->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
               ( $token->content !== '(' ) ) )
        {
            // Nothing like a enumerated list here, exit early.
            /* DEBUG
            echo "   -> Nothing to start a enumerated list item with.\n";
            // /DEBUG */
            return false;
        }

        // Create enumerated list from list items surrounded by parantheses
        if ( ( $token->content === '(' ) &&
             isset( $tokens[0] ) && ( $tokens[0]->type === ezcDocumentRstToken::TEXT_LINE ) &&
             ( preg_match( $enumeratedListPattern, $tokens[0]->content ) ) &&
             isset( $tokens[1] ) && ( $tokens[1]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
             ( $tokens[1]->content === ')' ) &&
             isset( $tokens[2] ) && ( $tokens[2]->type === ezcDocumentRstToken::WHITESPACE ) )
        {
            $text = array_shift( $tokens );
            $char = array_shift( $tokens );
            $whitespace = array_shift( $tokens );

            /* DEBUG
            echo "   => Indentation updated to {$this->indentation}.\n";
            // /DEBUG */
            $this->indentation = $text->position + strlen( $text->content ) + 
                strlen( $whitespace->content ) + strlen( $char->content ) - 1;
            $node = new ezcDocumentRstEnumeratedListNode( $text );
            $node->indentation = $this->indentation;
            return $node;
        }

        // Create enumerated list from list items followed by a parantheses or
        // a dot
        if ( isset( $tokens[0] ) && ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
             ( ( $tokens[0]->content === ')' ) ||
               ( $tokens[0]->content === '.' ) ) &&
             isset( $tokens[1] ) && ( $tokens[1]->type === ezcDocumentRstToken::WHITESPACE ) )
        {
            $text = $token;
            $char = array_shift( $tokens );
            $whitespace = array_shift( $tokens );

            /* DEBUG
            echo "   => Indentation updated to {$this->indentation}.\n";
            // /DEBUG */
            $this->indentation = $text->position + strlen( $text->content ) + 
                strlen( $whitespace->content ) + strlen( $char->content ) - 1;
            $node = new ezcDocumentRstEnumeratedListNode( $text );
            $node->indentation = $this->indentation;
            return $node;
        }

        // No enumerated list type matched
        return false;
    }

    /**
     * Bullet point lists
     *
     * As defined at
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#bullet-lists
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftBulletList( ezcDocumentRstToken $token, array &$tokens )
    {
        // Check if the special character group matches the known bullet list
        // starting characters.
        if ( !in_array( $token->content, array(
                '*', '-', '+',
                "\xe2\x80\xa2", "\xe2\x80\xa3", "\xe2\x81\x83"
            ) ) )
        {
            return false;
        }

        // The bullet list should always start at the very beginning of a line
        // / paragraph, so that the char postion should match the current
        // identation level.
        if ( $token->position !== ( $this->indentation + 1 ) )
        {
            /* DEBUG
            echo "   -> Indentation mismatch ({$token->position} <> {$this->indentation})\n";
            // /DEBUG */
            return false;
        } // /DEBUG */

        // The next token has to be a whitespace, which length also defines the
        // new indentation level.
        if ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE )
        {
            /* DEBUG
            echo "   -> No whitespace.\n";
            // /DEBUG */
            return false;
        }
        $whitespace = array_shift( $tokens );

        // Update indentation level
        // @TODO: There need to be some checks in place before we can really
        // update the indentation
        $this->indentation = $token->position + strlen( $whitespace->content );
        /* DEBUG
        echo "   => Indentation updated to {$this->indentation}.\n";
        // /DEBUG */

        // This seems to be a valid bullet list
        $node = new ezcDocumentRstBulletListNode( $token );
        $node->indentation = $this->indentation;
        return $node;
    }

    /**
     * Just keep text as text nodes
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTextLineNode
     */
    protected function shiftWhitespaceAsText( ezcDocumentRstToken $token, array &$tokens )
    {
        return new ezcDocumentRstTextLineNode(
            $token
        );
    }

    /**
     * Keep the newline as a single whitespace to maintain readability in
     * texts.
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTextLineNode
     */
    protected function shiftAsWhitespace( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) )
        {
            $this->documentStack[0]->token->content .= ' ';
        }

        return false;
    }

    /**
     * Just keep text as text nodes
     * 
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstTextLineNode
     */
    protected function shiftSpecialCharsAsText( ezcDocumentRstToken $token, array &$tokens )
    {
        return new ezcDocumentRstTextLineNode(
            $token
        );
    }

    /**
     * Shift literal block
     *
     * Shift a complete literal block into one node. The behaviour of literal
     * blocks is defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#literal-blocks
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftLiteralBlock( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->content !== '::' ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ||
             ( !isset( $tokens[1] ) ) ||
             ( $tokens[1]->type !== ezcDocumentRstToken::NEWLINE ) )
        {
            // Literal blocks only start by a double colon: '::', and has
            // always to be followed by two newlines (marking a common
            // paragraph).
            return false;
        }

        // Check if we should add a text node to the stack first, including a
        // single colon.
        if ( ( $token->position > 1 ) &&
             ( isset( $this->documentStack[0] ) ) &&
             ( in_array( $this->documentStack[0]->type, $this->textNodes ) ) &&
             ( $this->documentStack[0]->token->type !== ezcDocumentRstToken::WHITESPACE ) )
        {
            array_unshift( $tokens,
                new ezcDocumentRstToken(
                    ezcDocumentRstToken::SPECIAL_CHARS, '::', $token->line, 0
                )
            );

            // Return a new text node first, the new pseudo-token starting the
            // literal block will be handled in the next iteration.
            /* DEBUG
            echo "  => Create new text node, handle the literal block later.\n";
            // /DEBUG */
            return new ezcDocumentRstTextLineNode(
                new ezcDocumentRstToken(
                    ezcDocumentRstToken::TEXT_LINE, ':', $token->line, $token->position, true
                )
            );
        }

        // If the token is onyl preceeded by a textnode we put it back on the
        // token stack an return a paragraph node first, to close the previous
        // paragraph. In the next iteration the literal block will be handled.
        if ( isset( $this->documentStack[0] ) &&
             in_array( $this->documentStack[0]->type, $this->textNodes ) )
        {
            array_unshift( $tokens, $token );
            /* DEBUG
            echo "  => Create a paragraph for the preceeding text stuff first.\n";
            // /DEBUG */
            return new ezcDocumentRstParagraphNode( $token );
        }

        // Skip all empty lines first
        while ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE )
        {
            /* DEBUG
            echo "  -> Skip newline.\n";
            // /DEBUG */
            array_shift( $tokens );
        }

        // Once we got the first line after the literal block start marker, we
        // check for the quoting style
        if ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE )
        {
            // In case of a whitespace indentation token, this is used
            // completely as indentation marker.
            /* DEBUG
            echo "  -> Detected whitespace indetation..\n";
            // /DEBUG */
            $baseIndetation = clone $tokens[0];
        }
        elseif ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS )
        {
            // In case of special characters we expect each line to start with
            // the same single character, while the original content is
            // preserved.
            /* DEBUG
            echo "  -> Detected special character indetation..\n";
            // /DEBUG */
            $baseIndetation = new ezcDocumentRstToken(
                ezcDocumentRstToken::SPECIAL_CHARS,
                $tokens[0]->content[0], $tokens[0]->line, $tokens[0]->position
            );
        }
        else
        {
            // In other case we got something unexpected.
            return false;
        }

        $collected = array();
        while ( // Empty lines are inlcuded.
                ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
                // All other lines must start with the determined base
                // indentation
                ( ( $tokens[0]->type === $baseIndetation->type ) &&
                  ( strpos( $tokens[0]->content, $baseIndetation->content ) === 0 ) ) )
        {
            $literalToken = array_shift( $tokens );
            if ( $literalToken->type === ezcDocumentRstToken::NEWLINE )
            {
                // Nothing to do for empty lines, but they are included in the
                // literal block.
                /* DEBUG
                echo "  -> Collected plain newline.\n";
                // /DEBUG */
                $collected[] = new ezcDocumentRstLiteralNode( $literalToken );
                continue;
            }

            if ( $baseIndetation->type === ezcDocumentRstToken::WHITESPACE )
            {
                // Remove whitespaces used for indentation in literal blocks
                /* DEBUG
                echo "  -> Remove whitespace indentation.\n";
                // /DEBUG */
                $literalToken->content = substr( $literalToken->content, strlen( $baseIndetation->content ) );
            }

            $collected[] = new ezcDocumentRstLiteralNode( $literalToken );
            
            // Just collect everything until we reach a newline, the the check
            // starts again.
            /* DEBUG
            echo "  -> Collect: ";
            // /DEBUG */
            do {
                /* DEBUG
                echo ".";
                // /DEBUG */
                $collected[] = new ezcDocumentRstLiteralNode( $item = array_shift( $tokens ) );
            }
            while ( $item->type !== ezcDocumentRstToken::NEWLINE );
            /* DEBUG
            echo "\n";
            // /DEBUG */
        }
        /* DEBUG
        echo "  => Finished collecting.\n";
        // /DEBUG */

        // Readd the last newline to the token stack
        array_unshift( $tokens, $item );

        // Nothing more could be collected, either because the indentation has
        // been reduced, or the markers are missing. Create the literal block
        // node.
        return new ezcDocumentRstLiteralBlockNode( $token, $collected );
    }

    /**
     * Read all token until one of the given tokens occurs
     *
     * Reads all tokens and removes them from the token stack, which do not
     * match of the given tokens. Escaping is maintained.
     * 
     * @param array $tokens 
     * @param array $until 
     * @return array
     */
    protected function readUntil( array &$tokens, array $until )
    {
        $foundTokens = array();
        $found = false;
        do {
            if ( $tokens[0]->type === ezcDocumentRstToken::BACKSLASH )
            {
                $backslash = array_shift( $tokens );
                $this->shiftBackslash( $backslash, $tokens );
            }

            foreach ( $until as $check )
            {
                if ( ( !isset( $check['type'] ) ||
                       ( $tokens[0]->type === $check['type'] ) ) &&
                     ( !isset( $check['content'] ) ||
                       ( $tokens[0]->content === $check['content'] ) ) )
                {
                    $found = true;
                    break 2;
                }
            }

            $foundTokens[] = array_shift( $tokens );
        } while ( $found === false );

        return $foundTokens;
    }

    /**
     * Read multiple lines
     *
     * Reads the content of multiple indented lines, where the indentation can
     * bei either handled strict, or lose, when literal text is expected.
     *
     * Returns an array with the collected tokens, until the indentation
     * changes.
     * 
     * @param array $tokens 
     * @param bool $strict 
     * @return array
     */
    protected function readMutlipleIndentedLines( array &$tokens, $strict = false )
    {
        /* DEBUG
        echo "  -> Read follow up text.\n";
        // /DEBUG */
        $collected = array();
        if ( $tokens[0]->position > ( $this->indentation + 1 ) )
        {
            // The first token is a follow up token to something before. We
            // ignore the indentation here, and read everything in the first
            // line.
            do {
                $collected[] = $token = array_shift( $tokens );
            } while ( $token->type !== ezcDocumentRstToken::NEWLINE );
        }
        /* DEBUG
        echo "  -> Found " . count( $collected ) . " tokens in same line.\n";
        // /DEBUG */

        // Now check for the actual indentation and aggregate everything which
        // stays indented like this.
        if ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE )
        {
            /* DEBUG
            echo "  -> Whitespace indentation.\n";
            // /DEBUG */
            $indentation = clone $tokens[0];
        }
        else
        {
            // We require indenteation here, so return, when the follow up text
            // is not indented at all.
            /* DEBUG
            echo "  -> No indented text.\n";
            // /DEBUG */
            return $collected;
        }

        while ( ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
                ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) &&
                  ( strpos( $tokens[0]->content, $indentation->content ) === 0 ) ) )
        {
            if ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE )
            {
                $collected[] = $token = array_shift( $tokens );
                // Just skip empty lines
                /* DEBUG
                echo "  -> Skip empty line.\n";
                // /DEBUG */
                continue;
            }

            if ( $strict &&
                 ( $tokens[0]->content !== $indentation->content ) )
            {
                $this->triggerError(    
                    ezcDocumentParser::ERROR,
                    'Indentation mismatch.',
                    null, $token->line, $token->position
                );
            }

            // Remove whitespaces used for indentation
            $whitespace = array_shift( $tokens );
            if ( strlen( $whitespace->content ) > ( $inLength = strlen( $indentation->content ) ) )
            {
                $whitespace->content = substr( $whitespace->content, $inLength );
                $collected[] = $whitespace;
            }

            // Read all further nodes until the next newline, and check for
            // indentation again then.
            /* DEBUG
            echo "  -> Collect: ";
            // /DEBUG */
            do {
                /* DEBUG
                echo ".";
                // /DEBUG */
                $collected[] = $token = array_shift( $tokens );
            } while ( $token->type !== ezcDocumentRstToken::NEWLINE );
            /* DEBUG
            echo "\n";
            // /DEBUG */
        }

        // Add last to to stack again, is useful for common reduction handling.
        array_unshift( $tokens, $token );
        /* DEBUG
        echo "  => Collected " . count( $collected ) . " tokens.\n";
        // /DEBUG */
        return $collected;
    }

    /**
     * Shift directives
     *
     * Shift directives as a subaction of the shiftComment method, though the
     * signature differs from the common shift methods.
     *
     * This method aggregated options and parameters of directives, but leaves
     * the content aggregation to the common comment aggregation.
     *
     * @param ezcDocumentRstDirectiveNode $directive
     * @param array $tokens 
     * @return ezcDocumentRstDirectiveNode
     */
    protected function shiftDirective( ezcDocumentRstDirectiveNode $directive, array &$tokens )
    {
        // All nodes until the first newline are the so called parameters of
        // the directive.
        $parameters = '';
        while ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE )
        {
            $token = array_shift( $tokens );
            $parameters .= $token->content;
        }
        /* DEBUG
        echo "  -> Set directive parameter: $parameters\n";
        // /DEBUG */
        $directive->parameters = $parameters;
        array_shift( $tokens );

        // If there are two newlines, there are no options.
        if ( $tokens[0]->type == ezcDocumentRstToken::NEWLINE )
        {
            return $directive;
        }

        // After that there may be options, which are indented and start with a
        // colon.
        while ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) &&
                ( $tokens[1]->content === ':' ) )
        {
            array_shift( $tokens );
            array_shift( $tokens );

            // Extract option name
            $name = '';
            while ( $tokens[0]->content !== ':' )
            {
                $token = array_shift( $tokens );
                $name .= $token->content;
            }
            array_shift( $tokens );

            // Extract option value
            $value = '';
            while ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE )
            {
                $token = array_shift( $tokens );
                $value .= $token->content;
            }

            // Assign option on directive
            array_shift( $tokens );
            $directive->options[$name] = $value;
            /* DEBUG
            echo "  -> Set directive option: $name => $value\n";
            // /DEBUG */
        }

        // Leave everything else up to the comment shifter
        return $directive;
    }

    /**
     * Shift comment
     *
     * Shift comments. Comments are introduced by '..' and just contain text.
     * There are several other block, which are introduced the same way, but
     * where the first token determines the actual type.
     *
     * This method implements the parsing and detection of those different
     * items.
     *
     * Comments are basically described here, but there are crosscutting
     * concerns throughout the complete specification:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#comments
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftComment( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->content !== '..' ) ||
             ( $token->position > 1 ) ||
             ( !isset( $tokens[0] ) ) ||
             ( ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE ) &&
               ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ) ||
             ( isset( $tokens[1] ) &&
               ( $tokens[1]->type === ezcDocumentRstToken::NEWLINE ) ) )
        {
            // All types handled by this method are introduced by a token
            // containing '..' at the very beginning of the line, followed by a
            // whitespace.
            return false;
        }

        // Ignore the following whitespace
        array_shift( $tokens );

        // The next tokens determine which type of structure we found, while
        // everything which is not handled by a special case falls back to a
        // comment.
        $determined = false;
        $substitution = null;
        while ( !$determined )
        {
            switch ( true )
            {
                case $tokens[0]->type === ezcDocumentRstToken::TEXT_LINE:
                    // We may have found a directive. Aggregate the identifier and
                    // check for two colons after that.
                    $identifierTokens = array();
                    $identifier = '';
                    /* DEBUG
                    echo "  -> This may be a directive....\n";
                    // /DEBUG */
                    while ( ( $tokens[0]->type === ezcDocumentRstToken::TEXT_LINE ) ||
                            ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                              ( in_array( $tokens[0]->content[0], array( '-', '_', '.' ) ) ) ) )
                    {
                        $identifierTokens[] = $iToken = array_shift( $tokens );
                        $identifier .= $iToken->content;
                    }

                    // Right after the identifier there should be a double colon,
                    // otherwise this is just a plain comment.
                    if ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                         ( $tokens[0]->content === '::' ) )
                    {
                        /* DEBUG
                        echo "  -> Found directive.\n";
                        // /DEBUG */
                        array_shift( $tokens );
                        $node = new ezcDocumentRstDirectiveNode( $token, strtolower( trim( $identifier ) ) );
                        // The shiftDirective method aggregates options and
                        // parameters of the directive and the contents will be
                        // aggregated later by the common comment functionality.
                        $this->shiftDirective( $node, $tokens );
                        $determined = true;
                    }
                    else
                    {
                        /* DEBUG
                        echo "  -> Just a comment.\n";
                        // /DEBUG */
                        // We create a comment node, where all following contents
                        // may also be aggregated.
                        $node = new ezcDocumentRstCommentNode( $token );
                        $determined = true;

                        // Add tokens used for type detection to the begin of
                        // the comment node.
                        foreach ( $identifierTokens as $subtoken )
                        {
                            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
                        }
                    }
                    break;

                case ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                       ( $tokens[0]->content === '|' ) ):
                    /* DEBUG
                    echo "  -> Found a substitution target.\n";
                    // /DEBUG */
                    // We found a substitution directive. It is identified by the
                    // text between the pipes and the reenters this parsing
                    // process.
                    $name = array_merge(
                        array( array_shift( $tokens ) ),
                        $this->readUntil( $tokens, array( 
                            array(
                                'type' => ezcDocumentRstToken::NEWLINE,
                            ),
                            array(
                                'type' => ezcDocumentRstToken::SPECIAL_CHARS,
                                'content' => '|',
                            ),
                        ) )
                    );

                    // Right after the identifier there should be a double colon,
                    // otherwise this is just a plain comment.
                    if ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                         ( $tokens[0]->content === '|' ) )
                    {
                        $name[] = array_shift( $tokens );
                        /* DEBUG
                        echo "  -> Substitution target successfully found.\n";
                        // /DEBUG */
                        $substitution = new ezcDocumentRstSubstitutionNode( $token, array_slice( $name, 1, -1 ) );
                        // After we found a substitution directive, we reenter
                        // the process to find a associated directive.
                        
                        // Skip following whitespace
                        if ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE )
                        {
                            array_shift( $tokens );
                        }
                    }
                    else
                    {
                        /* DEBUG
                        echo "  -> Just a comment.\n";
                        // /DEBUG */
                        // We create a comment node, where all following contents
                        // may also be aggregated.
                        $node = new ezcDocumentRstCommentNode( $token );
                        $determined = true;

                        // Add tokens used for type detection to the begin of
                        // the comment node.
                        foreach ( $name as $subtoken )
                        {
                            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
                        }
                    }
                    break;

                case ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                       ( $tokens[0]->content === '[' ) ):
                    /* DEBUG
                    echo "  -> Found a potential footnote target.\n";
                    // /DEBUG */
                    // We found a substitution directive. It is identified by the
                    // text between the pipes and the reenters this parsing
                    // process.
                    $name = array_merge(
                        array( array_shift( $tokens ) ),
                        $this->readUntil( $tokens, array( 
                            array(
                                'type' => ezcDocumentRstToken::NEWLINE,
                            ),
                            array(
                                'type' => ezcDocumentRstToken::SPECIAL_CHARS,
                                'content' => ']',
                            ),
                        ) )
                    );

                    // Right after the identifier there should be a double colon,
                    // otherwise this is just a plain comment.
                    if ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                         ( $tokens[0]->content === ']' ) )
                    {
                        $name[] = array_shift( $tokens );
                        /* DEBUG
                        echo "  -> Footnote target successfully found.\n";
                        // /DEBUG */
                        $node = new ezcDocumentRstFootnoteNode( $token, array_slice( $name, 1, -1 ) );
                        // With the name we find the associated contents, which
                        // may span multiple lines, so that this is done by a
                        // seperate method.
                        $content = $this->readMutlipleIndentedLines( $tokens, true );
                        $section = $this->reenterParser( $content );
                        $node->nodes = $section->nodes;

                        // There is nothing more to read. We can exit immediately.
                        return $node;
                    }
                    else
                    {
                        /* DEBUG
                        echo "  -> Just a comment.\n";
                        // /DEBUG */
                        // We create a comment node, where all following contents
                        // may also be aggregated.
                        $node = new ezcDocumentRstCommentNode( $token );
                        $determined = true;

                        // Add tokens used for type detection to the begin of
                        // the comment node.
                        foreach ( $name as $subtoken )
                        {
                            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
                        }
                    }
                    break;

                case ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                       ( $tokens[0]->content === '_' ) ):
                    /* DEBUG
                    echo "  -> Found a named reference target.\n";
                    // /DEBUG */
                    // We found a named reference target. It is identified by a
                    // starting underscrore, followed by the reference name,
                    // and the reference target.
                    array_shift( $tokens );
                    $name = $this->readUntil( $tokens, array( 
                        array(
                            'type' => ezcDocumentRstToken::NEWLINE,
                        ),
                        array(
                            'type' => ezcDocumentRstToken::SPECIAL_CHARS,
                            'content' => ':',
                        ),
                    ) );

                    // Right after the identifier there should be a double colon,
                    // otherwise this is just a plain comment.
                    if ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                         ( $tokens[0]->content === ':' ) )
                    {
                        array_shift( $tokens );
                        array_shift( $tokens );
                        /* DEBUG
                        echo "  -> Named reference target successfully found.\n";
                        // /DEBUG */
                        $node = new ezcDocumentRstNamedReferenceNode( $token, $name );
                        // With the name we find the associated contents, which
                        // may span multiple lines, so that this is done by a
                        // seperate method.
                        $content = $this->readMutlipleIndentedLines( $tokens, true );
                        foreach ( $content as $subtoken )
                        {
                            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
                        }

                        // There is nothing more to read. We can exit immediately.
                        return $node;
                    }
                    else
                    {
                        /* DEBUG
                        echo "  -> Just a comment.\n";
                        // /DEBUG */
                        // We create a comment node, where all following contents
                        // may also be aggregated.
                        $node = new ezcDocumentRstCommentNode( $token );
                        $determined = true;

                        // Add tokens used for type detection to the begin of
                        // the comment node.
                        foreach ( $name as $subtoken )
                        {
                            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
                        }
                    }
                    break;

                case ( ( $tokens[0]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                       ( $tokens[0]->content === '__' ) && 
                       ( isset( $tokens[1] ) ) &&
                       ( $tokens[1]->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                       ( $tokens[1]->content === ':' ) ):
                    /* DEBUG
                    echo "  -> Found a anonymous reference target.\n";
                    // /DEBUG */
                    // We found a anonymous reference target. It is identified
                    // by two starting underscrores, directly followed by a
                    // colon.
                    array_shift( $tokens );
                    array_shift( $tokens );
                    array_shift( $tokens );

                    $node = new ezcDocumentRstAnonymousReferenceNode( $token );
                    // With the name we find the associated contents, which
                    // may span multiple lines, so that this is done by a
                    // seperate method.
                    $content = $this->readMutlipleIndentedLines( $tokens, true );
                    foreach ( $content as $subtoken )
                    {
                        $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
                    }

                    // There is nothing more to read. We can exit immediately.
                    return $node;

                default:
                    // Everything else starting with '..' is just a comment.
                    /* DEBUG
                    echo "  -> Found comment.\n";
                    // /DEBUG */
                    $node = new ezcDocumentRstCommentNode( $token );
                    $determined = true;
                    break;
            }
        }

        // If this is part of a substitution reference, we return the
        // substitution after the process and not just the plain node.
        if ( $substitution !== null )
        {
            $substitution->nodes = array( $node );
            $return = $substitution;
        }
        else
        {
            $return = $node;
        }

        // Check if this is a short directive - in this case we skip the
        // following aggregation and return the directive directly.
        if ( ( $node instanceof ezcDocumentRstDirectiveNode ) &&
             ( in_array( $node->identifier, $this->shortDirectives, true ) ) )
        {
            return $return;
        }

        // Skip all empty lines first
        while ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE )
        {
            /* DEBUG
            echo "  -> Skip newline.\n";
            // /DEBUG */
            array_shift( $tokens );
        }

        // Once we got the first line after the literal block start marker, we
        // check for the quoting style
        if ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE )
        {
            // In case of a whitespace indentation token, this is used
            // completely as indentation marker.
            /* DEBUG
            echo "  -> Detected whitespace indetation..\n";
            // /DEBUG */
            $baseIndetation = clone $tokens[0];
        }
        else
        {
            // If no qouting could be detected, we are finished now, and the
            // comment / directive / ... has no more content.
            return $return;
        }

        // Collect all contents, until the indentation changes.
        $collected = array();
        while ( // Empty lines are inlcuded.
                ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
                // All other lines must start with the determined base
                // indentation
                ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) &&
                  ( strpos( $tokens[0]->content, $baseIndetation->content ) === 0 ) ) )
        {
            $literalToken = array_shift( $tokens );
            if ( $literalToken->type === ezcDocumentRstToken::NEWLINE )
            {
                // Nothing to do for empty lines, but they are included in the
                // literal block.
                /* DEBUG
                echo "  -> Collected plain newline.\n";
                // /DEBUG */
                $collected[] = new ezcDocumentRstLiteralNode( $literalToken );
                continue;
            }

            // Remove whitespaces used for indentation in literal blocks
            /* DEBUG
            echo "  -> Remove whitespace indentation.\n";
            // /DEBUG */
            $literalToken->content = substr( $literalToken->content, strlen( $baseIndetation->content ) );
            $collected[] = new ezcDocumentRstLiteralNode( $literalToken );
            
            // Just collect everything until we reach a newline, the the check
            // starts again.
            /* DEBUG
            echo "  -> Collect: ";
            // /DEBUG */
            do {
                /* DEBUG
                echo ".";
                // /DEBUG */
                $collected[] = new ezcDocumentRstLiteralNode( $item = array_shift( $tokens ) );
            }
            while ( $item->type !== ezcDocumentRstToken::NEWLINE );
            /* DEBUG
            echo "\n";
            // /DEBUG */
        }
        /* DEBUG
        echo "  => Finished collecting.\n";
        // /DEBUG */

        // Readd the last newline to the token stack
        array_unshift( $tokens, $item );

        // Nothing more could be collected, either because the indentation has
        // been reduced, or the markers are missing. Add the aggregated
        // contents to the node and return it.
        $node->nodes = array_merge(
            $node->nodes,
            $collected
        );
        return $node;
    }

    /**
     * Shift anonymous reference target
     *
     * Shift the short version of anonymous reference targets, the long version
     * is handled in the shiftComment() method. Both are specified at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#anonymous-hyperlinks
     * 
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftAnonymousReference( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->content !== '__' ) ||
             ( $token->position !== 1 ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE ) )
        {
            // This does not fulfill the requirements for a short anonymous
            // hyperling reference.
            return false;
        }

        // Shift whitespace
        array_shift( $tokens );

        $node = new ezcDocumentRstAnonymousReferenceNode( $token );
        // With the name we find the associated contents, which
        // may span multiple lines, so that this is done by a
        // seperate method.
        $content = $this->readMutlipleIndentedLines( $tokens, true );
        foreach ( $content as $subtoken )
        {
            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
        }

        // There is nothing more to read. We can exit immediately.
        return $node;
    }

    /**
     * Shift field lists
     *
     * Shift field lists, which are introduced by a term surrounded by columns
     * and any text following. Field lists are specified at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#field-lists
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftFieldList( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->content !== ':' ) ||
             ( $token->position > 1 ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) ||
             ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
             ( isset( $this->documentStack[0] ) &&
               ( in_array( $this->documentStack[0]->type, $this->textNodes ) ) ) )
        {
            // All types handled by this method are introduced by a token
            // containing ':' at the very beginning of the line, followed by
            // text.
            return false;
        }

        $name = $this->readUntil( $tokens, array( 
                array(
                    'type' => ezcDocumentRstToken::NEWLINE,
                ),
                array(
                    'type' => ezcDocumentRstToken::SPECIAL_CHARS,
                    'content' => ':',
                ),
            )
        );

        if ( ( $tokens[0]->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $tokens[0]->content !== ':' ) )
        {
            // Check that the read read stopped at the field list name end
            // marker, otherwise this is just some random text, at least no
            // valid field list.
            $tokens = array_merge( $tokens, $name );
            return false;
        }

        // Ignore the closing ':'.
        array_shift( $tokens );

        // Skip all empty lines before text starts
        while ( ( $tokens[0]->type === ezcDocumentRstToken::NEWLINE ) ||
                ( ( $tokens[0]->type === ezcDocumentRstToken::WHITESPACE ) &&
                  ( $tokens[1]->type === ezcDocumentRstToken::NEWLINE ) ) )
        {
            array_shift( $tokens );
        }

        // Read all text, following the field list name
        $node = new ezcDocumentRstFieldListNode( $token, $name );
        // With the name we find the associated contents, which
        // may span multiple lines, so that this is done by a
        // seperate method.
        $content = $this->readMutlipleIndentedLines( $tokens, true );
        foreach ( $content as $subtoken )
        {
            $node->nodes[] = new ezcDocumentRstLiteralNode( $subtoken );
        }

        // There is nothing more to read. We can exit immediately.
        return $node;
    }

    /**
     * Read simple cells
     *
     * Read cells as defined in simple tables. Cells are maily structured by
     * whitespaces, but may also exceed one cell.
     *
     * Returns an array with cells, ordered by their rows and columns.
     * 
     * @param array $cellStarts 
     * @param array $tokens 
     * @return array
     */
    protected function readSimpleCells( $cellStarts, &$tokens )
    {
        /* DEBUG
        echo "  -> Read simple table cells.";
        // /DEBUG */
        // Two dimensiponal structure with the actual cell contents.
        $cellContents = array();
        $row = -1;
        // Read until we got some kind of definition line.
        while ( ( ( $tokens[0]->position > 1 ) ||
                  ( $tokens[0]->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
                  ( ( $tokens[0]->content[0] !== '=' ) &&
                    ( $tokens[0]->content[0] !== '-' ) ) ||
                  ( !isset( $tokens[1] ) ) ||
                  ( $tokens[1]->type !== ezcDocumentRstToken::WHITESPACE ) ||
                  ( !isset( $tokens[2] ) ) ||
                  ( $tokens[2]->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
                  ( ( $tokens[2]->content[0] !== '=' ) &&
                    ( $tokens[2]->content[0] !== '-' ) ) ) &&
                ( $token = array_shift( $tokens ) ) )
        {
            // Determine column for current content.
            $column = false;
            foreach ( $cellStarts as $nr => $position )
            {
                if ( !isset( $cellStarts[$nr + 1] ) ||
                     ( $cellStarts[$nr + 1] > $token->position ) )
                {
                    $column = $nr;
                    /* DEBUG
                    echo "$column, ";
                    // /DEBUG */
                    break;
                }
            }

            if ( $column === false )
            {
                $column = $nr;
                /* DEBUG
                echo "$column, ";
                // /DEBUG */
            }

            // Increase row number, if the we get non-whitespace content in the
            // first cell
            if ( ( ( $row === -1 ) &&
                   ( $token->position === 1 ) ) ||
                 ( ( $row !== -1 ) &&
                   ( $token->position === 1 ) &&
                   ( $token->type !== ezcDocumentRstToken::WHITESPACE ) &&
                   ( $token->type !== ezcDocumentRstToken::NEWLINE ) ) )
            {
                ++$row;
                /* DEBUG
                echo "\n   -> Row $row: ";
                // /DEBUG */
            }

            // Append contents to column
            $cellContents[$row][$column][] = $token;
        }
        /* DEBUG
        echo "\n";
        // /DEBUG */

        return $cellContents;
    }

    /**
     * Read simple table specifications
     *
     * Read the column specification headers of a simple table and return the
     * sizes of the specified columns in an array.
     * 
     * @param array $tokens 
     * @return array
     */
    protected function readSimpleTableSpecification( &$tokens )
    {
        // Detect the cell sizes inside of the simple table.
        $tableSpec = array();
        /* DEBUG
        echo "  -> Table specification: ";
        // /DEBUG */
        while ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE )
        {
            $specToken = array_shift( $tokens );
            if ( ( ( $specToken->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                   ( ( $specToken->content[0] === '=' ) ||
                     ( $specToken->content[0] === '-' ) ) ) ||
                 ( ( $specToken->type === ezcDocumentRstToken::WHITESPACE ) &&
                   ( strlen( $specToken->content ) > 1 ) ) )
            {
                $tableSpec[] = array( $specToken->type, strlen( $specToken->content ) );
                /* DEBUG
                echo strlen( $specToken->content ), ", ";
                // /DEBUG */
            }
            else
            {
                $this->triggerError(
                    ezcDocumentParser::FATAL,
                    'Invalid token in simple table specifaction.',
                    null, $specToken->line, $specToken->position
                );
            }
        }
        array_shift( $tokens );
        /* DEBUG
        echo "\n";
        // /DEBUG */

        return $tableSpec;
    }

    /**
     * Shift simple table
     *
     * "Simple tables" are not defined by a complete grid, but only by top and
     * bottome lines. There exact specification can be found at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#simple-tables
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftSimpleTable( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->position > 1 ) ||
             ( $token->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $token->content[0] !== '=' ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::WHITESPACE ) ||
             ( !isset( $tokens[1] ) ) ||
             ( $tokens[1]->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $tokens[1]->content[0] !== '=' ) )
        {
            // Missing multiple special character groups only containing '=',
            // separated by whitespaces, which introduce a simple table.
            return false;
        }

        /* DEBUG
        echo "  -> Found simple table.\n";
        // /DEBUG */
        // Detect the cell sizes inside of the simple table.
        array_unshift( $tokens, $token );
        $tableSpec = $this->readSimpleTableSpecification( $tokens );

        // Refactor specification to work with it more easily.
        $cellStarts = array();
        $position = 1;
        foreach ( $tableSpec as $cell )
        {
            if ( $cell[0] === ezcDocumentRstToken::SPECIAL_CHARS )
            {
                $cellStarts[] = $position;
            }
            $position += $cell[1];
        }

        // Read all titles, which may be multiple rows, each sparated by '-'.
        $titles = array();
        do
        {
            $titles = array_merge(
                $titles,
                $this->readSimpleCells( $cellStarts, $tokens )
            );
        } while ( isset( $tokens[0] ) &&
                  ( $tokens[0]->type == ezcDocumentRstToken::SPECIAL_CHARS ) &&
                  ( $tokens[0]->content[0] === '-' ) &&
                  // We ignoe the actual header undeline table cell
                  // redefinition, as we detect this magically while reading
                  // the cells already.
                  $this->readSimpleTableSpecification( $tokens ) );

        // After the titles we get another specification line, which should
        // match the top specification
        if ( $tableSpec !== $this->readSimpleTableSpecification( $tokens ) )
        {
            $this->triggerError(
                ezcDocumentParser::WARNING,
                'Table specification mismatch in simple table.',
                null, $tokens[0]->line, $tokens[0]->position
            );
        }

        // Read actual table contents.
        $contents = $this->readSimpleCells( $cellStarts, $tokens );

        // Last line should also match specification.
        if ( $tableSpec !== $this->readSimpleTableSpecification( $tokens ) )
        {
            $this->triggerError(
                ezcDocumentParser::WARNING,
                'Table specification mismatch in simple table.',
                null, $tokens[0]->line, $tokens[0]->position
            );
        }

        // Reenter parser for table titels and contents, and create table AST
        // from it.
        $table = new ezcDocumentRstTableNode( $token );
        if ( count( $titles ) )
        {
            $table->nodes[] = $head = new ezcDocumentRstTableHeadNode( $token );
            $lastCell = null;
            $lastCNr  = null;
            foreach ( $titles as $rNr => $row )
            {
                $head->nodes[$rNr] = $tableRow = new ezcDocumentRstTableRowNode( $token );

                foreach ( $row as $cNr => $cell )
                {
                    /* DEBUG
                    echo "\n   -> Reenter parser for tokens $rNr, $cNr\n";
                    // /DEBUG */
                    $section = $this->reenterParser( $cell );
                    $tableRow->nodes[$cNr] = $tableCell = new ezcDocumentRstTableCellNode( reset( $cell ) );
                    $tableCell->nodes = $section->nodes;

                    // Set colspan, if required
                    if ( ( $lastCNr !== null ) &&
                         ( $lastCNr < ( $cNr - 1 ) ) )
                    {
                        $lastCell->colspan = $cNr - $lastCNr;
                    }

                    $lastCNr = $cNr;
                    $lastCell = $tableCell;
                }

                $lastCNr = null;
            }
        }

        $table->nodes[] = $body = new ezcDocumentRstTableBodyNode( $token );
        $lastCell = null;
        $lastCNr  = null;
        foreach ( $contents as $rNr => $row )
        {
            $body->nodes[$rNr] = $tableRow = new ezcDocumentRstTableRowNode( $token );

            foreach ( $row as $cNr => $cell )
            {
                /* DEBUG
                echo "\n   -> Reenter parser for tokens $rNr, $cNr\n";
                // /DEBUG */
                $section = $this->reenterParser( $cell );
                $tableRow->nodes[$cNr] = $tableCell = new ezcDocumentRstTableCellNode( reset( $cell ) );
                $tableCell->nodes = $section->nodes;

                // Set colspan, if required
                if ( ( $lastCNr !== null ) &&
                     ( $lastCNr < ( $cNr - 1 ) ) )
                {
                    $lastCell->colspan = $cNr - $lastCNr;
                }

                $lastCNr = $cNr;
                $lastCell = $tableCell;
            }

            $lastCNr = null;
        }

        return $table;
    }

    /**
     * Read grid table specifications
     *
     * Read the column specification headers of a grid table and return the
     * sizes of the specified columns in an array.
     * 
     * @param array $tokens 
     * @return array
     */
    protected function readGridTableSpecification( &$tokens )
    {
        // Detect the cell sizes inside of the simple table.
        $tableSpec = array();
        /* DEBUG
        echo "  -> Table specification: ";
        // /DEBUG */
        while ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE )
        {
            $specToken = array_shift( $tokens );
            if ( ( ( $specToken->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                   ( ( $specToken->content[0] === '=' ) ||
                     ( $specToken->content[0] === '-' ) ) ) ||
                 ( ( $specToken->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                   ( $specToken->content === '+' ) ) )
            {
                if ( $specToken->content === '+' )
                {
                    $tableSpec[] = $specToken->position;
                }
            }
            else
            {
                $this->triggerError(
                    ezcDocumentParser::FATAL,
                    'Invalid token in grid table specifaction.',
                    null, $specToken->line, $specToken->position
                );
            }
        }
        array_shift( $tokens );
        /* DEBUG
        echo "\n";
        // /DEBUG */

        return $tableSpec;
    }

    /**
     * Shift grid table
     *
     * In "Grid tables" the values are embedded in a complete grid visually
     * describing a a table using characters.
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#grid-tables
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftGridTable( ezcDocumentRstToken $token, array &$tokens )
    {
        if ( ( $token->position > 1 ) ||
             ( $token->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $token->content !== '+' ) ||
             ( !isset( $tokens[0] ) ) ||
             ( $tokens[0]->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $tokens[0]->content[0] !== '-' ) ||
             ( !isset( $tokens[1] ) ) ||
             ( $tokens[1]->type !== ezcDocumentRstToken::SPECIAL_CHARS ) ||
             ( $tokens[1]->content !== '+' ) )
        {
            // Missing multiple special character groups only containing '=',
            // separated by whitespaces, which introduce a simple table.
            return false;
        }

        /* DEBUG
        echo "  -> Found grid table.\n";
        // /DEBUG */
        // Detect the cell sizes inside of the grid table.
        $rowOffset = $token->line;
        array_unshift( $tokens, $token );
        $tableSpec = $this->readGridTableSpecification( $tokens );

        // Read all table tokens and extract the complete cell specification of
        // the table.
        $tableTokens = array();
        $titleRow    = 0;
        $cells       = array();
        $row         = 0;
        while ( ( $tableTokens[] = $token = array_shift( $tokens ) ) &&
                // Read until we find two newlines, which indicate the end of
                // the table
                ( ( $token->type !== ezcDocumentRstToken::NEWLINE ) ||
                  ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ) )
        {
            if ( ( $position = array_search( $token->position, $tableSpec, true ) ) !== false )
            {
                /* DEBUG
                echo "    -> Token at cell position: ";
                // /DEBUG */
                // Token may be relevant for the table structure, as it resides
                // at the entry points of the table specification.
                switch ( true )
                {
                    case ( ( $token->content === '+' ) &&
                           ( isset( $tokens[0] ) ) &&
                           ( $tokens[0]->content[0] === '=' ) ):
                        $titleRow = $row;

                    case ( ( $token->content === '+' ) &&
                           ( isset( $tokens[0] ) ) &&
                           ( $tokens[0]->content[0] === '-' ) ):
                        /* DEBUG
                        echo "Row breaker: $position\n";
                        // /DEBUG */
                        $cells[$row][$position] = ( $tokens[0]->content[0] === '-' ? 2 : 3 );
                        break;

                    case ( ( ( $token->content === '|' ) &&
                             ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ) ||
                           ( ( $token->content === '+' ) &&
                             ( $tokens[0]->type !== ezcDocumentRstToken::NEWLINE ) ) ):
                        /* DEBUG
                        echo "Cell: $position\n";
                        // /DEBUG */
                        $cells[$row][$position] = 1;
                        break;

                    default:
                        /* DEBUG
                        echo "irrelevant\n";
                        // /DEBUG */
                }
            }
            elseif ( $token->type === ezcDocumentRstToken::NEWLINE )
            {
                /* DEBUG
                echo "   -> Next row.\n";
                // /DEBUG */
                ++$row;
            }
        }

        // Dump cell structure
        /* DEBUG
        foreach ( $cells as $rNr => $row )
        {
            $lcNr = 0;
            foreach ( $row as $cNr => $cell )
            {
                for ( $i = $lcNr; $i < ( $cNr - 1 ); ++$i ) echo "    ";
                echo ( $cell === 1 ? '|>  ' : ( $cell === 2 ? '----' : '====' ) );
                $lcNr = $cNr;
            }
            echo "\n";
        }
        // /DEBUG */

        // Clean up cell structure: Remove cell seperators, which actually
        // aren't cell seperators because they are not followed or preceeded by
        // cell seperators.
        /* DEBUG
        echo "  -> Clean up cell structure\n";
        // /DEBUG */
        $rowCount = count( $cells );
        foreach ( $cells as $rNr => $row )
        {
            foreach ( $row as $cNr => $cell )
            {
                if ( $cell !== 1 )
                {
                    // Skip everything but cell seperators
                    continue;
                }

                if ( ( $rNr > 0 ) &&
                     ( !isset( $cells[$rNr - 1][$cNr] ) ) )
                {
                    /* DEBUG
                    echo "   -> Remove superflous cell seperator (NP) in $rNr * $cNr\n";
                    // /DEBUG */
                    unset( $cells[$rNr][$cNr] );
                }
                elseif ( ( $rNr < ( $rowCount - 1 ) ) &&
                         ( !isset( $cells[$rNr + 1][$cNr] ) ) )
                {
                    /* DEBUG
                    echo "   -> Remove superflous cell seperator (NF) in $rNr * $cNr\n";
                    // /DEBUG */
                    unset( $cells[$rNr][$cNr] );
                }
            }
        }

        // Dump cell structure
        /* DEBUG
        foreach ( $cells as $rNr => $row )
        {
            $lcNr = 0;
            foreach ( $row as $cNr => $cell )
            {
                for ( $i = $lcNr; $i < ( $cNr - 1 ); ++$i ) echo "    ";
                echo ( $cell === 1 ? '|>  ' : ( $cell === 2 ? '----' : '====' ) );
                $lcNr = $cNr;
            }
            echo "\n";
        }
        // /DEBUG */

        $columnNumber = array();
        $cellMapping  = array();
        $rowCount     = count( $cells );
        $cellCount    = count( $tableSpec ) - 1;

        // Initilize column number array
        for ( $c = 0; $c < $cellCount; ++$c )
        {
            $columnNumber[$c] = 0;
        }

        // Create cell mapping array
        for ( $r = 0; $r < $rowCount; ++$r )
        {
            for ( $c = 0; $c < $cellCount; ++$c )
            {
                if ( !isset( $cells[$r][$c] ) )
                {
                    // No explicit cell definition given. Map to last cell in
                    // current row.
                    $row = $columnNumber[$c];

                    // It may happen for cell seperators, that the last cell is
                    // not available. It is save to skip this case.
                    if ( !isset( $cellMapping[$r][$c - 1] ) )
                    {
                        continue;
                    }

                    $lastCell = $cellMapping[$r][$c - 1];
                    if ( ( $lastCell[0] !== $row ) ||
                         ( $lastCell[1] !== ( $c - 1 ) ) )
                    {
                        // Last cell has already been mapped, use the map
                        // destination from this cell. We do not need to do
                        // this recusively, because we iterate in single steps
                        // over the table and fix each redirection immediately,
                        // so all prior cells already point to their final
                        // location.
                        $cellMapping[$r][$c] = $lastCell;
                    }
                    else
                    {
                        // Otherwise map to last cell
                        $cellMapping[$r][$c] = array( $row, $c - 1 );
                    }
                }
                elseif ( $cells[$r][$c] === 1 )
                {
                    // New cell, just add to mapping table.
                    $cellMapping[$r][$c] = array( $columnNumber[$c], $c );
                }
                elseif ( $cells[$r][$c] > 1 )
                {
                    // We found a row breaker, so increase the future row
                    // number for the current column.
                    //
                    // The increased column number is the maximum of the
                    // current column + 1 and all other columns, because we
                    // want to keep up to a same row number in one row.
                    $columnNumber[$c] = max(
                        $columnNumber[$c] + 1,
                        max( $columnNumber )
                    );
                }
            }
        }

        /* DEBUG
        foreach ( $cellMapping as $rNr => $row )
        {
            echo $rNr, ": ";
            foreach ( $row as $cNr => $cell )
            {
                echo "$cNr(", $cell[0], ", ", $cell[1], ")  ";
            }
            echo "\n";
        }
        // /DEBUG */

        // Iterate over cell mapping array to calculate cell spans
        $cellSpans = array();
        $rNr = 0;
        foreach ( $cellMapping as $nr => $row )
        {
            // Determine maximum row number in current row
            $maxNr = 0;
            foreach ( $row as $cell )
            {
                $maxNr = max( $maxNr, $cell[0] );
            }

            if ( $rNr > $maxNr )
            {
                continue;
            }

            // Increase row and colspan depending on the cell pointer.
            foreach ( $row as $cNr => $cell )
            {
                if ( ( $rNr === $cell[0] ) &&
                     ( $cNr === $cell[1] ) )
                {
                    // It is the cell itself
                    $cellSpans[$cell[0]][$cell[1]] = array( 1, 1 );
                }
                elseif ( $cNr === $cell[1] )
                {
                    // Another cell pointer in the same column
                    $cellSpans[$cell[0]][$cell[1]][0]++;
                }
                elseif ( $rNr === $cell[0] )
                {
                    // Another cell pointer in the same row
                    $cellSpans[$cell[0]][$cell[1]][1]++;
                }
            }

            $rNr = $maxNr + 1;
        }

        // Now we can reiterate over the cell tokens array and assign all
        // tokens to their correct cells.
        $cell     = 0;
        $row      = 0;
        $contents = array();
        $titles   = array();
        $current  = &$titles;
        foreach ( $tableTokens as $token )
        {
            // Newline tokens are only used to skip into the next row, but
            // should also be added to each cell, to maintain the wrapping
            // iside of cells.
            if ( $token->type === ezcDocumentRstToken::NEWLINE )
            {
                if ( $row === $titleRow )
                {
                    // Switch current cell storage to table contents, once we
                    // got past the title row seperator.
                    $current = &$contents;
                }

                // Sppend the newline token to all current cells
                foreach ( $tableSpec as $col => $pos )
                {
                    if ( isset( $cellMapping[$row] ) && isset( $cellMapping[$row][$col] ) )
                    {
                        list( $r, $c ) = $cellMapping[$row][$col];
                        $current[$r][$c][] = $token;
                    }
                }

                ++$row;
                $cell = 0;
                continue;
            }

            // Check if this is a spec token, we want to ignore.
            if ( ( ( $position = array_search( $token->position, $tableSpec, true ) ) !== false ) &&
                 ( isset( $cells[$row][$position] ) ) )
            {
                // Skip spec token.
                continue;
            }

            // Check if entered the next column by checking the current token
            // position as the column offsets in the table spcification.
            if ( $token->position >= $tableSpec[$cell + 1] )
            {
                ++$cell;
            }
            
            // Get the actual destination cell from the table mapping array. If
            // there is no entry in the cell mapping array, the token is a
            // column breaker, and can safely be ignored.
            if ( isset( $cellMapping[$row] ) && isset( $cellMapping[$row][$cell] ) )
            {
                list( $r, $c ) = $cellMapping[$row][$cell];
                $current[$r][$c][] = $token;
            }
        }

        /* DEBUG
        echo "  -> Table contents:\n";
        foreach ( $contents as $rNr => $row )
        {
            echo $rNr, ": ";
            foreach ( $row as $cNr => $cell )
            {
                printf( '% 4d ', count( $cell ) );
            }
            echo "\n";
        }
        // /DEBUG */

        // Reenter parser for table titels and contents, and create table AST
        // from it.
        $table = new ezcDocumentRstTableNode( $token );
        if ( count( $titles ) )
        {
            $table->nodes[] = $head = new ezcDocumentRstTableHeadNode( $token );
            foreach ( $titles as $rNr => $row )
            {
                $head->nodes[$rNr] = $tableRow = new ezcDocumentRstTableRowNode( $token );

                foreach ( $row as $cNr => $cell )
                {
                    /* DEBUG
                    echo "\n   -> Reenter parser for tokens $rNr, $cNr\n";
                    // /DEBUG */
                    $section = $this->reenterParser( $cell );
                    $tableRow->nodes[$cNr] = $tableCell = new ezcDocumentRstTableCellNode( reset( $cell ) );
                    $tableCell->nodes = $section->nodes;
                    $tableCell->rowspan = $cellSpans[$rNr][$cNr][0];
                    $tableCell->colspan = $cellSpans[$rNr][$cNr][1];
                }
            }
        }

        $table->nodes[] = $body = new ezcDocumentRstTableBodyNode( $token );
        foreach ( $contents as $rNr => $row )
        {
            $body->nodes[$rNr] = $tableRow = new ezcDocumentRstTableRowNode( $token );

            foreach ( $row as $cNr => $cell )
            {
                /* DEBUG
                echo "\n   -> Reenter parser for tokens $rNr, $cNr\n";
                // /DEBUG */
                $section = $this->reenterParser( $cell );
                $tableRow->nodes[$cNr] = $tableCell = new ezcDocumentRstTableCellNode( reset( $cell ) );
                $tableCell->nodes = $section->nodes;
                $tableCell->rowspan = $cellSpans[$rNr][$cNr][0];
                $tableCell->colspan = $cellSpans[$rNr][$cNr][1];
            }
        }

        return $table;
    }

    /**
     * Shift definition lists
     *
     * Shift definition lists, which are introduced by an indentation change
     * without speration by a paragraph. Because of this the method is called
     * form the updateIndentation method, which handles such indentation
     * changes.
     *
     * The text for the definition and its classifiers is already on the
     * document stack because of this.
     *
     * Definition lists are specified at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#definition-lists
     *
     * @param ezcDocumentRstToken $token 
     * @param array $tokens 
     * @return ezcDocumentRstMarkupEmphasisNode
     */
    protected function shiftDefinitionList( ezcDocumentRstToken $token, array &$tokens )
    {
        // Fetch definition list back from document stack, where the text nodes
        // are stacked in reverse order.
        $name = array();
        /* DEBUG
        echo "  -> Fetch name from document stack: ";
        // /DEBUG */
        do {
            $node = array_shift( $this->documentStack );
            $name[] = $node->token;
            /* DEBUG
            echo '.';
            // /DEBUG */
        } while ( isset( $this->documentStack[0] ) &&
                  ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) );
        /* DEBUG
        echo "\n";
        // /DEBUG */

        $node = new ezcDocumentRstDefinitionListNode( $token, array_reverse( $name ) );
        // With the name we find the associated contents, which
        // may span multiple lines, so that this is done by a
        // seperate method.
        array_unshift( $tokens, $token );
        /* DEBUG
        echo "  -> Read definition list contents\n";
        // /DEBUG */
        $content = $this->readMutlipleIndentedLines( $tokens, true );
        array_shift( $content );
        $section = $this->reenterParser( $content );
        $node->nodes = $section->nodes;

        // There is nothing more to read. We can exit immediately.
        return $node;
    }
    
    /**
     * Reduce all elements to one document node.
     * 
     * @param ezcDocumentRstTitleNode $node 
     * @return void
     */
    protected function reduceTitle( ezcDocumentRstTitleNode $node )
    {
        if ( !isset( $this->documentStack[0] ) ||
             ( $this->documentStack[0]->type !== ezcDocumentRstNode::TEXT_LINE ) )
        {
            // This is a title top line, just skip for now.
            return $node;
        }

        // Pop all text lines from stack and aggregate them into the title
        $titleText = '';
        while ( ( isset( $this->documentStack[0] ) ) &&
                ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) )
        {
            $textNode = array_shift( $this->documentStack );
            $titleText .= $textNode->token->content;
        }

        // There is one additional whitespace appended because of the newline -
        // remove it:
        $titleText = substr( $titleText, 0, -1 );

        $title = $textNode;
        $title->token->content = $titleText;

        // Check if the lengths of the top line and the text matches.
        if ( strlen( $node->token->content ) !== strlen( $titleText ) )
        {
            $this->triggerError(
                ezcDocumentParser::NOTICE,
                "Title underline length does not match text length.",
                null, $node->token->line, $node->token->position
            );
        }

        // Check if the title has a top line
        $titleType = $node->token->content[0];
        if ( isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::TITLE ) )
        {
            $doubleTitle = array_shift( $this->documentStack );
            $titleType = $doubleTitle->token->content[0] . $titleType;

            // Ensure title over and underline lengths matches, for docutils
            // this is a severe error.
            if ( strlen( $node->token->content ) !== strlen( $doubleTitle->token->content ) )
            {
                $this->triggerError(
                    ezcDocumentParser::WARNING,
                    "Title overline and underline mismatch.",
                    null, $node->token->line, $node->token->position
                );
            }
        }

        // Get section nesting depth for title
        if ( isset( $this->titleLevels[$titleType] ) )
        {
            $depth = $this->titleLevels[$titleType];
        }
        else
        {
            $this->titleLevels[$titleType] = $depth = count( $this->titleLevels ) + 1;
        }

        // Prepend section element to document stack
        return new ezcDocumentRstSectionNode(
            $title->token, $depth
        );
    }

    /**
     * Reduce prior sections, if a new section has been found.
     *
     * If a new section has been found all sections with a higher depth level
     * can be closed, and all items fitting into sections may be aggregated by
     * the respective sections as well.
     * 
     * @param ezcDocumentRstSectionNode $node 
     * @return void
     */
    protected function reduceSection( ezcDocumentRstNode $node )
    {
        // Collected node for prior section
        $collected = array();
        $lastSectionDepth = -1;

        // Include all paragraphs, tables, lists and sections with a higher
        // nesting depth
        while ( $child = array_shift( $this->documentStack ) )
        {
            /* DEBUG
            echo "  -> Try node: " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */
            if ( !in_array( $child->type, array(
                ezcDocumentRstNode::PARAGRAPH,
                ezcDocumentRstNode::BLOCKQUOTE,
                ezcDocumentRstNode::SECTION,
                ezcDocumentRstNode::BULLET_LIST,
                ezcDocumentRstNode::ENUMERATED_LIST,
                ezcDocumentRstNode::TABLE,
                ezcDocumentRstNode::LITERAL_BLOCK,
                ezcDocumentRstNode::COMMENT,
                ezcDocumentRstNode::DIRECTIVE,
                ezcDocumentRstNode::SUBSTITUTION,
                ezcDocumentRstNode::NAMED_REFERENCE,
                ezcDocumentRstNode::FOOTNOTE,
                ezcDocumentRstNode::ANON_REFERENCE,
                ezcDocumentRstNode::TRANSITION,
                ezcDocumentRstNode::FIELD_LIST,
                ezcDocumentRstNode::DEFINITION_LIST,
                ezcDocumentRstNode::LINE_BLOCK,
            ), true ) )
            {
                $this->triggerError(
                    ezcDocumentParser::FATAL,
                    "Unexpected node: " . ezcDocumentRstNode::getTokenName( $child->type ) . ".",
                    null, $child->token->line, $child->token->position
                );
            }

            if ( $child->type === ezcDocumentRstNode::SECTION )
            {
                if ( $child->depth <= $node->depth )
                {
                    $child->nodes = $collected;
                    // If the found section has a same or higher level, just
                    // put it back on the stack
                    array_unshift( $this->documentStack, $child );
                    /* DEBUG
                    echo "   -> Leave on stack.\n";
                    // /DEBUG */
                    return $node;
                }

                if ( ( $lastSectionDepth - $child->depth ) > 1 )
                {
                    $this->triggerError(
                        ezcDocumentParser::FATAL,
                        "Title depth inconsitency.",
                        null, $child->token->line, $child->token->position
                    );
                }

                if ( ( $lastSectionDepth === -1 ) ||
                     ( $lastSectionDepth > $child->depth ) )
                {
                    // If the section level is higher then in our new node and
                    // lower the the last node, reduce sections.
                    /* DEBUG
                    echo "   -> Reduce section {$child->depth}.\n";
                    // /DEBUG */
                    $child->nodes = array_merge( 
                        $child->nodes,
                        $collected
                    );
                    $collected = array();
                }

                // Sections on an equal level are just appended, for all
                // sections we remember the last depth.
                $lastSectionDepth = $child->depth;
            }

            array_unshift( $collected, $child );
        }

        $node->nodes = array_merge(
            $node->nodes,
            $collected
        );
        return $node;
    }

    /**
     * Reduce blockquote annotation content
     *
     * @param ezcDocumentRstParagraphNode $node 
     * @return void
     */
    protected function reduceBlockquoteAnnotationParagraph( ezcDocumentRstNode $node )
    {
        if ( isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::ANNOTATION ) )
        {
            // The last paragraph was preceded by an annotation marker
            $annotation = array_shift( $this->documentStack );
            $annotation->nodes = $node;
            return $annotation;
        }

        return $node;
    }

    /**
     * Reduce blockquote annotation
     *
     * @param ezcDocumentRstParagraphNode $node 
     * @return void
     */
    protected function reduceBlockquoteAnnotation( ezcDocumentRstNode $node )
    {
        // Do not reduce before it is filled with content
        if ( count( $node->nodes ) < 1 )
        {
            return $node;
        }

        // It has already ensured by the shift, that the marker is preceeded by
        // a blockquote.
        $this->documentStack[0]->annotation = $node;
        $this->documentStack[0]->closed = true;
        return null;
    }

    /**
     * Reduce paragraph to blockquote
     *
     * Indented paragraphs are blockquotes, which should be wrapped in such a
     * node.
     * 
     * @param ezcDocumentRstParagraphNode $node 
     * @return void
     */
    protected function reduceBlockquote( ezcDocumentRstNode $node )
    {
        if ( $node->indentation <= 0 )
        {
            // Apply rule only for indented paragraphs.
            return $node;
        }

        // Check last node, if it is already a blockquote, append paragraph
        // there.
        if ( isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::BLOCKQUOTE ) &&
             ( $this->documentStack[0]->closed === false ) )
        {
            // The indentation level of blockquotes should stay the same
            if ( $this->documentStack[0]->indentation !== $node->indentation )
            {
                $this->triggerError(
                    ezcDocumentParser::ERROR,
                    "Indentation level changed between block quotes from {$this->documentStack[0]->indentation} to {$node->indentation}.",
                    null, $node->token->line, $node->token->position
                );
            }

            // Just append paragraph and exit
            $quote = array_shift( $this->documentStack );
            $quote->nodes[] = $node;
            return $quote;
        }

        // Create a new blockquote
        $blockquote = new ezcDocumentRstBlockquoteNode( $node->nodes[0]->token );
        $blockquote->indentation = $node->indentation;
        array_unshift( $blockquote->nodes, $node );
        return $blockquote;
    }

    /**
     * Reduce paragraph to enumerated lists
     *
     * Indented paragraphs are enumerated lists, if prefixed by a enumerated
     * list indicator.
     * 
     * @param ezcDocumentRstParagraphNode $node 
     * @return void
     */
    protected function reduceEnumeratedListParagraph( ezcDocumentRstNode $node )
    {
        $childs = array();
        $lastIndentationLevel = $node->indentation;

        // If this is the very first paragraph, we have nothing we can reduce
        // to, so just skip this rule.
        if ( !count( $this->documentStack ) )
        {
            /* DEBUG
            echo "  => Nothing to reduce to.\n";
            // /DEBUG */
            return $node;
        }

        // Include all paragraphs, lists and blockquotes
        while ( $child = array_shift( $this->documentStack ) )
        {
            if ( !in_array( $child->type, array(
                    ezcDocumentRstNode::PARAGRAPH,
                    ezcDocumentRstNode::BLOCKQUOTE,
                    ezcDocumentRstNode::ENUMERATED_LIST,
                 ), true ) ||
                 ( $child->indentation < $node->indentation ) )
            {
                // We did not find a enumerated list to reduce to, so it is time to
                // put the stuff back to the stack and leave.
                /* DEBUG
                echo "   -> No reduction target found.\n";
                // /DEBUG */
                $this->documentStack = array_merge(
                    $childs,
                    array( $child ),
                    $this->documentStack
                );
                return $node;
            }

            if ( ( $child->type === ezcDocumentRstNode::ENUMERATED_LIST ) &&
                 ( $child->indentation === $node->indentation ) )
            {
                // We found a enumerated list for the current paragraph.
                /* DEBUG
                echo "   => Found matching enumerated list.\n";
                // /DEBUG */
                $child->nodes[] = $node;
                $this->documentStack = array_merge(
                    $childs,
                    array( $child ),
                    $this->documentStack
                );
                return null;
            }

            if ( ( $child->type === ezcDocumentRstNode::ENUMERATED_LIST ) &&
                 ( $child->indentation < $lastIndentationLevel ) )
            {
                // The indentation level reduced during the processing of
                // childs. We can reduce the found childs to the new child with
                // lowest indentation.
                /* DEBUG
                echo "   -> Reduce subgroup (" . count( $childs ) . " items).\n";
                // /DEBUG */
                $child->nodes = array_merge(
                    $child->nodes,
                    array_reverse( $childs )
                );
                $childs = array();
            }

            // Else just append item to curernt child list, and update current
            // indentation.
            /* DEBUG
            echo "   -> Appending " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */
            $childs[] = $child;
            $lastIndentationLevel = $child->indentation;
        }

        // Clean up and return node
        /* DEBUG
        echo "   => Done (" . count( $this->documentStack ) . " elements on stack).\n";
        // /DEBUG */
        return null;
    }

    /**
     * Reduce paragraph to bullet lsit
     *
     * Indented paragraphs are bllet lists, if prefixed by a bullet list
     * indicator.
     * 
     * @param ezcDocumentRstParagraphNode $node 
     * @return void
     */
    protected function reduceBulletListParagraph( ezcDocumentRstNode $node )
    {
        $childs = array();
        $lastIndentationLevel = $node->indentation;

        // If this is the very first paragraph, we have nothing we can reduce
        // to, so just skip this rule.
        if ( !count( $this->documentStack ) )
        {
            /* DEBUG
            echo "  => Nothing to reduce to.\n";
            // /DEBUG */
            return $node;
        }

        // Include all paragraphs, lists and blockquotes
        while ( $child = array_shift( $this->documentStack ) )
        {
            if ( !in_array( $child->type, array(
                    ezcDocumentRstNode::PARAGRAPH,
                    ezcDocumentRstNode::BLOCKQUOTE,
                    ezcDocumentRstNode::BULLET_LIST,
                 ), true ) ||
                 ( $child->indentation < $node->indentation ) )
            {
                // We did not find a bullet list to reduce to, so it is time to
                // put the stuff back to the stack and leave.
                /* DEBUG
                echo "   -> No reduction target found.\n";
                // /DEBUG */
                $this->documentStack = array_merge(
                    $childs,
                    array( $child ),
                    $this->documentStack
                );
                return $node;
            }

            if ( ( $child->type === ezcDocumentRstNode::BULLET_LIST ) &&
                 ( $child->indentation === $node->indentation ) )
            {
                // We found a bullet list for the current paragraph.
                /* DEBUG
                echo "   => Found matching bullet list.\n";
                // /DEBUG */
                $child->nodes[] = $node;
                $this->documentStack = array_merge(
                    $childs,
                    array( $child ),
                    $this->documentStack
                );
                return null;
            }

            if ( ( $child->type === ezcDocumentRstNode::BULLET_LIST ) &&
                 ( $child->indentation < $lastIndentationLevel ) )
            {
                // The indentation level reduced during the processing of
                // childs. We can reduce the found childs to the new child with
                // lowest indentation.
                /* DEBUG
                echo "   -> Reduce subgroup (" . count( $childs ) . " items).\n";
                // /DEBUG */
                $child->nodes = array_merge(
                    $child->nodes,
                    array_reverse( $childs )
                );
                $childs = array();
            }

            // Else just append item to curernt child list, and update current
            // indentation.
            /* DEBUG
            echo "   -> Appending " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */
            $childs[] = $child;
            $lastIndentationLevel = $child->indentation;
        }

        // Clean up and return node
        $this->documentStack = array_merge( 
            array( $node ),
            $childs,
            $this->documentStack
        );
        /* DEBUG
        echo "   => Done (" . count( $this->documentStack ) . " elements on stack).\n";
        // /DEBUG */
        return null;
    }

    /**
     * Reduce item to bullet list
     *
     * Called for all items, which may be part of bullet lists. Depending on
     * the indentation level we reduce some amount of items to a bullet list.
     * 
     * @param ezcDocumentRstBulletListNode $node 
     * @return void
     */
    protected function reduceBulletList( ezcDocumentRstNode $node )
    {
        $childs = array();
        $lastIndentationLevel = 0;

        /* DEBUG
        echo "   - Indentation {$node->indentation}.\n";
        // /DEBUG */

        // Include all paragraphs, lists and blockquotes
        while ( $child = array_shift( $this->documentStack ) )
        {
            if ( !in_array( $child->type, array(
                    ezcDocumentRstNode::PARAGRAPH,
                    ezcDocumentRstNode::BLOCKQUOTE,
                    ezcDocumentRstNode::BULLET_LIST,
                 ), true ) ||
                 ( $child->indentation < $node->indentation ) )
            {
                // We did not find a bullet list to reduce to, so it is time to
                // put the stuff back to the stack and leave.
                /* DEBUG
                echo "   -> No reduction target found.\n";
                // /DEBUG */
                array_unshift( $this->documentStack, $child );
                break;
            }

            if ( ( $child->type === ezcDocumentRstNode::BULLET_LIST ) &&
                 ( $child->indentation === $node->indentation ) )
            {
                // We found a bullet lsit on the same level, so this is a new
                // bullet list item.
                /* DEBUG
                echo "   => Found same level bullet list item.\n";
                // /DEBUG */
                $child->nodes = array_merge( 
                    $child->nodes,
                    $childs
                );

                $this->documentStack = array_merge(
                    array( $child ),
                    $this->documentStack
                );
                
                return $node;
            }

            if ( ( $child->type === ezcDocumentRstNode::BULLET_LIST ) &&
                 ( $child->indentation < $lastIndentationLevel ) )
            {
                // The indentation level reduced during the processing of
                // childs. We can reduce the found childs to the new child with
                // lowest indentation.
                /* DEBUG
                echo "   -> Reduce subgroup (" . count( $childs ) . " items).\n";
                // /DEBUG */
                $child->nodes = array_merge(
                    $child->nodes,
                    array_reverse( $childs )
                );
                $childs = array();
            }

            // Else just append item to curernt child list, and update current
            // indentation.
            /* DEBUG
            echo "   -> Appending " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */
            $childs[] = $child;
            $lastIndentationLevel = $child->indentation;
        }

        // Clean up and return node
        /* DEBUG
        echo "   => Done.\n";
        // /DEBUG */
        $this->documentStack = array_merge(
            $childs,
            $this->documentStack
        );
        return $node;
    }

    /**
     * Reduce item to enumerated list
     *
     * Called for all items, which may be part of enumerated lists. Depending on
     * the indentation level we reduce some amount of items to a enumerated list.
     * 
     * @param ezcDocumentRstEnumeratedListNode $node 
     * @return void
     */
    protected function reduceEnumeratedList( ezcDocumentRstNode $node )
    {
        $childs = array();
        $lastIndentationLevel = 0;

        /* DEBUG
        echo "   - Indentation {$node->indentation}.\n";
        // /DEBUG */

        // Include all paragraphs, lists and blockquotes
        while ( $child = array_shift( $this->documentStack ) )
        {
            if ( !in_array( $child->type, array(
                    ezcDocumentRstNode::PARAGRAPH,
                    ezcDocumentRstNode::BLOCKQUOTE,
                    ezcDocumentRstNode::ENUMERATED_LIST,
                 ), true ) ||
                 ( $child->indentation < $node->indentation ) )
            {
                // We did not find a enumerated list to reduce to, so it is time to
                // put the stuff back to the stack and leave.
                /* DEBUG
                echo "   -> No reduction target found.\n";
                // /DEBUG */
                array_unshift( $this->documentStack, $child );
                break;
            }

            if ( ( $child->type === ezcDocumentRstNode::ENUMERATED_LIST ) &&
                 ( $child->indentation === $node->indentation ) )
            {
                // We found a enumerated lsit on the same level, so this is a new
                // enumerated list item.
                /* DEBUG
                echo "   => Found same level enumerated list item.\n";
                // /DEBUG */
                $child->nodes = array_merge( 
                    $child->nodes,
                    $childs
                );

                $this->documentStack = array_merge(
                    array( $child ),
                    $this->documentStack
                );
                
                return $node;
            }

            if ( ( $child->type === ezcDocumentRstNode::ENUMERATED_LIST ) &&
                 ( $child->indentation < $lastIndentationLevel ) )
            {
                // The indentation level reduced during the processing of
                // childs. We can reduce the found childs to the new child with
                // lowest indentation.
                /* DEBUG
                echo "   -> Reduce subgroup (" . count( $childs ) . " items).\n";
                // /DEBUG */
                $child->nodes = array_merge(
                    $child->nodes,
                    array_reverse( $childs )
                );
                $childs = array();
            }

            // Else just append item to curernt child list, and update current
            // indentation.
            /* DEBUG
            echo "   -> Appending " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */
            $childs[] = $child;
            $lastIndentationLevel = $child->indentation;
        }

        // Clean up and return node
        /* DEBUG
        echo "   => Done.\n";
        // /DEBUG */
        $this->documentStack = array_merge(
            $childs,
            $this->documentStack
        );
        return $node;
    }

    /**
     * Reduce paragraph
     *
     * Aggregates all nodes which are allowed as subnodes into a paragraph.
     * 
     * @param ezcDocumentRstParagraphNode $node 
     * @return void
     */
    protected function reduceParagraph( ezcDocumentRstNode $node )
    {
        $found = 0;

        // Include all paragraphs, tables, lists and sections with a higher
        // nesting depth
        while ( isset( $this->documentStack[0] ) &&
            in_array( $this->documentStack[0]->type, $this->textNodes, true ) )
        {
            // Convert single markup nodes back to text
            $text = array_shift( $this->documentStack );
            if ( in_array( $text->type, array(
                    ezcDocumentRstNode::MARKUP_EMPHASIS,
                    ezcDocumentRstNode::MARKUP_STRONG,
                    ezcDocumentRstNode::MARKUP_INTERPRETED,
                    ezcDocumentRstNode::MARKUP_LITERAL,
                    ezcDocumentRstNode::MARKUP_SUBSTITUTION,
                 ) ) &&
                 ( count( $text->nodes ) < 1 ) )
            {
                $text = new ezcDocumentRstTextLineNode( $text->token );
            }

            /* DEBUG
            echo "   -> Append text to paragraph\n";
            // /DEBUG */
            array_unshift( $node->nodes, $text );
            ++$found;
        }

        if ( $found > 0 )
        {
            $node->indentation = $this->indentation;
            /* DEBUG
            echo "   => Create paragraph with indentation {$node->indentation}\n";
            // /DEBUG */
            $this->indentation = ( $this->postIndentation !== null ? $this->postIndentation : 0 );
            return $node;
        }
    }

    /**
     * Reduce markup
     *
     * Tries to find the opening tag for a markup definition.
     * 
     * @param ezcDocumentRstMarkupNode $node 
     * @return void
     */
    protected function reduceMarkup( ezcDocumentRstNode $node )
    {
        if ( $node->openTag === true )
        {
            // Opening tags are just added to the document stack and we exit
            // the reuction method.
            return $node;
        }

        $childs = array();
        while ( isset( $this->documentStack[0] ) &&
            in_array( $this->documentStack[0]->type, $this->textNodes, true ) )
        {
            $child = array_shift( $this->documentStack );
            if ( ( $child->type == $node->type ) &&
                 ( $child->openTag === true ) )
            {
                /* DEBUG
                echo "   => Found matching tag.\n";
                // /DEBUG */
                // We found the nearest matching open tag. Append all included
                // stuff as child nodes and add the closing tag to the document
                // stack.
                $node->nodes = $childs;
                return $node;
                return;
            }

            /* DEBUG
            echo "     - Collected " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */

            // Append unusable but inline node to potential child list.
            array_unshift( $childs, $child );
        }

        // We did not find an opening node.
        //
        // This is not a parse error, but in this case we just consider the
        // closing node as text and reattach all found childs to the document
        // stack.
        /* DEBUG
        echo "   => Use as Text.\n";
        // /DEBUG */
        $this->documentStack = array_merge(
            $childs,
            $this->documentStack
        );

        return new ezcDocumentRstTextLineNode( $node->token );
    }

    /**
     * Reduce internal target
     *
     * Internal targets are listed before the literal markup block, so it may
     * be found and reduced after we found a markup block.
     * 
     * @param ezcDocumentRstMarkupNode $node 
     * @return void
     */
    protected function reduceInternalTarget( ezcDocumentRstNode $node )
    {
        if ( ( $node->type !== ezcDocumentRstNode::MARKUP_INTERPRETED ) ||
             ( count( $node->nodes ) <= 0 ) )
        {
            // This is a irrelevant markup tags for this rules
            /* DEBUG
            echo "   -> Irrelevant markup.\n";
            // /DEBUG */
            return $node;
        }

        if ( isset( $this->documentStack[0] ) &&
             ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) &&
             ( $this->documentStack[0]->token->content === '_' ) )
        {
            // We found something, create target node and aggregate
            // corresponding nodes.
            $targetTextNode = array_shift( $this->documentStack );
            $target = new ezcDocumentRstTargetNode( $targetTextNode->token );
            $target->nodes = array( $node );
            /* DEBUG
            echo "   -> Found new target.\n";
            // /DEBUG */
            return $target;
        }

        // Otherwise just do nothing and pass the old node through
        /* DEBUG
        echo "   -> Skipped: No match.\n";
        // /DEBUG */
        return $node;
    }

    /**
     * Reduce reference
     *
     * Reduce references as defined at:
     * http://docutils.sourceforge.net/docs/ref/rst/restructuredtext.html#inline-markup
     * 
     * @param ezcDocumentRstMarkupNode $node 
     * @return void
     */
    protected function reduceReference( ezcDocumentRstNode $node )
    {
        // Pop closing brace
        $closing = array_shift( $this->documentStack );

        // Find all childs.
        //
        // May be multiple childs, asthe references may consist of multiple
        // characters with special chars ( *, # ) embedded.
        $childs = array();
        while ( isset( $this->documentStack[0] ) &&
                ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) )
        {
            $child = array_shift( $this->documentStack );
            if ( ( $child->token->type === ezcDocumentRstToken::SPECIAL_CHARS ) &&
                 ( $child->token->content === '[' ) )
            {
                /* DEBUG
                echo "   => Found matching tag.\n";
                // /DEBUG */
                // We found the nearest matching open tag. Append all included
                // stuff as child nodes and add the closing tag to the document
                // stack.
                $node->nodes = $childs;
                return $node;
            }

            /* DEBUG
            echo "     - Collected " . ezcDocumentRstNode::getTokenName( $child->type ) . ".\n";
            // /DEBUG */

            // Append unusable but inline node to potential child list.
            array_unshift( $childs, $child );
        }

        // We did not find an opening node.
        //
        // This is not a parse error, but in this case we just consider the
        // closing node as text and reattach all found childs to the document
        // stack.
        /* DEBUG
        echo "   => Use as Text.\n";
        // /DEBUG */
        $this->documentStack = array_merge(
            array( $closing ),
            $childs,
            $this->documentStack
        );

        return new ezcDocumentRstTextLineNode( $node->token );
    }

    /**
     * Reduce link
     *
     * Uses the preceding element as the hyperlink content. This should be
     * either a literal markup section, or just the last word.
     *
     * As we do not get workd content out of the tokenizer (too much overhead),
     * we split out the previous text node up, in case we got one.
     * 
     * @param ezcDocumentRstMarkupNode $node 
     * @return void
     */
    protected function reduceLink( ezcDocumentRstNode $node )
    {
        if ( !isset( $this->documentStack[0] ) )
        {
            // This should never happen, though.
            return;
        }

        // Check a special case for anonymous hyperlinks, that the beforelast
        // token is not a '__'.
        if ( isset( $this->documentStack[1] ) &&
             ( $this->documentStack[1]->token->content === '__' ) )
        {
            return new ezcDocumentRstTextLineNode( $node->token );
        }

        // If it is a literal node, just use it as link content
        if ( ( $this->documentStack[0]->type === ezcDocumentRstNode::MARKUP_INTERPRETED ) ||
             ( ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE ) &&
               ( strpos( $this->documentStack[0]->token->content, ' ' ) === false ) ) )
        {
            $text = array_shift( $this->documentStack );
            $node->nodes = array( $text );
            return $node;
        }

        if ( $this->documentStack[0]->type === ezcDocumentRstNode::TEXT_LINE )
        {
            // This is bit hackish, but otherwise the tokenizer would produce
            // too large amounts of structs.
            $words = explode( ' ', $this->documentStack[0]->token->content );
            $this->documentStack[0]->token->content = implode( ' ', array_slice( $words, 0, -1 ) );

            $token = clone $this->documentStack[0]->token;
            $token->content = end( $words );
            $text = new ezcDocumentRstTextLineNode( $token );

            $node->nodes = array( $text );
            return $node;
        }

        // We did not find a valid precedessor, so just convert to a text node.
        return new ezcDocumentRstTextLineNode( $node->token );
    }
}

?>