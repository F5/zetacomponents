<?php
/**
 * File containing the ezcQuerySelect class.
 *
 * @package Database
 * @version //autogentag//
 * @copyright Copyright (C) 2005, 2006 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * Class to create select database independent SELECT queries.
 *
 * Note that this class creates queries that are syntactically independant
 * of database. Semantically the queries still differ and so the same
 * query may produce different results on different databases. Such
 * differences are noted throughout the documentation of this class.
 *
 * This class implements SQL92. If your database differs from the SQL92
 * implementation extend this class and reimplement the methods that produce
 * different results. Some methods implemented in ezcQuery are not defined by SQL92.
 * These methods are marked and ezcQuery will return MySQL syntax for these cases.
 *
 * The examples show the SQL generated by this class.
 * Database specific implementations may produce different results.
 *
 * Example:
 * <code>
 * $q = ezcDbInstance::get()->createSelectQuery();
 * $q->select( '*' )->from( 'Greetings' )
 *     ->where( $q->expr->gt( 'age', 10 ),
 *              $q->expr->eq( 'greeting', $q->bindValue( 'Hello world' ) ) )
 *     ->orderBy( 'owner' )
 *     ->limit( 10 );
 * $stmt = $q->prepare(); // $stmt is a normal PDOStatement
 * $stmt->execute();
 * </code>
 *
 * Database independence:
 * TRUE/FALSE, MySQL accepts 0 and 1 as boolean values. Postgres does not, but accepts TRUE/FALSE.
 * @todo introduction needs explation of security etc.
 * @todo introduction needs examples with clone(), reusing a query and advanced binding.
 * @package Database
 * @mainclass
 */
class ezcQuerySelect extends ezcQuery
{
    /**
     * Sort the result ascendingly.
     */
    const ASC = 'ASC';

    /**
     * Sort the result descendingly.
     */
    const DESC = 'DESC';

    /**
     * Stores the SELECT part of the SQL.
     *
     * Everything from 'SELECT' until 'FROM' is stored.
     * @var string
     */
    protected $selectString = null;

    /**
     * Stores the FROM part of the SQL.
     *
     * Everything from 'FROM' until 'WHERE' is stored.
     * @var string
     */
    protected $fromString = null;

    /**
     * Stores the WHERE part of the SQL.
     *
     * Everything from 'WHERE' until 'GROUP', 'LIMIT', 'ORDER' or 'SORT' is stored.
     * @var string
     */
    protected $whereString = null;

    /**
     * Stores the GROUP BY part of the SQL.
     *
     * @var string
     */
    protected $groupString = null;

    /**
     * Stores the HAVING part of SQL
     * 
     * @var string
     */    
    protected $havingString = null;

    /**
     * Stores the ORDER BY part of the SQL.
     *
     * @var string
     */
    protected $orderString = null;
        
    /**
     * Stores the LIMIT part of the SQL.
     *
     * @var string
     */
    protected $limitString = null;

    /**
     * Stores the name of last invoked SQL clause method.
     *
     * Could be 'select', 'from', 'where', 'group', 'having', 'order', 'limit'
     * @var string
     */
    protected $lastInvokedMethod = null;

    /**
     * Constructs a new ezcQuery object.
     *
     * @param PDO $db a pointer to the database object.
     */
    public function __construct( PDO $db, array $aliases = array() )
    {
        parent::__construct( $db, $aliases );
    }

    /**
     * Resets the query object for reuse.
     *
     * @return void
     */
    public function reset()
    {
        $this->selectString = null;
        $this->fromString = null;
        $this->whereString = null;
        $this->groupString = null;
        $this->havingString = null;
        $this->orderString = null;
        $this->limitString = null;
        $this->lastInvokedClauseMethod = null;

        $this->boundCounter = 0;
        $this->boundValues = array();
    }

    /**
     * Opens the query and selects which columns you want to return with
     * the query.
     *
     * select() accepts an arbitrary number of parameters. Each parameter
     * must contain either the name of a column or an array containing
     * the names of the columns.
     * Each call to select() appends columns to the list of columns that will be
     * used in the query.
     *
     * Example:
     * <code>
     * $q->select( 'column1', 'column2' );
     * </code>
     * The same could also be written
     * <code>
     * $columns[] = 'column1';
     * $columns[] = 'column2;
     * $q->select( $columns );
     * </code>
     * or using several calls
     * <code>
     * $q->select( 'column1' )->select( 'column2' );
     * </code>
     *
     * Each of above code produce SQL clause 'SELECT column1, column2' for the query.
     *
     * @throws ezcQueryVariableParameterException if called with no parameters..
     * @param string|array(string) Either a string with a column name or an array of column names.
     * @return ezcQuery returns a pointer to $this.
     */
    public function select()
    {
        if ( $this->selectString == null )
        {
            $this->selectString = 'SELECT ';
        }

        $args = func_get_args();
        $cols = self::arrayFlatten( $args );

        if ( count( $cols ) < 1 )
        {
            throw new ezcQueryVariableParameterException( 'select', count( $args ), 1 );
        }
        $this->lastInvokedMethod = 'select';
        $cols = $this->getIdentifiers( $cols );

        // glue string should be inserted each time but not before first entry
        if ( $this->selectString != 'SELECT ' )
        {
            $this->selectString .= ', ';
        }

        $this->selectString .= join( ', ', $cols );
        return $this;
    }

    /**
     * Returns SQL to create an alias
     *
     * This method can be used to create an alias for either a
     * table or a column.
     * Example:
     * <code>
     * // this will make the table users have the alias employees
     * // and the column user_id the alias employee_id
     * $q->select( $q->alias( 'user_id', 'employee_id' )
     *   ->from( $q->alias( 'users', 'employees' ) );
     * </code>
     *
     * @param string $name
     * @param string $alias
     * @return string the query string "columnname as targetname"
     */
    public function alias( $name, $alias )
    {
        $name = $this->getIdentifier( $name );
        return "{$name} AS {$alias}";
    }

    /**
     * Select which tables you want to select from.
     *
     * from() accepts an arbitrary number of parameters. Each parameter
     * must contain either the name of a table or an array containing
     * the names of tables..
     * Each call to from() appends tables to the list of tables that will be
     * used in the query.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM table_name
     * $q->select( 'id' )->from( 'table_name' );
     * </code>
     *
     * @throws ezcQueryVariableParameterException if called with no parameters.
     * @param string|array(string) Either a string with a table name or an array of table names.
     * @return ezcQuery a pointer to $this
     */
    public function from()
    {
        if ( $this->fromString == '' )
        {
            $this->fromString = 'FROM ';
        }

        $args = func_get_args();
        $tables = self::arrayFlatten( $args );
        if ( count( $tables ) < 1 )
        {
            throw new ezcQueryVariableParameterException( 'from', count( $args ), 1 );
        }
        $this->lastInvokedMethod = 'from';
        $tables = $this->getIdentifiers( $tables );

        // glue string should be inserted each time but not before first entry
        if ( $this->fromString != 'FROM ' )
        {
            $this->fromString .= ', ';
        }

        $this->fromString .= join( ', ', $tables );
        return $this;
    }

    /**
     * Returns the SQL for a inner join or prepares $fromString for a inner join.
     * 
     * @throws ezcQueryInvalidException if called with incosistent parameters or if
     *         invoked without preceding call to from().
     *
     * This method could be used in three forms:
     * - innerJoin( 't1', 't2', 't1.id', 't2.id' ) takes 4 string arguments and return SQL string
     * @param string $table1 the name of the table to join with
     * @param string $table2 the name of the table to join
     * @param string $column1 the column to join with
     * @param string $column2 the column to join on
     * @return string the SQL call for a inner join.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 INNER JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( $q->innerJoin( 't1', 't2', 't1.id', 't2.id' ) );
     * </code>
     *
     * - innerJoin( 't2', $joinCondition )
     * takes 2 string arguments and return ezcQuery.
     *
     * @param string $table2 the name of the table to join. The name of table to
     * join with should be set in previous call to from().
     * @param string $condition the string with join condition returned by ezcQueryExpression.
     * @return ezcQuery returns a pointer to $this.
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 INNER JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( 't1' )->innerJoin( 't2', $q->expr->eq('t1.id', 't2.id' ) );
     * </code>
     *
     * - innerJoin( 't1', 't1.id', 't2.id' ) simlified version of previous
     * that equals to innerJoin( 't1', $this->expr->eq('t1.id', 't2.id' ) );
     *
     * takes 3 string arguments and return ezcQuery
     * @param string $table2 the name of the table to join. The name of table to
     * join with should be set in previous call to from().
     * @param string $column1 the column to join with
     * @param string $column2 the column to join on
     * @return ezcQuery returns a pointer to $this.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 INNER JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( 't1' )->innerJoin( 't2', 't1.id', 't2.id' );
     * </code>
     *
     */
    public function innerJoin()
    {
        $args = func_get_args();
        $passedArgsCount = count( $args );
        if ( $passedArgsCount != 4 && $passedArgsCount != 2 && $passedArgsCount != 3 )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Wrong count of arguments passed to innerJoin():'.$passedArgsCount );
        }
        // process old simple syntax.
        if ( $passedArgsCount == 4 )
        {
            if ( is_string( $args[0] ) && is_string( $args[1] ) &&
                 is_string( $args[2] ) && is_string( $args[3] )
               )
            {
                $table1 = $this->getIdentifier( $args[0] );
                $table2 = $this->getIdentifier( $args[1] );
                $column1 = $this->getIdentifier( $args[2] );
                $column2 = $this->getIdentifier( $args[3] );

                return "{$table1} INNER JOIN {$table2} ON {$column1} = {$column2}";
            }
            else
            {
                throw new ezcQueryInvalidException( 'SELECT', 'Inconsistent types of arguments passed to innerJoin().' );
            }
        }

        // using from()->innerJoin() syntax assumed, so check if last call was to from()
        if ( $this->lastInvokedMethod != 'from' )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Invoking innerJoin() not immediately after from().' );
        }

        $table = '';
        if ( !is_string( $args[0] ) )
        {
            throw new ezcQueryInvalidException( 'SELECT',
                     'Inconsistent type of first argument passed to innerJoin(). Should be string with name of table.' );
        }
        $table = $this->getIdentifier( $args[0] );

        $condition = '';
        if ( $passedArgsCount == 2 && is_string( $args[1] ) )
        {
            $condition = $args[1];
        } else if ( $passedArgsCount == 3 && is_string( $args[1] ) && is_string( $args[2] ) )
        {
            $arg1 = $this->getIdentifier( $args[1] );
            $arg2 = $this->getIdentifier( $args[2] );

            $condition = "{$arg1} = {$arg2}";
        }

        $this->fromString .= " INNER JOIN {$table} ON {$condition}";
        return $this;
    }

    /**
     * Returns the SQL for a left join or prepares $fromString for a left join.
     * 
     * @throws ezcQueryInvalidException if called with incosistent parameters or if
     *         invoked without preceding call to from().
     *
     * This method could be used in three forms:
     * - leftJoin( 't1', 't2', 't1.id', 't2.id' ) takes 4 string arguments and return SQL string
     * @param string $table1 the name of the table to join with
     * @param string $table2 the name of the table to join
     * @param string $column1 the column to join with
     * @param string $column2 the column to join on
     * @return string the SQL call for a left join.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 LEFT JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( $q->leftJoin( 't1', 't2', 't1.id', 't2.id' ) );
     * </code>
     *
     * - leftJoin( 't2', $joinCondition )
     * takes 2 string arguments and return ezcQuery.
     *
     * @param string $table2 the name of the table to join. The name of table to
     * join with should be set in previous call to from().
     * @param string $condition the string with join condition returned by ezcQueryExpression.
     * @return ezcQuery returns a pointer to $this.
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 LEFT JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( 't1' )->leftJoin( 't2', $q->expr->eq('t1.id', 't2.id' ) );
     * </code>
     *
     * - leftJoin( 't1', 't1.id', 't2.id' ) simlified version of previous
     * that equals to leftJoin( 't1', $this->expr->eq('t1.id', 't2.id' ) );
     *
     * takes 3 string arguments and return ezcQuery
     * @param string $table2 the name of the table to join. The name of table to
     * join with should be set in previous call to from().
     * @param string $column1 the column to join with
     * @param string $column2 the column to join on
     * @return ezcQuery returns a pointer to $this.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 LEFT JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( 't1' )->leftJoin( 't2', 't1.id', 't2.id' );
     * </code>
     *
     */
    public function leftJoin()
    {
        $args = func_get_args();
        $passedArgsCount = count( $args );
        if ( $passedArgsCount != 4 && $passedArgsCount != 2 && $passedArgsCount != 3 )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Wrong count of arguments passed to leftJoin():'.$passedArgsCount );
        }
        // process old simple syntax.
        if ( $passedArgsCount == 4 )
        {
            if ( is_string( $args[0] ) && is_string( $args[1] ) &&
                 is_string( $args[2] ) && is_string( $args[3] )
               )
            {
                $table1 = $this->getIdentifier( $args[0] );
                $table2 = $this->getIdentifier( $args[1] );
                $column1 = $this->getIdentifier( $args[2] );
                $column2 = $this->getIdentifier( $args[3] );

                return "{$table1} LEFT JOIN {$table2} ON {$column1} = {$column2}";
            }
            else
            {
                throw new ezcQueryInvalidException( 'SELECT', 'Inconsistent types of arguments passed to leftJoin().' );
            }
        }

        // using from()->leftJoin() syntax assumed, so check if last call was to from()
        if ( $this->lastInvokedMethod != 'from' )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Invoking leftJoin() not immediately after from().' );
        }

        $table = '';
        if ( !is_string( $args[0] ) )
        {
            throw new ezcQueryInvalidException( 'SELECT',
                     'Inconsistent type of first argument passed to leftJoin(). Should be string with name of table.' );
        }
        $table = $this->getIdentifier( $args[0] );

        $condition = '';
        if ( $passedArgsCount == 2 && is_string( $args[1] ) )
        {
            $condition = $args[1];
        } else if ( $passedArgsCount == 3 && is_string( $args[1] ) && is_string( $args[2] ) )
        {
            $arg1 = $this->getIdentifier( $args[1] );
            $arg2 = $this->getIdentifier( $args[2] );

            $condition = "{$arg1} = {$arg2}";
        }

        $this->fromString .= " LEFT JOIN {$table} ON {$condition}";
        return $this;
    }

    /**
     * Returns the SQL for a right join or prepares $fromString for a right join.
     * 
     * @throws ezcQueryInvalidException if called with incosistent parameters or if
     *         invoked without preceding call to from().
     * 
     * This method could be used in three forms:
     * - rightJoin( 't1', 't2', 't1.id', 't2.id' ) takes 4 string arguments and return SQL string
     * @param string $table1 the name of the table to join with
     * @param string $table2 the name of the table to join
     * @param string $column1 the column to join with
     * @param string $column2 the column to join on
     * @return string the SQL call for a right join.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 RIGHT JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( $q->rightJoin( 't1', 't2', 't1.id', 't2.id' ) );
     * </code>
     *
     * - rightJoin( 't2', $joinCondition )
     * takes 2 string arguments and return ezcQuery.
     *
     * @param string $table2 the name of the table to join. The name of table to
     * join with should be set in previous call to from().
     * @param string $condition the string with join condition returned by ezcQueryExpression.
     * @return ezcQuery returns a pointer to $this.
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 RIGHT JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( 't1' )->rightJoin( 't2', $q->expr->eq('t1.id', 't2.id' ) );
     * </code>
     *
     * - rightJoin( 't1', 't1.id', 't2.id' ) simlified version of previous
     * that equals to rightJoin( 't1', $this->expr->eq('t1.id', 't2.id' ) );
     *
     * takes 3 string arguments and return ezcQuery
     * @param string $table2 the name of the table to join. The name of table to
     * join with should be set in previous call to from().
     * @param string $column1 the column to join with
     * @param string $column2 the column to join on
     * @return ezcQuery returns a pointer to $this.
     *
     * Example:
     * <code>
     * // the following code will produce the SQL
     * // SELECT id FROM t1 RIGHT JOIN t2 ON t1.id = t2.id
     * $q->select( 'id' )->from( 't1' )->rightJoin( 't2', 't1.id', 't2.id' );
     * </code>
     *
     */
    public function rightJoin()
    {
        $args = func_get_args();
        $passedArgsCount = count( $args );
        if ( $passedArgsCount != 4 && $passedArgsCount != 2 && $passedArgsCount != 3 )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Wrong count of arguments passed to rightJoin():'.$passedArgsCount );
        }
        // process old simple syntax.
        if ( $passedArgsCount == 4 )
        {
            if ( is_string( $args[0] ) && is_string( $args[1] ) &&
                 is_string( $args[2] ) && is_string( $args[3] )
               )
            {
                $table1 = $this->getIdentifier( $args[0] );
                $table2 = $this->getIdentifier( $args[1] );
                $column1 = $this->getIdentifier( $args[2] );
                $column2 = $this->getIdentifier( $args[3] );

                return "{$table1} RIGHT JOIN {$table2} ON {$column1} = {$column2}";
            }
            else
            {
                throw new ezcQueryInvalidException( 'SELECT', 'Inconsistent types of arguments passed to rightJoin().' );
            }
        }

        // using from()->rightJoin() syntax assumed, so check if last call was to from()
        if ( $this->lastInvokedMethod != 'from' )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Invoking rightJoin() not immediately after from().' );
        }

        $table = '';
        if ( !is_string( $args[0] ) )
        {
            throw new ezcQueryInvalidException( 'SELECT',
                     'Inconsistent type of first argument passed to rightJoin(). Should be string with name of table.' );
        }
        $table = $this->getIdentifier( $args[0] );

        $condition = '';
        if ( $passedArgsCount == 2 && is_string( $args[1] ) )
        {
            $condition = $args[1];
        } else if ( $passedArgsCount == 3 && is_string( $args[1] ) && is_string( $args[2] ) )
        {
            $arg1 = $this->getIdentifier( $args[1] );
            $arg2 = $this->getIdentifier( $args[2] );

            $condition = "{$arg1} = {$arg2}";
        }

        $this->fromString .= " RIGHT JOIN {$table} ON {$condition}";
        return $this;
    }

    /**
     * Adds a where clause with logical expressions to the query.
     *
     * where() accepts an arbitrary number of parameters. Each parameter
     * must contain a logical expression or an array with logical expressions.
     * If you specify multiple logical expression they are connected using
     * a logical and.
     *
     * Multiple calls to where() will join the expressions using a logical and.
     *
     * Example:
     * <code>
     * $q->select( '*' )->from( 'table' )->where( $q->expr->eq( 'id', 1 ) );
     * </code>
     *
     * @throws ezcQueryVariableParameterException if called with no parameters.
     * @param string|array(string) Either a string with a logical expression name
     * or an array with logical expressions.
     * @return ezcQuerySelect
     */
    public function where()
    {
        if ( $this->whereString == null )
        {
            $this->whereString = 'WHERE ';
        }

        $args = func_get_args();
        $expressions = self::arrayFlatten( $args );
        if ( count( $expressions ) < 1 )
        {
            throw new ezcQueryVariableParameterException( 'where', count( $args ), 1 );
        }

        $this->lastInvokedMethod = 'where';

        // glue string should be inserted each time but not before first entry
        if ( $this->whereString != 'WHERE ' )
        {
            $this->whereString .= ' AND ';
        }

        $this->whereString .= join( ' AND ', $expressions );
        return $this;
    }


    // limit, order and group

    /**
     * Returns SQL that limits the result set.
     *
     * $limit controls the maximum number of rows that will be returned.
     * $offset controls which row that will be the first in the result
     * set from the total amount of matching rows.
     *
     * Example:
     * <code>
     * $q->select( '*' )->from( 'table' )
     *                  ->limit( 10, 0 );
     * </code>
     *
     * LIMIT is not part of SQL92. It is implemented here anyway since all
     * databases support it one way or the other and because it is
     * essential.
     *
     * @param $limit integer expression
     * @param $offset integer expression
     * @return string logical expression
     */
    public function limit( $limit, $offset = 0 )
    {
        if ( $offset == 0 )
        {
            $this->limitString = "LIMIT {$limit}";
        }
        else
        {
            $this->limitString = "LIMIT {$limit} OFFSET {$offset}";
        }
        $this->lastInvokedMethod = 'limit';

        return $this;
    }

    /**
     * Returns SQL that orders the result set by a given column.
     *
     * You can call orderBy multiple times. Each call will add a
     * column to order by.
     *
     * Example:
     * <code>
     * $q->select( '*' )->from( 'table' )
     *                  ->orderBy( 'id' );
     * </code>
     *
     * @param string $column a column name in the result set
     * @param string $type if the column should be sorted ascending or descending.
     *        you can specify this using ezcQuery::ASC or ezcQuery::DESC
     * @return ezcQuery a pointer to $this
     */
    public function orderBy( $column, $type = self::ASC )
    {
        $string = $this->getIdentifier( $column );
        if ( $type == self::DESC )
        {
            $string .= ' DESC';
        }
        if ( $this->orderString == '' )
        {
            $this->orderString = "ORDER BY {$string}";
        }
        else
        {
            $this->orderString .= ", {$string}";
        }
        $this->lastInvokedMethod = 'order';

        return $this;
    }

    /**
     * Returns SQL that groups the result set by a given column.
     *
     * You can call groupBy multiple times. Each call will add a
     * column to group by.
     *
     * Example:
     * <code>
     * $q->select( '*' )->from( 'table' )
     *                  ->groupBy( 'id' );
     * </code>
     *
     * @throws ezcQueryVariableParameterException if called with no parameters.
     * @param string $column a column name in the result set
     * @return ezcQuery a pointer to $this
     */
    public function groupBy()
    {
        $args = func_get_args();
        $columns = self::arrayFlatten( $args );
        if ( count( $columns ) < 1 )
        {
            throw new ezcQueryVariableParameterException( 'groupBy', count( $args ), 1 );
        }
        $columns = $this->getIdentifiers( $columns );

        $string = join( ', ', $columns );
        if ( $this->groupString == '' )
        {
            $this->groupString = "GROUP BY {$string}" ;
        }
        else
        {
            $this->groupString .= ", {$string}";
        }

        $this->lastInvokedMethod = 'group';

        return $this;
    }

    /**
     * Returns SQL that set having by a given expression.
     *
     * You can call having multiple times. Each call will add an
     * expression with a logical and.
     *
     * Example:
     * <code>
     * $q->select( '*' )->from( 'table' )->groupBy( 'id' )
     *                  ->having( $q->expr->eq('id',1) );
     * </code>
     *
     * @throws ezcQueryInvalidException
     *         if invoked without preceding call to groupBy().
     * @throws ezcQueryVariableParameterException
     *         if called with no parameters.
     * @param string|array(string) Either a string with a logical expression name
     *                             or an array with logical expressions.
     * @return ezcQuery a pointer to $this
     */    
    public function having()
    {
        // using groupBy()->having() syntax assumed, so check if last call was to groupBy()
        if ( $this->lastInvokedMethod != 'group' && $this->lastInvokedMethod != 'having' )
        {
            throw new ezcQueryInvalidException( 'SELECT', 'Invoking having() not immediately after groupBy().' );
        }

        $args = func_get_args();
        $expressions = self::arrayFlatten( $args );
        if ( count( $expressions ) < 1 )
        {
            throw new ezcQueryVariableParameterException( 'having', count( $args ), 1 );
        }

        if ( $this->havingString == null )
        {
            $this->havingString = 'HAVING ';
        }

        // will add "AND expression" in subsequent calls to having()
        if ( $this->havingString != 'HAVING ' )
        {
            $this->havingString .= ' AND ';
        }

        $this->havingString .= join( ' AND ', $expressions );
        $this->lastInvokedMethod = 'having';
        return $this;
    }

    /**
     * Returns dummy table name.
     *
     * If your select query just evaluates an expression
     * without fetching table data (e.g. 'SELECT 1+1')
     * some databases require you to specify a dummy table in FROM clause.
     * (Oracle: 'SELECT 1+1 FROM dual').
     *
     * This methods returns name of such a dummy table.
     * For DBMSs that don't require that, the method returns false.
     * Otherwise the dummy table name is returned.
     *
     * @return bool|string a dummy table name or false if not needed
     */
    static public function getDummyTableName()
    {
        return false;
    }

    /**
     * Returns the complete select query string.
     *
     * This method uses the build methods to build the
     * various parts of the select query.
     *
     * @todo add newlines? easier for debugging
     * @throws ezcQueryInvalidException if it was not possible to build a valid query.
     * @return string
     */
    public function getQuery()
    {
        if ( $this->selectString == null )
        {
            throw new ezcQueryInvalidException( "SELECT", "select() was not called before getQuery()." );
        }

        $query = "{$this->selectString}";
        if ( $this->fromString != null )
        {
            $query = "{$query} {$this->fromString}";
        }
        if ( $this->whereString != null )
        {
            $query = "{$query} {$this->whereString}";
        }
        if ( $this->groupString != null )
        {
            $query = "{$query} {$this->groupString}";
        }
        if ( $this->havingString != null )
        {
            $query = "{$query} {$this->havingString}";
        }
        if ( $this->orderString != null )
        {
            $query = "{$query} {$this->orderString}";
        }
        if ( $this->limitString != null )
        {
            $query = "{$query} {$this->limitString}";
        }
        return $query;
    }

    /**
     * Returns the ezcQuerySubSelect query object.
     *
     * This method creates new ezcQuerySubSelect object
     * that could be used for building correct
     * subselect inside query.
     *
     * @return ezcQuerySubSelect
     */
    public function subSelect()
    {
        return new ezcQuerySubSelect( $this );
    }

}

?>
