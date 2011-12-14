<?php

class ezcSearchInvalidValueException extends ezcSearchException
{
    /**
     * Constructs an ezcSearchDefinitionInvalidException
     *
     * @param string $type
     * @param string $class
     * @param string $location
     * @param string $extraMsg
     * @return void
     */
    public function __construct( $type, $value, $flag )
    {
        $message = "The field type '$type' has an invalid value '$value' with '$flag'.";
        parent::__construct( $message );
    }
}
?>
