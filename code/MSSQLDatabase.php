<?php

/**
 * @package sapphire
 * @subpackage model
 */

/**
 * MS SQL connector class.
 * @package sapphire
 * @subpackage model
 */
class MSSQLDatabase extends Database {
	/**
	 * Connection to the DBMS.
	 * @var resource
	 */
	private $dbConn;
	
	/**
	 * True if we are connected to a database.
	 * @var boolean
	 */
	private $active;
	
	/**
	 * The name of the database.
	 * @var string
	 */
	private $database;
	
	/**
	 * Connect to a MS SQL database.
	 * @param array $parameters An map of parameters, which should include:
	 *  - server: The server, eg, localhost
	 *  - username: The username to log on with
	 *  - password: The password to log on with
	 *  - database: The database to connect to
	 */
	public function __construct($parameters) {
		//assumes that the server and dbname will always be provided:
		$this->dbConn = mssql_connect($parameters['server'], $parameters['username'], $parameters['password']);
		$this->active = mssql_select_db($parameters['database'], $this->dbConn);
		$this->database = $parameters['database'];
		
		if(!$this->dbConn) {
			$this->databaseError("Couldn't connect to MS SQL database");
		} else {
			$this->active=true;
			$this->database = $parameters['database'];
			mssql_select_db($parameters['database'], $this->dbConn);
		}

		parent::__construct();
		
		// Configure the connection
		$this->query('SET QUOTED_IDENTIFIER ON');
		
		//Enable full text search.
		$this->createFullTextCatalog();
	}
	
	/**
	 * This will set up the full text search capabilities.
	 * Theoretically, you don't need to 'enable' it every time...
	 *
	 * TODO: make this a _config.php setting
	 */
	function createFullTextCatalog(){
			
		$this->query("exec sp_fulltext_database 'enable';");
		
		$result=$this->query("SELECT name FROM sys.fulltext_catalogs;");
		if(!$result)
			$this->query("CREATE FULLTEXT CATALOG {$GLOBALS['database']};");
		
	}
	/**
	 * Not implemented, needed for PDO
	 */
	public function getConnect($parameters) {
		return null;
	}
	
	/**
	 * Returns true if this database supports collations
	 * @return boolean
	 */
	public function supportsCollations() {
		return true;
	}
	
	/**
	 * The version of MSSQL
	 * @var float
	 */
	private $mssqlVersion;
	
	/**
	 * Get the version of MySQL.
	 * @return float
	 */
	public function getVersion() {
		user_error("getVersion not implemented", E_USER_WARNING);
		return 2008;
		/*
		if(!$this->pgsqlVersion) {
			//returns something like this: PostgreSQL 8.3.3 on i386-apple-darwin9.3.0, compiled by GCC i686-apple-darwin9-gcc-4.0.1 (GCC) 4.0.1 (Apple Inc. build 5465)
			$postgres=strlen('PostgreSQL ');
			$db_version=$this->query("SELECT VERSION()")->value();
			
			$this->pgsqlVersion = (float)trim(substr($db_version, $postgres, strpos($db_version, ' on ')));
		}
		return $this->pgsqlVersion;
		*/
	}
	
	/**
	 * Get the database server, namely mysql.
	 * @return string
	 */
	public function getDatabaseServer() {
		return "mssql";
	}
	
	public function query($sql, $errorLevel = E_USER_ERROR) {
		if(isset($_REQUEST['previewwrite']) && in_array(strtolower(substr($sql,0,strpos($sql,' '))), array('insert','update','delete','replace'))) {
			Debug::message("Will execute: $sql");
			return;
		}

		if(isset($_REQUEST['showqueries'])) { 
			$starttime = microtime(true);
		}
				
		//echo 'sql: ' . $sql . '<br>';
		//Debug::backtrace();
		
		$handle = mssql_query($sql, $this->dbConn);
		
		if(isset($_REQUEST['showqueries'])) {
			$endtime = round(microtime(true) - $starttime,4);
			Debug::message("\n$sql\n{$endtime}ms\n", false);
		}
		
		DB::$lastQuery=$handle;
		
		if(!$handle && $errorLevel) $this->databaseError("Couldn't run query: $sql", $errorLevel);
		return new MSSQLQuery($this, $handle);
	}
	
	public function getGeneratedID($table) {
		return $this->query("SELECT @@IDENTITY FROM \"$table\"")->value();
	}
	
	/**
	 * OBSOLETE: Get the ID for the next new record for the table.
	 * 
	 * @var string $table The name od the table.
	 * @return int
	 */
	public function getNextID($table) {
		user_error('getNextID is OBSOLETE (and will no longer work properly)', E_USER_WARNING);
		$result = $this->query("SELECT MAX(ID)+1 FROM \"$table\"")->value();
		return $result ? $result : 1;
	}
	
	public function isActive() {
		return $this->active ? true : false;
	}
	
	public function createDatabase() {
		$this->query("CREATE DATABASE $this->database");
	}

	/**
	 * Drop the database that this object is currently connected to.
	 * Use with caution.
	 */
	public function dropDatabase() {
		$this->query("DROP DATABASE $this->database");
	}
	
	/**
	 * Returns the name of the currently selected database
	 */
	public function currentDatabase() {
		return $this->database;
	}
	
	/**
	 * Switches to the given database.
	 * If the database doesn't exist, you should call createDatabase() after calling selectDatabase()
	 */
	public function selectDatabase($dbname) {
		$this->database = $dbname;
		if($this->databaseExists($this->database)) mysql_select_db($this->database, $this->dbConn);
		$this->tableList = $this->fieldList = $this->indexList = null;
	}

	/**
	 * Returns true if the named database exists.
	 */
	public function databaseExists($name) {
		$SQL_name = Convert::raw2sql($name);
		return $this->query("SHOW DATABASES LIKE '$SQL_name'")->value() ? true : false;
	}
	
	public function createTable($tableName, $fields = null, $indexes = null) {
		$fieldSchemas = $indexSchemas = "";
		if($fields) foreach($fields as $k => $v) $fieldSchemas .= "\"$k\" $v,\n";
		
		$this->query("CREATE TABLE \"$tableName\" (
				$fieldSchemas
				primary key (\"ID\")
			);");

		//we need to generate indexes like this: CREATE INDEX IX_vault_to_export ON vault (to_export);
		//This needs to be done AFTER the table creation, so we can set up the fulltext indexes correctly
		if($indexes) foreach($indexes as $k => $v) $indexSchemas .= $this->getIndexSqlDefinition($tableName, $k, $v) . "\n";
		 
		$this->query($indexSchemas);
		
	}

	/**
	 * Alter a table's schema.
	 * @param $table The name of the table to alter
	 * @param $newFields New fields, a map of field name => field schema
	 * @param $newIndexes New indexes, a map of index name => index type
	 * @param $alteredFields Updated fields, a map of field name => field schema
	 * @param $alteredIndexes Updated indexes, a map of index name => index type
	 */
	public function alterTable($tableName, $newFields = null, $newIndexes = null, $alteredFields = null, $alteredIndexes = null) {
		$fieldSchemas = $indexSchemas = "";
		
		$alterList = array();
		if($newFields) foreach($newFields as $k => $v) $alterList[] .= "ADD \"$k\" $v";
		
		if($alteredFields) {
			foreach($alteredFields as $k => $v) {
				
				$val=$this->alterTableAlterColumn($tableName, $k, $v);
				if($val!='')
					$alterList[] .= $val;
			}
		}
		
		//DB ABSTRACTION: we need to change the constraints to be a separate 'add' command,
		//see http://www.postgresql.org/docs/8.1/static/sql-altertable.html
		$alterIndexList=Array();
		if($alteredIndexes) foreach($alteredIndexes as $v) {
			
			if($v['type']!='fulltext'){
				if(is_array($v))
					$alterIndexList[] = 'DROP INDEX ix_' . strtolower($tableName) . '_' . strtolower($v['value']) . ' ON ' . $tableName . ';';
				else
					$alterIndexList[] = 'DROP INDEX ix_' . strtolower($tableName) . '_' . strtolower(trim($v, '()')) . ' ON ' . $tableName . ';';
							
				if(is_array($v))
					$k=$v['value'];
				else $k=trim($v, '()');
				
				$alterIndexList[] = $this->getIndexSqlDefinition($tableName, $k, $v);
			}
 		}
 		
 		//Add the new indexes:
 		if($newIndexes) foreach($newIndexes as $k=>$v){
 			$alterIndexList[] = $this->getIndexSqlDefinition($tableName, $k, $v);
 		}

 		if($alterList) {
			$alterations = implode(",\n", $alterList);
			$this->query("ALTER TABLE \"$tableName\" " . $alterations);
		}
		
		foreach($alterIndexList as $alteration)
			if($alteration!='')
				$this->query($alteration);
	}
	
	/*
	 * Creates an ALTER expression for a column in MS SQL
	 * 
	 * @param $tableName Name of the table to be altered
	 * @param $colName   Name of the column to be altered
	 * @param $colSpec   String which contains conditions for a column
	 * @return string
	 */
	private function alterTableAlterColumn($tableName, $colName, $colSpec){
		// Alterations not implemented
		return;
		
		// First, we split the column specifications into parts
		// TODO: this returns an empty array for the following string: int(11) not null auto_increment
		//		 on second thoughts, why is an auto_increment field being passed through?
		
		$pattern = '/^([\w()]+)\s?((?:not\s)?null)?\s?(default\s[\w\']+)?\s?(check\s[\w()\'",\s]+)?$/i';
		preg_match($pattern, $colSpec, $matches);
		
		if(isset($matches[1])) {
			$alterCol = "ALTER COLUMN \"$colName\" TYPE $matches[1]\n";
		
			// SET null / not null
			if(!empty($matches[2])) $alterCol .= ",\nALTER COLUMN \"$colName\" SET $matches[2]";
			
			// SET default (we drop it first, for reasons of precaution)
			if(!empty($matches[3])) {
				$alterCol .= ",\nALTER COLUMN \"$colName\" DROP DEFAULT";
				$alterCol .= ",\nALTER COLUMN \"$colName\" SET $matches[3]";
			}
			
			// SET check constraint (The constraint HAS to be dropped)
			if(!empty($matches[4])) {
				$alterCol .= ",\nDROP CONSTRAINT \"{$tableName}_{$colName}_check\"";
				$alterCol .= ",\nADD CONSTRAINT \"{$tableName}_{$colName}_check\" $matches[4]";
			}
		}
		
		return isset($alterCol) ? $alterCol : '';
	}
	
	public function renameTable($oldTableName, $newTableName) {
		$this->query("ALTER TABLE \"$oldTableName\" RENAME \"$newTableName\"");
	}
	
	/**
	 * Checks a table's integrity and repairs it if necessary.
	 * @var string $tableName The name of the table.
	 * @return boolean Return true if the table has integrity after the method is complete.
	 */
	public function checkAndRepairTable($tableName) {
		/*
		$this->runTableCheckCommand("VACUUM FULL \"$tableName\"");
		*/
		return true;
	}
	
	/**
	 * Helper function used by checkAndRepairTable.
	 * @param string $sql Query to run.
	 * @return boolean Returns if the query returns a successful result.
	 */
	protected function runTableCheckCommand($sql) {
		$testResults = $this->query($sql);
		foreach($testResults as $testRecord) {
			if(strtolower($testRecord['Msg_text']) != 'ok') {
				return false;
			}
		}
		return true;
	}
	
	public function createField($tableName, $fieldName, $fieldSpec) {
		$this->query("ALTER TABLE \"$tableName\" ADD \"$fieldName\" $fieldSpec");
	}
	
	/**
	 * Change the database type of the given field.
	 * @param string $tableName The name of the tbale the field is in.
	 * @param string $fieldName The name of the field to change.
	 * @param string $fieldSpec The new field specification
	 */
	public function alterField($tableName, $fieldName, $fieldSpec) {
		$this->query("ALTER TABLE \"$tableName\" CHANGE \"$fieldName\" \"$fieldName\" $fieldSpec");
	}

	/**
	 * Change the database column name of the given field.
	 * 
	 * @param string $tableName The name of the tbale the field is in.
	 * @param string $oldName The name of the field to change.
	 * @param string $newName The new name of the field
	 */
	public function renameField($tableName, $oldName, $newName) {
		$fieldList = $this->fieldList($tableName);
		if(array_key_exists($oldName, $fieldList)) {
			$this->query("ALTER TABLE \"$tableName\" RENAME COLUMN \"$oldName\" TO \"$newName\"");
		}
	}
	
	public function fieldList($table) {
		//Query from http://www.alberton.info/postgresql_meta_info.html
		//This gets us more information than we need, but I've included it all for the moment....
		$fields = $this->query("SELECT ordinal_position, column_name, data_type, column_default, is_nullable, character_maximum_length, numeric_precision FROM information_schema.columns WHERE table_name = '$table' ORDER BY ordinal_position;");
		
		$output = array();
		if($fields) foreach($fields as $field) {
			switch($field['data_type']){
				case 'varchar':
					//Check to see if there's a constraint attached to this column:
					//$constraint=$this->query("SELECT conname,pg_catalog.pg_get_constraintdef(r.oid, true) FROM pg_catalog.pg_constraint r WHERE r.contype = 'c' AND conname='" . $table . '_' . $field['column_name'] . "_check' ORDER BY 1;")->first();
					$constraint=$this->query("SELECT CHECK_CLAUSE, COLUMN_NAME FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS AS CC INNER JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE AS CCU ON CCU.CONSTRAINT_NAME=CC.CONSTRAINT_NAME WHERE TABLE_NAME='$table' AND COLUMN_NAME='" . $field['column_name'] . "';")->first();
					if($constraint){
						//Now we need to break this constraint text into bits so we can see what we have:
						//Examples:
						//([PasswordEncryption] = 'crc32b' OR [PasswordEncryption] = 'crc32' OR [PasswordEncryption] = 'adler32' OR [PasswordEncryption] = 'gost' OR [PasswordEncryption] = 'snefru' OR [PasswordEncryption] = 'whirlpool' OR [PasswordEncryption] = 'ripemd320' OR [PasswordEncryption] = 'ripemd256' OR [PasswordEncryption] = 'ripemd160' OR [PasswordEncryption] = 'ripemd128' OR [PasswordEncryption] = 'sha512' OR [PasswordEncryption] = 'sha384' OR [PasswordEncryption] = 'sha256' OR [PasswordEncryption] = 'sha1' OR [PasswordEncryption] = 'md5' OR [PasswordEncryption] = 'md4' OR [PasswordEncryption] = 'md2' OR [PasswordEncryption] = 'none')
						//([ClassName] = 'Member')
						
						//TODO: replace all this with a regular expression!
						$value=$constraint['CHECK_CLAUSE'];
						
						$segments=explode(' OR [', $value);
						$constraints=Array();
						foreach($segments as $this_segment){
							$bits=explode(' = ', $this_segment);
							
							for($i=1; $i<sizeof($bits); $i+=2)
								array_unshift($constraints, substr(rtrim($bits[$i], ')'), 1, -1));
							
						}
						$default=substr($field['column_default'], 2, -2);
						$field['data_type']=$this->enum(Array('default'=>$default, 'name'=>$field['column_name'], 'enums'=>$constraints));
					}
					
					$output[$field['column_name']]=$field;
					break;
				default:
					$output[$field['column_name']] = $field;
			}
			
		}
		
		return $output;
	}
	
	/**
	 * Create an index on a table.
	 * @param string $tableName The name of the table.
	 * @param string $indexName The name of the index.
	 * @param string $indexSpec The specification of the index, see Database::requireIndex() for more details.
	 */
	public function createIndex($tableName, $indexName, $indexSpec) {
		$this->query($this->getIndexSqlDefinition($tableName, $indexName, $indexSpec));
	}
	
	/*
	 * This takes the index spec which has been provided by a class (ie static $indexes = blah blah)
	 * and turns it into a proper string.
	 * Some indexes may be arrays, such as fulltext and unique indexes, and this allows database-specific
	 * arrays to be created.
	 */
	public function convertIndexSpec($indexSpec){
		if(is_array($indexSpec)){
			//Here we create a db-specific version of whatever index we need to create.
			switch($indexSpec['type']){
				case 'fulltext':
					$indexSpec='fulltext (' . str_replace(' ', '', $indexSpec['value']) . ')';
					break;
				case 'unique':
					$indexSpec='unique (' . $indexSpec['value'] . ')';
					break;
			}
		}
		
		return $indexSpec;
	}
	
	protected function getIndexSqlDefinition($tableName, $indexName, $indexSpec) {
	    
		if(!is_array($indexSpec)){
			$indexSpec=trim($indexSpec, '()');
			$bits=explode(',', $indexSpec);
			$indexes="\"" . implode("\",\"", $bits) . "\"";
			
			return 'create index ix_' . $tableName . '_' . $indexName . " ON \"" . $tableName . "\" (" . $indexes . ");";
		} else {
			//create a type-specific index
			if($indexSpec['type']=='fulltext'){
				//We need the name of the primary key for this table:
				//TODO: There MUST be a better way of doing this.... shurely....
				//$primary_key=$this->query("SELECT [name] FROM syscolumns WHERE [id] IN (SELECT [id] FROM sysobjects WHERE [name] = '$tableName') AND colid IN (SELECT SIK.colid FROM sysindexkeys SIK JOIN sysobjects SO ON SIK.[id] = SO.[id] WHERE SIK.indid = 1 AND SO.[name] = '$tableName');")->first();
				$indexes=DB::query("EXEC sp_helpindex '$tableName';");
				foreach($indexes as $this_index){
					if($this_index['index_keys']=='ID'){
						$primary_key=$this_index['index_name'];
						break;
					}
				}
				
				//First, we need to see if a full text search already exists:
				$result=$this->query("SELECT object_id FROM sys.fulltext_indexes WHERE object_id=object_id('$tableName');")->first();
				
				$drop='';
				if($result)
					$drop="DROP FULLTEXT INDEX ON \"" . $tableName . "\";";
				
				return $drop . "CREATE FULLTEXT INDEX ON \"$tableName\"	({$indexSpec['value']})	KEY INDEX $primary_key ON {$GLOBALS['database']}	WITH CHANGE_TRACKING AUTO;";
			
			}
									
			if($indexSpec['type']=='unique')
				return 'create unique index ix_' . $tableName . '_' . $indexName . " ON \"" . $tableName . "\" (\"" . $indexSpec['value'] . "\");";
		}
		
	}
	
	function getDbSqlDefinition($tableName, $indexName, $indexSpec){
		return $indexName;
	}
	
	/**
	 * Alter an index on a table.
	 * @param string $tableName The name of the table.
	 * @param string $indexName The name of the index.
	 * @param string $indexSpec The specification of the index, see Database::requireIndex() for more details.
	 */
	public function alterIndex($tableName, $indexName, $indexSpec) {
	    $indexSpec = trim($indexSpec);
	    if($indexSpec[0] != '(') {
	    	list($indexType, $indexFields) = explode(' ',$indexSpec,2);
	    } else {
	    	$indexFields = $indexSpec;
	    }
	    
	    if(!$indexType) {
	    	$indexType = "index";
	    }
    
	    $this->query("DROP INDEX $indexName ON $tableName;");
		$this->query("ALTER TABLE \"$tableName\" ADD $indexType \"$indexName\" $indexFields");
	}
	
	/**
	 * Return the list of indexes in a table.
	 * @param string $table The table name.
	 * @return array
	 */
	public function indexList($table) {
		$indexes=DB::query("EXEC sp_helpindex '$table';");
		
		
		foreach($indexes as $index) {
			
			//Check for uniques:
			if(strpos($index['index_description'], 'unique')!==false)
				$prefix='unique ';
			
			$key=str_replace(', ', ',', $index['index_keys']);
			$indexList[$key]['indexname']=$index['index_name'];
			$indexList[$key]['spec']=$prefix . '(' . $key . ')';
  		  			
  		}
  		
  		//Now we need to check to see if we have any fulltext indexes attached to this table:
  		$result=DB::query('EXEC sp_help_fulltext_columns;');
  		$columns='';
  		foreach($result as $row){
  			
  			if($row['TABLE_NAME']==$table)
  				$columns.=$row['FULLTEXT_COLUMN_NAME'] . ',';	
  			
  		}
  		
  		if($columns!=''){
  			$columns=trim($columns, ',');
  			$indexList['SearchFields']['indexname']='SearchFields';
  			$indexList['SearchFields']['spec']='fulltext (' . $columns . ')';
  		}

  		return isset($indexList) ? $indexList : null;
		
	}

	/**
	 * Returns a list of all the tables in the database.
	 * Table names will all be in lowercase.
	 * @return array
	 */
	public function tableList() {
		foreach($this->query("EXEC sp_tables") as $record) {
			$table = strtolower($record['TABLE_NAME']);
			$tables[$table] = $table;
		}
		return isset($tables) ? $tables : null;
	}
	
	/**
	 * Return the number of rows affected by the previous operation.
	 * @return int
	 */
	public function affectedRows() {
		return mssql_rows_affected($this->dbConn);
	}
	
	/**
	 * A function to return the field names and datatypes for the particular table
	 */
	public function tableDetails($tableName){
		user_error("tableDetails not implemented", E_USER_WARNING);
		return array();
		/*
		$query="SELECT a.attname as \"Column\", pg_catalog.format_type(a.atttypid, a.atttypmod) as \"Datatype\" FROM pg_catalog.pg_attribute a WHERE a.attnum > 0 AND NOT a.attisdropped AND a.attrelid = ( SELECT c.oid FROM pg_catalog.pg_class c LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE c.relname ~ '^($tableName)$' AND pg_catalog.pg_table_is_visible(c.oid));";
		$result=DB::query($query);
		
		$table=Array();
		while($row=pg_fetch_assoc($result)){
			$table[]=Array('Column'=>$row['Column'], 'DataType'=>$row['DataType']);
		}
		
		return $table;
		*/
	}
	
	/**
	 * Return a boolean type-formatted string
	 * We use 'bit' so that we can do numeric-based comparisons
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function boolean($values, $asDbValue=false){
		//Annoyingly, we need to do a good ol' fashioned switch here:
		($values['default']) ? $default='1' : $default='0';
		
		if($asDbValue)
			return 'bit';
		else
			return 'bit not null default ' . $default;
	}
	
	/**
	 * Return a date type-formatted string
	 * For MySQL, we simply return the word 'date', no other parameters are necessary
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function date($values, $asDbValue=false){
		if($asDbValue)
			return 'date';
		else
			return 'date null';
	}
	
	/**
	 * Return a decimal type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function decimal($values, $asDbValue=false){
		// Avoid empty strings being put in the db
		if($values['precision'] == '') {
			$precision = 1;
		} else {
			$precision = $values['precision'];
		}
		
		if($asDbValue)
			return Array('data_type'=>'decimal', 'numeric_precision'=>'9,2');
		else return 'decimal(' . $precision . ') not null';
	}
	
	/**
	 * Return a enum type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function enum($values){
		//Enums are a bit different. We'll be creating a varchar(255) with a constraint of all the usual enum options.
		//NOTE: In this one instance, we are including the table name in the values array
		
		return "varchar(255) not null default '" . $values['default'] . "' check (\"" . $values['name'] . "\" in ('" . implode('\', \'', $values['enums']) . "'))";
		
	}
	
	/**
	 * Return a float type-formatted string
	 * For MySQL, we simply return the word 'date', no other parameters are necessary
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function float($values, $asDbValue=false){
		if($asDbValue)
			return Array('data_type'=>'float');
		else return 'float';
	}
	
	/**
	 * Return a int type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function int($values, $asDbValue=false){
		//We'll be using an 8 digit precision to keep it in line with the serial8 datatype for ID columns
		if($asDbValue)
			return Array('data_type'=>'numeric', 'numeric_precision'=>'8');
		else
			return 'numeric(8) not null default ' . (int)$values['default'];
	}
	
	/**
	 * Return a datetime type-formatted string
	 * For MS SQL, we simply return the word 'timestamp', no other parameters are necessary
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function ssdatetime($values, $asDbValue=false){
		if($asDbValue)
			return Array('data_type'=>'datetime');
		else
			return 'datetime null';
	}
	
	/**
	 * Return a text type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function text($values, $asDbValue=false){
		if($asDbValue)
			return Array('data_type'=>'text');
		else
			return 'text null';
	}
	
	/**
	 * Return a time type-formatted string
	 * For MySQL, we simply return the word 'time', no other parameters are necessary
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function time($values){
		return 'time';
	}
	
	/**
	 * Return a varchar type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function varchar($values, $asDbValue=false){
		if($asDbValue)
			return Array('data_type'=>'varchar', 'character_maximum_length'=>'255');
		else
			return 'varchar(' . $values['precision'] . ') null';
	}
	
	/*
	 * Return a 4 digit numeric type.  MySQL has a proprietary 'Year' type.
	 */
	public function year($values, $asDbValue=false){
		if($asDbValue)
			return Array('data_type'=>'numeric', 'numeric_precision'=>'4');
		else return 'numeric(4)'; 
	}
	
	function escape_character($escape=false){
		if($escape)
			return "\\\"";
		else
			return "\"";
	}
	
	/**
	 * Create a fulltext search datatype for MySQL
	 *
	 * @param array $spec
	 */
	function fulltext($table, $spec){
		//$spec['name'] is the column we've created that holds all the words we want to index.
		//This is a coalesced collection of multiple columns if necessary
		//$spec='create index ix_' . $table . '_' . $spec['name'] . ' on ' . $table . ' using gist(' . $spec['name'] . ');';
		
		//return $spec;
		echo '<span style="color: Red">full text just got called!</span><br>';
		return '';
	}
	
	/**
	 * This returns the column which is the primary key for each table
	 * In Postgres, it is a SERIAL8, which is the equivalent of an auto_increment
	 *
	 * @return string
	 */
	function IdColumn($asDbValue=false, $hasAutoIncPK=true){
		
		if($asDbValue)
			return 'bigint';
		else {
			if($hasAutoIncPK)
				return 'bigint identity(1,1)';
			else return 'bigint';
		}
	}
	
	/**
	 * Returns the SQL command to get all the tables in this database
	 */
	function allTablesSQL(){
		return "SELECT name FROM {$GLOBALS['database']}..sysobjects WHERE xtype = 'U';";
	}
	
	/**
	 * Returns true if this table exists
	 * @todo Make a proper implementation
	 */
	function hasTable($tableName) {
		return true;
	}
	
	/**
	 * Return enum values for the given field
	 * @todo Make a proper implementation
	 */
	function enumValuesForField($tableName, $fieldName) {
		return array('SiteTree','Page');
	}

	/**
	 * Because NOW() doesn't always work...
	 * MSSQL, I'm looking at you
	 *
	 */
	function now(){
		return 'CURRENT_TIMESTAMP';
	}
	
	/**
	 * Convert a SQLQuery object into a SQL statement
	 * @todo There is a lot of duplication between this and MySQLDatabase::sqlQueryToString().  Perhaps they could both call a common
	 * helper function in Database?
	 */
	public function sqlQueryToString(SQLQuery $sqlQuery) {
		if (!$sqlQuery->from) return '';
		$distinct = $sqlQuery->distinct ? "DISTINCT " : "";
		if($sqlQuery->delete) {
			$text = "DELETE ";
		} else if($sqlQuery->select) {
			$text = "SELECT $distinct" . implode(", ", $sqlQuery->select);
		}
		$text .= " FROM " . implode(" ", $sqlQuery->from);

		if($sqlQuery->where) $text .= " WHERE (" . $sqlQuery->getFilter(). ")";
		if($sqlQuery->groupby) $text .= " GROUP BY " . implode(", ", $sqlQuery->groupby);
		if($sqlQuery->having) $text .= " HAVING ( " . implode(" ) AND ( ", $sqlQuery->having) . " )";
		if($sqlQuery->orderby) $text .= " ORDER BY " . $sqlQuery->orderby;

		// Limit not implemented
		
		/*if($sqlQuery->limit) {
			*/
			/*
			 * For MSSQL, we need to do something different since it doesn't support LIMIT OFFSET as most normal
			 * databases do
			 * 
			 * select * from (
				    select row_number() over (order by PrimaryKeyId) as number, * from MyTable
				) as numbered
				where number between 21 and 30
			 */
			
			/*$limit = $sqlQuery->limit;
			// Pass limit as array or SQL string value
			if(is_array($limit)) {
				if(!array_key_exists('limit',$limit)) user_error('SQLQuery::limit(): Wrong format for $limit', E_USER_ERROR);

				if(isset($limit['start']) && is_numeric($limit['start']) && isset($limit['limit']) && is_numeric($limit['limit'])) {
					$combinedLimit = (int)$limit['start'] . ',' . (int)$limit['limit'];
				} elseif(isset($limit['limit']) && is_numeric($limit['limit'])) {
					$combinedLimit = (int)$limit['limit'];
				} else {
					$combinedLimit = false;
				}
				if(!empty($combinedLimit)) $this->limit = $combinedLimit;

			} else {
				$text .= " LIMIT " . $sqlQuery->limit;
			}
		}
		*/
		
		return $text;
	}
	
	/*
	 * This will return text which has been escaped in a database-friendly manner
	 * Using PHP's addslashes method won't work in MSSQL
	 */
	function addslashes($value){
		$value=stripslashes($value);
    	$value=str_replace("'","''",$value);
    	$value=str_replace("\0","[NULL]",$value);

    	return $value;
	}
}

/**
 * A result-set from a MySQL database.
 * @package sapphire
 * @subpackage model
 */
class MSSQLQuery extends Query {
	/**
	 * The MySQLDatabase object that created this result set.
	 * @var MySQLDatabase
	 */
	private $database;
	
	/**
	 * The internal MySQL handle that points to the result set.
	 * @var resource
	 */
	private $handle;

	/**
	 * Hook the result-set given into a Query class, suitable for use by sapphire.
	 * @param database The database object that created this query.
	 * @param handle the internal mysql handle that is points to the resultset.
	 */
	public function __construct(MSSQLDatabase $database, $handle) {
		$this->database = $database;
		$this->handle = $handle;
		parent::__construct();
	}
	
	public function __destroy() {
		//mysql_free_result($this->handle);
	}
	
	public function seek($row) {
		//return mysql_data_seek($this->handle, $row);
		//This is unnecessary in postgres.  You can just provide a row number with the fetch
		//command.
	}
	
	public function numRecords() {
		return mssql_num_rows($this->handle);
	}
	
	public function nextRecord() {
		// Coalesce rather than replace common fields.
		if($data = mssql_fetch_row($this->handle)) {
			foreach($data as $columnIdx => $value) {
				$columnName = mssql_field_name($this->handle, $columnIdx);
				// $value || !$ouput[$columnName] means that the *last* occurring value is shown
				// !$ouput[$columnName] means that the *first* occurring value is shown
				if(isset($value) || !isset($output[$columnName])) {
					$output[$columnName] = $value;
				}
			}
			return $output;
		} else {
			return false;
		}
	}
	
	
}

?>