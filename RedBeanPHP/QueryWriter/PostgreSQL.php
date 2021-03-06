<?php

namespace RedBeanPHP\QueryWriter;

use RedBeanPHP\QueryWriter\AQueryWriter as AQueryWriter;
use RedBeanPHP\QueryWriter as QueryWriter;
use RedBeanPHP\Adapter\DBAdapter as DBAdapter;
use RedBeanPHP\Adapter as Adapter;

/**
 * RedBean PostgreSQL Query Writer
 *
 * @file    RedBean/QueryWriter/PostgreSQL.php
 * @desc    QueryWriter for the PostgreSQL database system.
 * @author  Gabor de Mooij and the RedBeanPHP Community
 * @license BSD/GPLv2
 *
 * (c) copyright G.J.G.T. (Gabor) de Mooij and the RedBeanPHP Community.
 * This source file is subject to the BSD/GPLv2 License that is bundled
 * with this source code in the file license.txt.
 */
class PostgreSQL extends AQueryWriter implements QueryWriter
{
	/**
	 * Data types
	 */
	const C_DATATYPE_INTEGER          = 0;
	const C_DATATYPE_DOUBLE           = 1;
	const C_DATATYPE_TEXT             = 3;
	const C_DATATYPE_SPECIAL_DATE     = 80;
	const C_DATATYPE_SPECIAL_DATETIME = 81;
	const C_DATATYPE_SPECIAL_POINT    = 90;
	const C_DATATYPE_SPECIAL_LSEG     = 91;
	const C_DATATYPE_SPECIAL_CIRCLE   = 92;
	const C_DATATYPE_SPECIAL_MONEY    = 93;
	const C_DATATYPE_SPECIAL_POLYGON  = 94;
	const C_DATATYPE_SPECIFIED        = 99;

	/**
	 * @var DBAdapter
	 */
	protected $adapter;

	/**
	 * @var string
	 */
	protected $quoteCharacter = '"';

	/**
	 * @var string
	 */
	protected $defaultValue = 'DEFAULT';

	/**
	 * Returns the insert suffix SQL Snippet
	 *
	 * @param string $table table
	 *
	 * @return  string $sql SQL Snippet
	 */
	protected function getInsertSuffix( $table )
	{
		return 'RETURNING id ';
	}

	/**
	 * @see AQueryWriter::getKeyMapForType
	 */
	protected function getKeyMapForType( $type )
	{
		$table = $this->esc( $type, TRUE );
		$keys = $this->adapter->get( '
			SELECT
			information_schema.key_column_usage.constraint_name AS "name",
			information_schema.key_column_usage.column_name AS "from",
			information_schema.constraint_table_usage.table_name AS "table",
			information_schema.constraint_column_usage.column_name AS "to",
			information_schema.referential_constraints.update_rule AS "on_update",
			information_schema.referential_constraints.delete_rule AS "on_delete"
				FROM information_schema.key_column_usage
			INNER JOIN information_schema.constraint_table_usage
				ON (
					information_schema.key_column_usage.constraint_name = information_schema.constraint_table_usage.constraint_name
					AND information_schema.key_column_usage.constraint_schema = information_schema.constraint_table_usage.constraint_schema
					AND information_schema.key_column_usage.constraint_catalog = information_schema.constraint_table_usage.constraint_catalog
				)
			INNER JOIN information_schema.constraint_column_usage
				ON (
					information_schema.key_column_usage.constraint_name = information_schema.constraint_column_usage.constraint_name
					AND information_schema.key_column_usage.constraint_schema = information_schema.constraint_column_usage.constraint_schema
					AND information_schema.key_column_usage.constraint_catalog = information_schema.constraint_column_usage.constraint_catalog
				)
			INNER JOIN information_schema.referential_constraints
				ON (
					information_schema.key_column_usage.constraint_name = information_schema.referential_constraints.constraint_name
					AND information_schema.key_column_usage.constraint_schema = information_schema.referential_constraints.constraint_schema
					AND information_schema.key_column_usage.constraint_catalog = information_schema.referential_constraints.constraint_catalog
				)
			WHERE
				information_schema.key_column_usage.table_catalog = current_database()
				AND information_schema.key_column_usage.table_schema = ANY( current_schemas( FALSE ) )
				AND information_schema.key_column_usage.table_name = ?
		', array( $type ) );
		$keyInfoList = array();
		foreach ( $keys as $k ) {
			$label = $this->makeFKLabel( $k['from'], $k['table'], $k['to'] );
			$keyInfoList[$label] = array(
				'name'          => $k['name'],
				'from'          => $k['from'],
				'table'         => $k['table'],
				'to'            => $k['to'],
				'on_update'     => $k['on_update'],
				'on_delete'     => $k['on_delete']
			);
		}
		return $keyInfoList;
	}

	/**
	 * @see AQueryWriter::getUniquesForType
	 */
	protected function getUniquesForType( $type )
	{
		$table = $this->esc( $type, TRUE );
		$columns = $this->adapter->get('
			SELECT
				information_schema.key_column_usage.constraint_name,
				information_schema.key_column_usage.column_name
			FROM
				information_schema.table_constraints
			INNER JOIN information_schema.key_column_usage
				ON (
					information_schema.table_constraints.constraint_name = information_schema.key_column_usage.constraint_name
					AND information_schema.table_constraints.constraint_schema = information_schema.key_column_usage.constraint_schema
					AND information_schema.table_constraints.constraint_catalog = information_schema.key_column_usage.constraint_catalog
				)
			WHERE
				information_schema.table_constraints.table_catalog = current_database()
				AND information_schema.key_column_usage.table_schema = ANY( current_schemas( FALSE ) )
				AND information_schema.table_constraints.table_name = ?
				AND information_schema.table_constraints.constraint_type = \'UNIQUE\'
		', array( $table ) );
		$uniques = array();
		foreach( $columns as $column ) {
			if ( !isset( $uniques[ $column['constraint_name'] ] ) ) $uniques[ $column['constraint_name'] ] = array();
			$uniques[ $column['constraint_name'] ][] = $column['column_name'];
		}
		return $uniques;
	}

	/**
	 * @see AQueryWriter::getIndexListForType
	 */
	protected function getIndexListForType( $type )
	{
		$tableNoQ = $this->esc( $type, TRUE );
		$indexes = $this->adapter->get("
			SELECT relname, indkey
				FROM pg_class, pg_index
			WHERE pg_class.oid = pg_index.indexrelid
				AND pg_class.oid IN (
					SELECT indexrelid
						FROM pg_index, pg_class
					WHERE pg_class.relname = '{$tableNoQ}'
						AND pg_class.oid = pg_index.indrelid
						AND indisunique != 't'
						AND indisprimary != 't')");

		$indexList = array();

		foreach($indexes as $index) {
			if (!isset($indexList[$index['relname']])) {
				$indexList[$index['relname']] = array();
			}
			$keys = implode(',', explode(' ', $index['indkey']));
			$info = $this->adapter->getCell("
				SELECT pg_attribute.attname
				FROM pg_index
				LEFT JOIN pg_class
					ON pg_index.indrelid  = pg_class.oid
				LEFT JOIN pg_attribute
					ON pg_attribute.attrelid = pg_class.oid
					AND pg_attribute.attnum = ANY( indkey )
				WHERE pg_class.relname = '{$tableNoQ}'
					AND pg_attribute.attnum IN ({$keys})
			");
			$indexList[$index['relname']][] = $info;
		}
		return $indexList;
	}

	/**
	 * Constructor
	 *
	 * @param Adapter $adapter Database Adapter
	 */
	public function __construct( Adapter $adapter )
	{
		$this->typeno_sqltype = array(
			self::C_DATATYPE_INTEGER          => ' integer ',
			self::C_DATATYPE_DOUBLE           => ' double precision ',
			self::C_DATATYPE_TEXT             => ' text ',
			self::C_DATATYPE_SPECIAL_DATE     => ' date ',
			self::C_DATATYPE_SPECIAL_DATETIME => ' timestamp without time zone ',
			self::C_DATATYPE_SPECIAL_POINT    => ' point ',
			self::C_DATATYPE_SPECIAL_LSEG     => ' lseg ',
			self::C_DATATYPE_SPECIAL_CIRCLE   => ' circle ',
			self::C_DATATYPE_SPECIAL_MONEY    => ' money ',
			self::C_DATATYPE_SPECIAL_POLYGON  => ' polygon ',
		);

		$this->sqltype_typeno = array();

		foreach ( $this->typeno_sqltype as $k => $v ) {
			$this->sqltype_typeno[trim( strtolower( $v ) )] = $k;
		}

		$this->adapter = $adapter;
	}

	/**
	 * This method returns the datatype to be used for primary key IDS and
	 * foreign keys. Returns one if the data type constants.
	 *
	 * @return integer $const data type to be used for IDS.
	 */
	public function getTypeForID()
	{
		return self::C_DATATYPE_INTEGER;
	}

	/**
	 * @see QueryWriter::getTables
	 */
	public function getTables()
	{
		return $this->adapter->getCol( 'SELECT table_name FROM information_schema.tables WHERE table_schema = ANY( current_schemas( FALSE ) )' );
	}

	/**
	 * @see QueryWriter::createTable
	 */
	public function createTable( $table )
	{
		$table = $this->esc( $table );

		$this->adapter->exec( " CREATE TABLE $table (id SERIAL PRIMARY KEY); " );
	}

	/**
	 * @see QueryWriter::getColumns
	 */
	public function getColumns( $table )
	{
		$table      = $this->esc( $table, TRUE );

		$columnsRaw = $this->adapter->get( "SELECT column_name, data_type FROM information_schema.columns WHERE table_name='$table'" );

		$columns = array();
		foreach ( $columnsRaw as $r ) {
			$columns[$r['column_name']] = $r['data_type'];
		}

		return $columns;
	}

	/**
	 * @see QueryWriter::scanType
	 */
	public function scanType( $value, $flagSpecial = FALSE )
	{
		$this->svalue = $value;

		if ( $value === INF ) return self::C_DATATYPE_TEXT;

		if ( $flagSpecial && $value ) {
			if ( preg_match( '/^\d{4}\-\d\d-\d\d$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_DATE;
			}

			if ( preg_match( '/^\d{4}\-\d\d-\d\d\s\d\d:\d\d:\d\d(\.\d{1,6})?$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_DATETIME;
			}

			if ( preg_match( '/^\([\d\.]+,[\d\.]+\)$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_POINT;
			}

			if ( preg_match( '/^\[\([\d\.]+,[\d\.]+\),\([\d\.]+,[\d\.]+\)\]$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_LSEG;
			}

			if ( preg_match( '/^\<\([\d\.]+,[\d\.]+\),[\d\.]+\>$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_CIRCLE;
			}

			if ( preg_match( '/^\((\([\d\.]+,[\d\.]+\),?)+\)$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_POLYGON;
			}

			if ( preg_match( '/^\-?(\$|€|¥|£)[\d,\.]+$/', $value ) ) {
				return PostgreSQL::C_DATATYPE_SPECIAL_MONEY;
			}
		}

		if ( $this->startsWithZeros( $value ) ) return self::C_DATATYPE_TEXT; 

		if ( $value === FALSE || $value === TRUE || $value === NULL || ( $value instanceof NULL ) || ( is_numeric( $value )
				&& floor( $value ) == $value
				&& $value < 2147483648
				&& $value > -2147483648 )
		) {
			return self::C_DATATYPE_INTEGER;
		} elseif ( is_numeric( $value ) ) {
			return self::C_DATATYPE_DOUBLE;
		} else {
			return self::C_DATATYPE_TEXT;
		}
	}

	/**
	 * @see QueryWriter::code
	 */
	public function code( $typedescription, $includeSpecials = FALSE )
	{
		$r = ( isset( $this->sqltype_typeno[$typedescription] ) ) ? $this->sqltype_typeno[$typedescription] : 99;

		if ( $includeSpecials ) return $r;

		if ( $r >= QueryWriter::C_DATATYPE_RANGE_SPECIAL ) {
			return self::C_DATATYPE_SPECIFIED;
		}

		return $r;
	}

	/**
	 * @see QueryWriter::widenColumn
	 */
	public function widenColumn( $type, $column, $datatype )
	{
		$table   = $type;
		$type    = $datatype;

		$table   = $this->esc( $table );
		$column  = $this->esc( $column );

		$newtype = $this->typeno_sqltype[$type];

		$this->adapter->exec( "ALTER TABLE $table \n\t ALTER COLUMN $column TYPE $newtype " );
	}

	/**
	 * @see QueryWriter::addUniqueIndex
	 */
	public function addUniqueConstraint( $type, $properties )
	{
		$tableNoQ = $this->esc( $type, TRUE );
		if ( $this->areColumnsInUniqueIndex( $tableNoQ, $properties ) ) return FALSE;
		$columns = array();
		foreach( $properties as $key => $column ) $columns[$key] = $this->esc( $column );
		$table = $this->esc( $type );
		sort( $columns ); //else we get multiple indexes due to order-effects
		$name = "UQ_" . sha1( $table . implode( ',', $columns ) );
		$sql = "ALTER TABLE {$table}
                ADD CONSTRAINT $name UNIQUE (" . implode( ',', $columns ) . ")";
		$this->adapter->exec( $sql );
	}

	/**
	 * @see QueryWriter::sqlStateIn
	 */
	public function sqlStateIn( $state, $list )
	{
		$stateMap = array(
			'42P01' => QueryWriter::C_SQLSTATE_NO_SUCH_TABLE,
			'42703' => QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			'23505' => QueryWriter::C_SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION
		);

		return in_array( ( isset( $stateMap[$state] ) ? $stateMap[$state] : '0' ), $list );
	}

	/**
	 * @see QueryWriter::addIndex
	 */
	public function addIndex( $type, $name, $property )
	{
		try {
			if ( $this->isIndexed( $type, $property ) ) return FALSE;
		} catch ( \Exception $e ) {
			return FALSE;
		}

		$table  = $this->esc( $type );
		$name   = preg_replace( '/\W/', '', $name );
		$column = $this->esc( $property );

		try {
			$this->adapter->exec( "CREATE INDEX {$name} ON $table ({$column}) " );
			return TRUE;
		} catch (\Exception $e ) {
			return FALSE;
		}
	}

	/**
	 * @see QueryWriter::addFK
	 */
	public function addFK( $type, $targetType, $property, $targetProperty, $isDep = FALSE )
	{
		$table = $this->esc( $type );
		$targetTable = $this->esc( $targetType );
		$field = $this->esc( $property );
		$targetField = $this->esc( $targetProperty );
		$tableNoQ = $this->esc( $type, TRUE );
		$fieldNoQ = $this->esc( $property, TRUE );
		if ( !is_null( $this->getForeignKeyForTypeProperty( $tableNoQ, $fieldNoQ ) ) ) return FALSE;
		try{
			$delRule = ( $isDep ? 'CASCADE' : 'SET NULL' );
			$this->adapter->exec( "ALTER TABLE {$table}
				ADD FOREIGN KEY ( {$field} ) REFERENCES  {$targetTable}
				({$targetField}) ON DELETE {$delRule} ON UPDATE {$delRule} DEFERRABLE ;" );
			return TRUE;
		} catch (\Exception $e ) {
			return FALSE;
		}
	}

	/**
	 * @see QueryWriter::wipeAll
	 */
	public function wipeAll()
	{
		$this->adapter->exec( 'SET CONSTRAINTS ALL DEFERRED' );

		foreach ( $this->getTables() as $t ) {
			$t = $this->esc( $t );

			$this->adapter->exec( "DROP TABLE IF EXISTS $t CASCADE " );
		}

		$this->adapter->exec( 'SET CONSTRAINTS ALL IMMEDIATE' );
	}
}
