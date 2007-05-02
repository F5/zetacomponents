<?php
/**
 * File containing the ezcWorkflowNode class.
 *
 * @package Workflow
 * @version //autogen//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * Abstract base class for workflow nodes.
 *
 * @package Workflow
 * @version //autogen//
 */
abstract class ezcWorkflowNode implements ezcWorkflowVisitable
{
    /**
     * The node is waiting to be activated.
     */
    const WAITING_FOR_ACTIVATION = 0;

    /**
     * The node is activated and waiting to be executed.
     */
    const WAITING_FOR_EXECUTION = 1;

    /**
     * Unique ID of this node.
     *
     * Only available when the workflow this node belongs to has been loaded
     * from or saved to the data storage.
     *
     * @var integer
     */
    protected $id = false;

    /**
     * The workflow this node belongs to.
     *
     * @var ezcWorkflow
     */
    protected $workflow = null;

    /**
     * The incoming nodes of this node.
     *
     * @var array
     */
    protected $inNodes = array();

    /**
     * The outgoing nodes of this node.
     *
     * @var array
     */
    protected $outNodes = array();

    /**
     * Constraint: The minimum number of incoming nodes this node has to have
     * to be valid. Set to false to disable this constraint.
     *
     * @var integer
     */
    protected $minInNodes = 1;

    /**
     * Constraint: The maximum number of incoming nodes this node has to have
     * to be valid. Set to false to disable this constraint.
     *
     * @var integer
     */
    protected $maxInNodes = 1;

    /**
     * Constraint: The minimum number of outgoing nodes this node has to have
     * to be valid. Set to false to disable this constraint.
     *
     * @var integer
     */
    protected $minOutNodes = 1;

    /**
     * Constraint: The maximum number of outgoing nodes this node has to have
     * to be valid. Set to false to disable this constraint.
     *
     * @var integer
     */
    protected $maxOutNodes = 1;

    /**
     * The number of incoming nodes.
     *
     * @var integer
     */
    protected $numInNodes = 0;

    /**
     * The number of outgoing nodes.
     *
     * @var integer
     */
    protected $numOutNodes = 0;

    /**
     * The configuration of this node.
     *
     * @var mixed
     */
    protected $configuration;

    /**
     * The state of this node.
     *
     * @var integer
     */
    protected $activationState;

    /**
     * The node(s) that activated this node.
     *
     * @var ezcWorkflowNode[]
     */
    protected $activatedFrom = array();

    /**
     * The state of this node.
     *
     * @var mixed
     */
    protected $state;

    /**
     * The id of the thread this node is executing in.
     *
     * @var integer
     */
    protected $threadId = null;

    /**
     * Flag that indicates whether an add*Node() or remove*Node()
     * call is internal.
     *
     * @var boolean
     */
    protected static $internalCall = false;

    /**
     * Constructor.
     *
     * @param mixed   $configuration
     * @param integer $activationState
     * @param mixed   $state
     */
    public function __construct( $configuration = '', $activationState = self::WAITING_FOR_ACTIVATION, $state = null )
    {
        $this->configuration = $configuration;

        if ( $state === null )
        {
            $this->initState();
        }
        else
        {
            $this->state = $state;
        }

        $this->setActivationState( $activationState );
    }

    /**
     * Adds a node to the incoming nodes of this node.
     *
     * @param  ezcWorkflowNode $node The node that is to be added as incoming node.
     * @throws ezcWorkflowInvalidDefinitionException if the operation violates the constraints of the nodes involved.
     * @return ezcWorkflowNode
     */
    public function addInNode( ezcWorkflowNode $node )
    {
        // Check whether the node is already an incoming node of this node.
        if ( ezcWorkflowUtil::findObject( $this->inNodes, $node ) !== false )
        {
            return $this;
        }

        // Check whether adding the other node to the incoming nodes
        // of this node would violate this node's constraints.
        if ( $this->maxInNodes !== FALSE && $this->numInNodes + 1 > $this->maxInNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Adding an incoming node to this node would violate its constraints.'
            );
        }

        // Add the other node to the workflow.
        $this->addNodeToWorkflow( $node );

        // Add this node as an outgoing node to the other node.
        if ( !self::$internalCall )
        {
            self::$internalCall = true;
            $node->addOutNode( $this );
        }
        else
        {
            self::$internalCall = false;
        }

        // Add the other node as an incoming node to this node.
        $this->inNodes[] = $node;
        $this->numInNodes++;

        return $this;
    }

    /**
     * Removes a node from the incoming nodes of this node.
     *
     * @param  ezcWorkflowNode $node The node that is to be removed as incoming node.
     * @throws ezcWorkflowInvalidDefinitionException if the operation violates the constraints of the nodes involved.
     * @return ezcWorkflowNode
     */
    public function removeInNode( ezcWorkflowNode $node )
    {
        $index = ezcWorkflowUtil::findObject( $this->inNodes, $node );

        if ( $index === false )
        {
            return $this;
        }

        if ( $this->minInNodes !== FALSE && $this->numInNodes - 1 < $this->minInNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Removing an incoming node from this node would violate its constraints.'
            );
        }

        // Remove this node as an outgoing node from the other node.
        if ( !self::$internalCall )
        {
            self::$internalCall = true;
            $node->removeOutNode( $this );
        }
        else
        {
            self::$internalCall = false;
        }

        unset( $this->inNodes[$index] );
        $this->numInNodes--;

        return $this;
    }

    /**
     * Adds a node to the outgoing nodes of this node.
     *
     * @param  ezcWorkflowNode $node The node that is to be added as outgoing node.
     * @throws ezcWorkflowInvalidDefinitionException if the operation violates the constraints of the nodes involved.
     * @return ezcWorkflowNode
     */
    public function addOutNode( ezcWorkflowNode $node )
    {
        // Check whether the other node is already an outgoing node of this node.
        if ( ezcWorkflowUtil::findObject( $this->outNodes, $node ) !== false )
        {
            return $this;
        }

        // Check whether adding the other node to the outgoing nodes
        // of this node would violate this node's constraints.
        if ( $this->maxOutNodes !== FALSE && $this->numOutNodes + 1 > $this->maxOutNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Adding an outgoing node to this node would violate its constraints.'
            );
        }

        // Add the other node to the workflow.
        $this->addNodeToWorkflow( $node );

        // Add this node as an incoming node to the other node.
        if ( !self::$internalCall )
        {
            self::$internalCall = true;
            $node->addInNode( $this );
        }
        else
        {
            self::$internalCall = false;
        }

        // Add the other node as an outgoing node to this node.
        $this->outNodes[] = $node;
        $this->numOutNodes++;

        return $this;
    }

    /**
     * Removes a node from the outgoing nodes of this node.
     *
     * @param  ezcWorkflowNode $node The node that is to be removed as outgoing node.
     * @throws ezcWorkflowInvalidDefinitionException if the operation violates the constraints of the nodes involved.
     * @return ezcWorkflowNode
     */
    public function removeOutNode( ezcWorkflowNode $node )
    {
        $index = ezcWorkflowUtil::findObject( $this->outNodes, $node );

        if ( $index === false )
        {
            return $this;
        }

        if ( $this->minOutNodes !== FALSE && $this->numOutNodes - 1 < $this->minOutNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Removing an outgoing node from this node would violate its constraints.'
            );
        }

        // Remove this node as an incoming node from the other node.
        if ( !self::$internalCall )
        {
            self::$internalCall = true;
            $node->removeInNode( $this );
        }
        else
        {
            self::$internalCall = false;
        }

        unset( $this->outNodes[$index] );
        $this->numOutNodes--;

        return $this;
    }

    /**
     * Sets the workflow object this node belongs to.
     *
     * @param ezcWorkflow $workflow The workflow object this node belongs to.
     */
    public function setWorkflow( ezcWorkflow $workflow )
    {
        $this->workflow = $workflow;
        $this->workflow->addNode( $this );
    }

    /**
     * Returns the workflow object this node belongs to.
     *
     * @return ezcWorkflow
     */
    public function getWorkflow()
    {
        return $this->workflow;
    }

    /**
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param integer $id
     */
    public function setId( $id )
    {
        $this->id = $id;
    }

    /**
     * @param integer $state
     */
    public function setActivationState( $activationState )
    {
        if ( $activationState == self::WAITING_FOR_ACTIVATION ||
             $activationState == self::WAITING_FOR_EXECUTION )
        {
            $this->activationState = $activationState;
        }
    }

    /**
     * Returns the incoming nodes of this node.
     *
     * @return ezcWorkflowNode[]
     */
    public function getInNodes()
    {
        return $this->inNodes;
    }

    /**
     * Returns the outgoing nodes of this node.
     *
     * @return ezcWorkflowNode[]
     */
    public function getOutNodes()
    {
        return $this->outNodes;
    }

    /**
     * Returns the configuration of this node.
     *
     * @return mixed
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Returns the state of this node.
     *
     * @return mixed
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Sets the state of this node.
     *
     * @param mixed $state
     */
    public function setState( $state )
    {
        $this->state = $state;
    }

    /**
     * Returns the node(s) that activated this node.
     *
     * @return array
     */
    public function getActivatedFrom()
    {
        return $this->activatedFrom;
    }

    /**
     * Sets the node(s) that activated this node.
     *
     * @param array $activatedFrom
     */
    public function setActivatedFrom( Array $activatedFrom )
    {
        $this->activatedFrom = $activatedFrom;
    }

    /**
     * Returns the id of the thread this node is executing in.
     *
     * @return integer
     */
    public function getThreadId()
    {
        return $this->threadId;
    }

    /**
     * Sets the id of the thread this node is executing in.
     *
     * @param integer $threadId
     */
    public function setThreadId( $threadId )
    {
        $this->threadId = $threadId;
    }

    /**
     * Checks this node's constraints.
     *
     * @throws ezcWorkflowInvalidDefinitionException if the constraints of this node are not met.
     */
    public function verify()
    {
        if ( $this->minInNodes !== false && $this->numInNodes < $this->minInNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Node has less incoming nodes than required.'
            );
        }

        if ( $this->maxInNodes !== false && $this->numInNodes > $this->maxInNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Node has more incoming nodes than allowed.'
            );
        }

        if ( $this->minOutNodes !== false && $this->numOutNodes < $this->minOutNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Node has less outgoing nodes than required.'
            );
        }

        if ( $this->maxOutNodes !== false && $this->numOutNodes > $this->maxOutNodes )
        {
            throw new ezcWorkflowInvalidDefinitionException(
              'Node has more outgoing nodes than allowed.'
            );
        }
    }

     /**
     * @param ezcWorkflowVisitor $visitor
     */
    public function accept( ezcWorkflowVisitor $visitor )
    {
        if ( $visitor->visit( $this ) )
        {
            foreach ( $this->outNodes as $outNode )
            {
                $outNode->accept( $visitor );
            }
        }
    }

    /**
     * Activate this node.
     *
     * @param ezcWorkflowExecution $execution
     * @param ezcWorkflowNode $activatedFrom
     * @param integer $threadId
     */
    public function activate( ezcWorkflowExecution $execution, ezcWorkflowNode $activatedFrom = null, $threadId = 0 )
    {
        if ( $this->activationState === self::WAITING_FOR_ACTIVATION )
        {
            $this->activationState = self::WAITING_FOR_EXECUTION;
            $this->threadId = $threadId;

            if ( $activatedFrom !== null )
            {
                $this->activatedFrom[] = get_class( $activatedFrom );
            }

            $execution->activate( $this );
        }
    }

    /**
     * Convenience method for activating an (outgoing) node.
     *
     * @param ezcWorkflowExecution $execution
     * @param ezcWorkflowNode $node
     */
    protected function activateNode( ezcWorkflowExecution $execution, ezcWorkflowNode $node )
    {
        $node->activate( $execution, $this, $this->getThreadId() );
    }

    /**
     * Checks whether this node is ready for execution.
     *
     * @return boolean
     */
    public function isExecutable()
    {
        return $this->activationState === self::WAITING_FOR_EXECUTION;
    }

    /**
     * Executes this node.
     *
     * @param  ezcWorkflowExecution $execution
     * @return boolean true when the node finished execution,
     *                 and false otherwise
     */
    public function execute( ezcWorkflowExecution $execution )
    {
        $this->activationState = self::WAITING_FOR_ACTIVATION;
        $this->activatedFrom = array();
        $this->threadId = null;

        return true;
    }

    /**
     * Adds a node to the workflow associated with this node.
     *
     * @param ezcWorkflowNode $node The node to be added.
     */
    protected function addNodeToWorkflow( ezcWorkflowNode $node )
    {
        $workflow = $node->getWorkflow();

        if ( $this->workflow === null && $workflow !== null )
        {
            $this->workflow = $workflow;
        }

        if ( $this->workflow !== null )
        {
            $nodes = array_merge(
              array( $node ),
              $node->getOutNodes(),
              $this->getOutNodes()
            );

            foreach ( $nodes as $node )
            {
                $this->workflow->addNode( $node );
            }
        }
    }

    /**
     * Returns a textual representation of this node.
     *
     * @return string
     */
    public function __toString()
    {
        $type   = str_replace( 'ezcWorkflowNode', '', get_class( $this ) );
        $max    = strlen( $type );
        $string = '';

        for ( $i = 0; $i < $max; $i++ )
        {
            if ( $i > 0 && ord( $type[$i] ) >= 65 && ord( $type[$i] ) <= 90 )
            {
                $string .= ' ';
            }

            $string .= $type[$i];
        }

        return $string;
    }

    /**
     * Initializes the state of this node.
     */
    protected function initState()
    {
    }
}
?>
