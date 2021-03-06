<?php
/*
 * Copyright (C) 2014 Blackmoon Info Tech Services
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace BitsTheater\costumes;
use BitsTheater\costumes\ABitsCostume as BaseCostume;
use BitsTheater\Director;
use BitsTheater\Model;
use BitsTheater\costumes\ISqlSanitizer;
use com\blackmoonit\exceptions\DbException;
use PDO;
use PDOStatement;
use PDOException;
{//namespace begin

/**
 * Models can use this class to help build their SQL queries / PDO statements.
 * Class built using MySQL, but hope to expand it so it tolerates others, too.
 */
class SqlBuilder extends BaseCostume {
	/**
	 * 5 digit code meaning "successful completion/no error".
	 * @var string
	 */
	const SQLSTATE_SUCCESS = PDO::ERR_NONE;
	/**
	 * 5 digit code meaning "no data"; e.g. UPDATE/DELETE failed due
	 * to record(s) defined by WHERE clause returned no rows at all.
	 * @var string
	 */
	const SQLSTATE_NO_DATA = '02000';
	/**
	 * 5 digit ANSI SQL code meaning a table referenced in the SQL
	 * does not exist.
	 * @var unknown
	 */
	const SQLSTATE_TABLE_DOES_NOT_EXIST = '42S02';
	/**
	 * The SQL element meaning ascending order when sorting.
	 * @var string
	 */
	const ORDER_BY_ASCENDING = 'ASC';
	/**
	 * The SQL element meaning descending order when sorting.
	 * @var string
	 */
	const ORDER_BY_DESCENDING = 'DESC';
	/**
	 * Sometimes we have a nested query in field list. So in order for
	 * {@link SqlBuilder::getQueryTotals()} to work automatically, we need to
	 * supply a comment hint to start the field list.
	 * @var string
	 */
	const FIELD_LIST_HINT_START = '/* FIELDLIST */';
	/**
	 * Sometimes we have a nested query in field list. So in order for
	 * {@link SqlBuilder::getQueryTotals()} to work automatically, we need to
	 * supply a comment hint to end the field list.
	 * @var string
	 */
	const FIELD_LIST_HINT_END = '/* /FIELDLIST */';
	/**
	 * The model class, so we can pass-thru some method calls like query.
	 * Also useful for auto-determining database quirks like the char used
	 * around field names.
	 * @var \BitsTheater\Model
	 */
	public $myModel = null;
	/**
	 * The object used to sanitize field/orderby lists to help prevent
	 * SQL injection attacks.
	 * @var ISqlSanitizer
	 */
	public $mySqlSanitizer = null;
	
	public $mySql = '';
	public $myParams = array();
	public $myDataSet = null;
	
	public $myParamTypes = array();
	public $myParamFuncs = array();
	
	/**
	 * The char used around field names in case of spaces and keyword clashes.
	 * Determined by the database type being used (MySQL vs Oracle, etc.).
	 * @var string
	 */
	public $field_quotes = '`';
	/**
	 * Using the "=" when NULL is involved is ambiguous unless you know
	 * if it is part of a SET clause or WHERE clause.  Explicitly set
	 * this flag to let the SqlBuilder know it is in a WHERE clause.
	 * @var boolean
	 */
	public $bUseIsNull = false;
	
	/**
	 * Temporary value used while constructing the SQL string.
	 * @var string
	 */
	public $myParamPrefix = ' ';
	/**
	 * Temporary value used while constructing the SQL string.
	 * @var string
	 */
	public $myParamOperator = '=';
	
	/**
	 * Magic PHP method to limit what var_dump() shows.
	 */
	public function __debugInfo() {
		$vars = parent::__debugInfo();
		unset($vars['myModel']);
		unset($vars['mySqlSanitizer']);
		unset($vars['myParamPrefix']);
		unset($vars['myParamOperator']);
		return $vars;
	}
	
	/**
	 * Models can use this class to help build their SQL queries / PDO statements.
	 * @param \BitsTheater\Model $aModel - the model being used.
	 * @return \BitsTheater\costumes\SqlBuilder Returns the created object.
	 */
	static public function withModel(Model $aModel) {
		$theClassName = get_called_class();
		$o = new $theClassName($aModel->director);
		return $o->setModel($aModel);
	}
	
	/**
	 * Sets the model being used along with initializing database specific quirks.
	 * @param \BitsTheater\Model $aModel
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function setModel(Model $aModel) {
		$this->myModel = $aModel;
		switch ($this->myModel->dbType()) {
			case Model::DB_TYPE_MYSQL:
			default:
				$this->field_quotes = '`';
		}//switch
		return $this;
	}
	
	/**
	 * Sets the dataset to be used by addParam methods.
	 * @param array or StdClass $aDataSet - array or class used to get param data.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 * @deprecated Please use {@link SqlBuilder::obtainParamsFrom()}
	 */
	public function setDataSet($aDataSet) {
		$this->myDataSet = $aDataSet;
		return $this;
	}
	
	/**
	 * Sets the dataset to be used by addParam methods. Alias for setDataSet().
	 * @param array or StdClass $aDataSet - array or class used to get param data.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function obtainParamsFrom($aDataSet) {
		$this->myDataSet = $aDataSet;
		return $this;
	}
	
	/**
	 * Set the object used for sanitizing SQL to help prevent SQL Injection attacks.
	 * @param ISqlSanitizer $aSqlSanitizer - the object used to sanitize field/orderby lists.
	 * @return $this Returns $this for chaining.
	 */
	public function setSanitizer( ISqlSanitizer $aSanitizer=null )
	{
		$this->mySqlSanitizer = $aSanitizer;
		return $this;
	}
	
	/**
	 * Mainly used internally to get param data.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method.
	 * @param string $aDefaultValue - (optional) default value if data is null.
	 * @return mixed Returns the data value.
	 * @see \BitsTheater\costumes\SqlBuilder::obtainParamsFrom()
	 */
	public function getDataValue($aDataKey, $aDefaultValue=null) {
		$theData = $aDefaultValue;
		if (isset($this->myDataSet)) {
			$theDataSet = $this->myDataSet;
			if (is_array($theDataSet)) {
				if (isset($theDataSet[$aDataKey])) {
					$theData = $theDataSet[$aDataKey];
				}
			} else if (is_object($theDataSet)) {
				if (isset($theDataSet->$aDataKey)) {
					$theData = $theDataSet->$aDataKey;
				}
			} else if (is_string($theDataSet)) {
				$theData = $theDataSet;
			}
		}
		//see if there is a data processing function and call it
		if (isset($this->myParamFuncs[$aDataKey])) {
			$theData = $this->myParamFuncs[$aDataKey]($this, $aDataKey, $theData);
		}
		return $theData;
	}
	
	/**
	 * Mainly used internally by addParamIfDefined to determine if data param exists.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method.
	 * @return boolean Returns TRUE if data key is defined (or param function exists).
	 * @see \BitsTheater\costumes\SqlBuilder::obtainParamsFrom()
	 */
	public function isDataKeyDefined($aDataKey) {
		//see if there is a data processing function
		$bResult = (isset($this->myParamFuncs[$aDataKey]));
		if (!$bResult && isset($this->myDataSet)) {
			$theDataSet = $this->myDataSet;
			if (is_array($theDataSet)) {
				$bResult = array_key_exists($aDataKey, $theDataSet);
			} else if (is_object($theDataSet)) {
				$bResult = property_exists($theDataSet, $aDataKey);
			} else if (is_string($theDataSet)) {
				$bResult = true;
			}
		}
		return $bResult;
	}
	
	/**
	 * Resets the object so it can be resused with same dataset.
	 * @param boolean $bClearDataFunctionsToo - (optional) clear data processing
	 * functions as well (default is FALSE).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function reset($bClearDataFunctionsToo=false) {
		$this->myParamPrefix = ' ';
		$this->myParamOperator = '=';
		$this->mySql = '';
		$this->myParams = array();
		$this->myParamTypes = array();
		if ($bClearDataFunctionsToo)
			$this->myParamFuncs = array();
		return $this;
	}
	
	/**
	 * Sets the param value and param type, but does not affect the SQL string.
	 * @param string $aParamKey - the field/param name.
	 * @param string $aParamValue - the param value to use.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function setParam($aParamKey, $aParamValue, $aParamType=null) {
		if (isset($aParamValue)) {
			$this->myParams[$aParamKey] = $aParamValue;
			if (isset($aParamType)) {
				$this->myParamTypes[$aParamKey] = $aParamType;
			} else if (!isset($this->myParamTypes[$aParamKey])) {
				$this->myParamTypes[$aParamKey] = PDO::PARAM_STR;
			}
		} else {
			$this->myParams[$aParamKey] = null;
			$this->myParamTypes[$aParamKey] = PDO::PARAM_NULL;
		}
		return $this;
	}
	
	/**
	 * Gets the current value of a param that has been added.
	 * @param string $aParamKey - the param key to retrieve.
	 * @return multitype Returns the data.
	 */
	public function getParam($aParamKey) {
		if (isset($this->myParams[$aParamKey]))
			return $this->myParams[$aParamKey];
	}
	
	/**
	 * Sets the SQL string to this value.
	 * @param string $aSql - the first part of a SQL string to build upon.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function startWith($aSql) {
		$this->mySql = $aSql;
		return $this;
	}
	
	/**
	 * Adds the fieldlist to the SQL string.
	 * @param array|string $aFieldList - the list or comma string of fieldnames.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function addFieldList($aFieldList)
	{
		$theFieldList = '*' ;
		if( !empty($aFieldList) )
		{
			if( is_array($aFieldList) )
			{
				if( !empty($this->myParamPrefix) )
				{ // ensure prefixes are applied
					$thePrefixedFieldList = array() ;
					foreach( $aFieldList as $aField )
					{
						if( strpos( $aField, $this->myParamPrefix ) !== 0 )
							$thePrefixedFieldList[] =
								$this->myParamPrefix . $aField ;
						else
							$thePrefixedFieldList[] = $aField ;
					}
					$theFieldList = implode( ', ', $thePrefixedFieldList ) ;
				}
				else
					$theFieldList = implode( ', ', $aFieldList ) ;
			}
			else if( is_string($aFieldList) )
			{ // add it as-is; let the caller beware
				$theFieldList = $aFieldList ;
			}
		}
		else if( !empty( $this->myParamPrefix ) )
		{
			$theFieldList = $this->myParamPrefix . '*' ;
		}
		return $this->add($theFieldList) ;
	}
	
	/**
	 * Adds to the SQL string as a set of values; e.g. "(datakey_1,datakey_2, .. datakey_N)"
	 * along with param values set for each of the keys.
	 * Honors the ParamPrefix and ParamOperator properties.
	 * @param string $aFieldName - the field name.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method; NOTE: $aDataKeys will have _$i from 1..count() appended.
	 * @param array $aDataValuesList - the value list to use as param values.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function mustAddParamAsList($aFieldName, $aDataKey, $aDataValuesList, $aParamType=PDO::PARAM_STR) {
		if (is_array($aDataValuesList) && !empty($aDataValuesList)) {
			$this->mySql .= $this->myParamPrefix.$this->field_quotes.$aFieldName.$this->field_quotes.$this->myParamOperator.'(';
			$theList = $aDataValuesList;
			for ($i=0; $i < count($theList); $i++) {
				$theDataKey = $aDataKey.'_'.$i;
				$this->mySql .= ':'.$theDataKey.(($i < count($theList)-1) ? ',' : ')');
				$this->setParam($theDataKey,$theList[$i],$aParamType);
			}
			return $this;
		} else {
			return $this->mustAddParam($aDataKey, null, $aParamType);
		}
	}
	
	/**
	 * Internal method to affect SQL statment with a param and its value.
	 * @param string $aFieldName - the field name.
	 * @param string $aParamKey - the param name.
	 * @param string $aParamValue - the param value to use.
	 * @param number $aParamType - PDO::PARAM_* integer constant.
	 */
	protected function addingParam($aFieldName, $aParamKey, $aParamValue, $aParamType) {
		if (!is_array($aParamValue) || empty($aParamValue)) {
			if (!is_null($aParamValue) || !$this->bUseIsNull) {
				$this->mySql .= $this->myParamPrefix .
						$this->field_quotes . $aFieldName . $this->field_quotes .
						$this->myParamOperator . ':' . $aParamKey ;
				$this->setParam($aParamKey,$aParamValue,$aParamType);
			} else {
				switch (trim($this->myParamOperator)) {
					case '=':
						$this->mySql .= $this->myParamPrefix .
								$this->field_quotes . $aFieldName . $this->field_quotes .
								' IS NULL' ;
						break;
					case '<>':
						$this->mySql .= $this->myParamPrefix .
								$this->field_quotes . $aFieldName . $this->field_quotes .
								' IS NOT NULL' ;
						break;
				}//switch
			}
		} else {
			$saveParamOp = $this->myParamOperator;
			switch (trim($this->myParamOperator)) {
				case '=':
					$this->myParamOperator = ' IN ';
					break;
				case '<>':
					$this->myParamOperator = ' NOT IN ';
					break;
			}//switch
			$this->mustAddParamAsList($aFieldName, $aParamKey, $aParamValue, $aParamType);
			$this->myParamOperator = $saveParamOp;
		}
	}
	
	/**
	 * Parameter must go into the SQL string regardless of NULL status of data.
	 * @param string $aFieldName - field name to use.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method.
	 * @param string $aDefaultValue - (optional) default value if data is null.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function mustAddFieldAndParam($aFieldName, $aDataKey, $aDefaultValue=null, $aParamType=PDO::PARAM_STR) {
		$theData = $this->getDataValue($aDataKey, $aDefaultValue);
		$this->addingParam($aFieldName, $aDataKey, $theData, $aParamType);
		return $this;
	}
	
	/**
	 * Parameter must go into the SQL string regardless of NULL status of data.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method; this doubles as the field name.
	 * @param string $aDefaultValue - (optional) default value if data is null.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function mustAddParam($aDataKey, $aDefaultValue=null, $aParamType=PDO::PARAM_STR) {
		$theData = $this->getDataValue($aDataKey, $aDefaultValue);
		$this->addingParam($aDataKey, $aDataKey, $theData, $aParamType);
		return $this;
	}
	
	/**
	 * Parameter only gets added to the SQL string if data is not NULL. This differs from
	 * addParam() in that you specify the field name and param name to use, in case they
	 * need to be different for some reason, like if you need to update an ID field. <br>
	 * e.g. <code>UPDATE myIDfield=:new_myIDfield_data WHERE myIDfield=:myIDfield</code>
	 * @param string $aFieldName - field name to use.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method.
	 * @param string $aDefaultValue - (optional) default value if data is null.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function addFieldAndParam($aFieldName, $aDataKey, $aDefaultValue=null, $aParamType=PDO::PARAM_STR) {
		$theData = $this->getDataValue($aDataKey, $aDefaultValue);
		if (isset($theData)) {
			$this->addingParam($aFieldName, $aDataKey, $theData, $aParamType);
		}
		return $this;
	}
	
	/**
	 * Parameter gets added to the SQL string if data key exists in data set.
	 * @param string $aFieldName - field name to use.
	 * @param string $aDataKey - array key or property name used to retrieve
	 *     data set by the obtainParamsFrom() method.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function addFieldAndParamIfDefined($aFieldName, $aDataKey, $aParamType=PDO::PARAM_STR)
	{
		if ($this->isDataKeyDefined($aDataKey)) {
			$theData = $this->getDataValue($aDataKey);
			$this->addingParam($aFieldName, $aDataKey, $theData, $aParamType);
		}
		return $this;
	}

	/**
	 * Parameter only gets added to the SQL string if data is not NULL.
	 * @param string $aDataKey - array key or property name used to retrieve
	 * data set by the obtainParamsFrom() method.
	 * @param string $aDefaultValue - (optional) default value if data is null.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function addParam($aDataKey, $aDefaultValue=null, $aParamType=PDO::PARAM_STR) {
		$theData = $this->getDataValue($aDataKey, $aDefaultValue);
		if (isset($theData)) {
			$this->addingParam($aDataKey, $aDataKey, $theData, $aParamType);
		}
		return $this;
	}

	/**
	 * Parameter gets added to the SQL string if data key exists in data set.
	 * @param string $aDataKey - array key or property name used to retrieve
	 *     data set by the obtainParamsFrom() method.
	 * @param number $aParamType - (optional) PDO::PARAM_* integer constant (STR is default).
	 * @param $aParamTypeDeprecated - (IGNORE) defined for backward compatibility only!
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function addParamIfDefined($aDataKey, $aParamType=PDO::PARAM_STR,
			$aParamTypeDeprecated=null)
	{
		if ($this->isDataKeyDefined($aDataKey)) {
			$theData = $this->getDataValue($aDataKey);
			//2nd param was always ignored anyway, if older code specified a 3rd param,
			//  just make it our 2nd param instead.
			if (!is_null($aParamTypeDeprecated))
				$aParamType = $aParamTypeDeprecated;
			$this->addingParam($aDataKey, $aDataKey, $theData, $aParamType);
		}
		return $this;
	}

	/**
	 * Sets the "glue" string that gets prepended to all subsequent calls
	 * to addParam kinds of methods. Spacing is important here, so add what
	 * is needed!
	 * @param string $aStr - glue to prepend.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function setParamPrefix($aStr=', ') {
		$this->myParamPrefix = $aStr;
		return $this;
	}

	/**
	 * Operator string to use in all subsequent calls to addParam methods.
	 * @param string $aStr - operator string to use ('=' is default,
	 * ' LIKE ' is a popular operator as well).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function setParamOperator($aStr='=') {
		$this->myParamOperator = $aStr;
		return $this;
	}
	
	/**
	 * Adds a string to the SQL prefixed with a space (just in case).
	 * @param string $aStr - patial SQL sting to add.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function add($aStr) {
		$this->mySql .= ' '.$aStr;
		return $this;
	}
	
	/**
	 * Sometimes parameter data needs processing before being used.
	 * @param string $aParamKey - the parameter key.
	 * @param Function $aParamFunc - a function used to process the
	 * data (even a default value) of the form:<br>
	 * func($thisSqlBuilder, $paramKey, $currentParamValue) and returns
	 * the processed value.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function setParamDataHandler($aParamKey, $aParamFunc) {
		if (!empty($aParamKey)) {
			$this->myParamFuncs[$aParamKey] = $aParamFunc;
		}
		return $this;
	}

	/**
	 * Apply an externally defined set of WHERE field clauses and param values
	 * to our SQL (excludes the "WHERE" keyword).
	 * @param SqlBuilder $aFilter - External SqlBuilder object (can be NULL).
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function applyFilter(SqlBuilder $aFilter=null) {
		if (!empty($aFilter)) {
			if (!empty($aFilter->mySql)) {
				$this->mySql .= $this->myParamPrefix.$aFilter->mySql;
			}
			if (!empty($aFilter->myParams)) {
				foreach ($aFilter->myParams as $theFilterParamKey => $theFilterParamValue) {
					$this->setParam( $theFilterParamKey, $theFilterParamValue,
							$aFilter->myParamTypes[$theFilterParamKey]
					);
				}
			}
		}
		return $this;
	}
	
	/**
	 * If sort list is defined and its contents are also contained
	 * in the non-empty $aFieldList, then apply the sort order as neccessary.
	 * @see \BitsTheater\costumes\SqlBuilder::applyOrderByList() which this method is an alias of.
	 * @param array $aSortList - keys are the fields => values are 'ASC' or 'DESC' with null='ASC'.
	 * @param array/string $aFieldList - the list or comma string of fieldnames.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function applySortList($aSortList, $aFieldList=null) {
		return $this->applyOrderByList($aSortList, $aFieldList);
	}
	
	/**
	 * If order by list is defined and its contents are also contained
	 * in the non-empty $aFieldList, then apply the sort order as neccessary.
	 * @param array $aOrderByList - keys are the fields => values are 'ASC' or 'DESC' with null='ASC'.
	 * @return \BitsTheater\costumes\SqlBuilder Returns $this for chaining.
	 */
	public function applyOrderByList($aOrderByList)
	{
		if (!empty($aOrderByList)) {
			$theSortKeyword = 'ORDER BY';
			//other db types may use a diff reserved keyword, set that here
			//TODO ...
			$this->add($theSortKeyword);
			
			$theOrderByList = $aOrderByList;
			//if plain string[] implying all ASC order, skip processing step
			if (!isset($theOrderByList[0])) {
				$theOrderByList = array();
				foreach ($aOrderByList as $theField => $theSortOrder) {
					$theEntry = $theField . ' ';
					if (is_bool($theSortOrder))
						$theEntry .= ($theSortOrder) ? self::ORDER_BY_ASCENDING : self::ORDER_BY_DESCENDING;
					else if (strtoupper(trim($theSortOrder))==self::ORDER_BY_DESCENDING)
						$theEntry .= self::ORDER_BY_DESCENDING;
					else
						$theEntry .= self::ORDER_BY_ASCENDING;
					$theOrderByList[] = $theEntry;
				}
			}
			$this->add(implode(',', $theOrderByList));
		}
		return $this;
	}
	
	/**
	 * Retrieve the order by list from the sanitizer which might be from the UI or a default.
	 * @return $this Returns $this for chaining.
	 */
	public function applyOrderByListFromSanitizer()
	{
		if (!empty($this->mySqlSanitizer))
			return $this->applyOrderByList( $this->mySqlSanitizer->getSanitizedOrderByList() ) ;
		else
			return $this;
	}
	
	/**
	 * Some operators require alternate handling during WHERE clauses (e.g. "=" with NULLs).
	 * This will setParamPrefix(" WHERE ") which will apply to the next addParam.
	 * @param string $aAdditionalParamPrefix - string to append to " WHERE " as the next param prefix.
	 */
	public function startWhereClause($aAdditionalParamPrefix='') {
		$this->bUseIsNull = true;
		return $this->setParamPrefix(' WHERE '.$aAdditionalParamPrefix);
	}
	
	/**
	 * Some operators require alternate handling during WHERE clauses (e.g. "=" with NULLs).
	 */
	public function endWhereClause() {
		$this->bUseIsNull = false;
		return $this;
	}
	
	//=================================================================
	// MAPPED FUNCTIONS TO MODEL
	//=================================================================

	/**
	 * Execute DML (data manipulation language - INSERT, UPDATE, DELETE) statements.
	 * @throws DbException if there is an error.
	 * @return number|PDOStatement Returns the number of rows affected OR if using params,
	 *     the PDOStatement.
	 * @see \BitsTheater\Model::execDML();
	 */
	public function execDML() {
		return $this->myModel->execDML($this->mySql, $this->myParams, $this->myParamTypes);
	}
	
	/**
	 * Executes DML statement and then checks the returned SQLSTATE.
	 * @param string|array $aSqlState5digitCodes - standard 5 digit codes to check,
	 * defaults to '02000', meaning "no data"; e.g. UPDATE/DELETE failed due
	 * to record defined by WHERE clause returned no data. May be a comma separated
	 * list of codes or an array of codes to check against.
	 * @return boolean Returns the result of the SQLSTATE check.
	 * @link https://ib-aid.com/download/docs/firebird-language-reference-2.5/fblangref25-appx02-sqlstates.html
	 */
	public function execDMLandCheck($aSqlState5digitCodes=array(self::SQLSTATE_NO_DATA)) {
		$theExecResult = $this->execDML();
		if (!empty($aSqlState5digitCodes)) {
			$theStatesToCheck = null;
			if (is_string($aSqlState5digitCodes)) {
				$theStatesToCheck = explode(',', $aSqlState5digitCodes);
			} else if (is_array($aSqlState5digitCodes)) {
				$theStatesToCheck = $aSqlState5digitCodes;
			}
			if (!empty($theStatesToCheck)) {
				$theSqlState = $theExecResult->errorCode();
				return (array_search($theSqlState, $theStatesToCheck, true)!==false);
			}
		}
		return !empty($theExecResult);
	}
	
	/**
	 * Executes DML statement and then checks the returned SQLSTATE.
	 * @param string $aSqlState5digitCode - a single standard 5 digit code to check,
	 * defaults to '02000', meaning "no data"; e.g. UPDATE/DELETE failed due
	 * to record defined by WHERE clause returned no data.
	 * @return boolean Returns the result of the SQLSTATE check.
	 * @see SqlBuilder::execDMLandCheck()
	 * @link https://ib-aid.com/download/docs/firebird-language-reference-2.5/fblangref25-appx02-sqlstates.html
	 */
	public function execDMLandCheckCode($aSqlState5digitCode=self::SQLSTATE_NO_DATA) {
		return ($this->execDML()->errorCode()==$aSqlState5digitCode);
	}
	
	/**
	 * Execute Select query, returns PDOStatement.
	 * @throws DbException if there is an error.
	 * @return PDOStatement on success.
	 * @see \BitsTheater\Model::query();
	 */
	public function query() {
		return $this->myModel->query($this->mySql, $this->myParams, $this->myParamTypes);
	}
	
	/**
	 * A combination query & fetch a single row, returns null if errored.
	 * @see \BitsTheater\Model::getTheRow();
	 */
	public function getTheRow() {
		return $this->myModel->getTheRow($this->mySql, $this->myParams, $this->myParamTypes);
	}
	
	/**
	 * SQL Params should be ordered array with ? params OR associative array with :label params.
	 * @param array $aListOfParamValues - array of arrays of values for the parameters in the SQL statement.
	 * @throws DbException if there is an error.
	 * @see \BitsTheater\Model::execMultiDML();
	 */
	public function execMultiDML($aListOfParamValues) {
		return $this->myModel->execMultiDML($this->mySql, $aListOfParamValues, $this->myParamTypes);
	}
	
	/**
	 * Perform an INSERT query and return the new Auto-Inc ID field value for it.
	 * Params should be ordered array with ? params OR associative array with :label params.
	 * @throws DbException if there is an error.
	 * @return Returns the lastInsertId().
	 * @see \BitsTheater\Model::addAndGetId();
	 */
	public function addAndGetId() {
		return $this->myModel->addAndGetId($this->mySql, $this->myParams, $this->myParamTypes);
	}
	
	/**
	 * Execute DML (data manipulation language - INSERT, UPDATE, DELETE) statements
	 * and return the params used in the query. Convenience method when using
	 * parameterized queries since PDOStatement::execDML() always only returns TRUE.
	 * @throws DbException if there is an error.
	 * @return Returns the param data.
	 * @see \PDOStatement::execute();
	 */
	public function execDMLandGetParams()
	{
		$this->myModel->execDML($this->mySql, $this->myParams, $this->myParamTypes);
		return $this->myParams;
	}
	
	/**
	 * Sometimes we want to aggregate the query somehow rather than return data from it.
	 * @param array $aSqlAggragates - (optional) the aggregation list, defaults to array('count(*)'=>'total_rows').
	 * @return array Returns the results of the aggregates.
	 */
	public function getQueryTotals( $aSqlAggragates=array('count(*)'=>'total_rows') )
	{
		$theSqlFields = array();
		foreach ($aSqlAggragates as $theField => $theName)
			array_push($theSqlFields, $theField . ' AS ' . $theName);
		$theSelectFields = implode(', ', $theSqlFields);
		$sqlTotals = $this->cloneFrom($this);
		try {
			return $sqlTotals->replaceSelectFieldsWith($theSelectFields)
					->getAggregateResults(array_values($aSqlAggragates))
			;
		} catch (\PDOException $pdoe)
		{ throw $sqlTotals->newDbException(__METHOD__, $pdoe); }
	}
	
	/**
	 * Create a clone of the object param and return it.
	 * @param SqlBuilder $aSqlBuilder - the builder to clone.
	 * @return SqlBuilder Returns the cloned builder.
	 */
	public function cloneFrom(SqlBuilder $aSqlBuilder)
	{
		return clone $aSqlBuilder;
	}
	
	/**
	 * Replace the currently formed SELECT fields with the param.  If you have nested queries,
	 * you will need to use the
	 * <pre>SELECT /&#42 FIELDLIST &#42/ field1, field2, (SELECT blah) AS field3 /&#42 /FIELDLIST &#42/ FROM</pre>
	 * hints in the SQL.
	 * @param string|array $aSelectFields - (optional) the fields to use instead, defaults to "*".
	 * @return SqlBuilder Returns $this for chaining.
	 */
	public function replaceSelectFieldsWith($aSelectFields=null)
	{
		$theSelectFields = null;
		if (is_string($aSelectFields))
			$theSelectFields = $aSelectFields;
		if (is_array($aSelectFields))
			$theSelectFields = implode(',', $aSelectFields);
		if (empty($theSelectFields))
			$theSelectFields = '*';
		$theSelectFields = 'SELECT '.$theSelectFields.' FROM';
		//nested queries can mess us up, so check for hints first
		if (strpos($this->mySql, self::FIELD_LIST_HINT_START)>0 &&
				strpos($this->mySql, self::FIELD_LIST_HINT_END)>0)
		{
			$this->mySql = preg_replace('|SELECT /\* FIELDLIST \*/ .+? /\* /FIELDLIST \*/ FROM|i',
					$theSelectFields, $this->mySql, 1
			);
		}
		else
		{
			//we want a "non-greedy" match so that it stops at the first "FROM" it finds: ".+?"
			$this->mySql = preg_replace('|SELECT .+? FROM|i', $theSelectFields, $this->mySql, 1);
		}
		//$this->debugLog(__METHOD__.' sql='.$theSql->mySql.' params='.$this->debugStr($theSql->myParams));
		return $this;
	}

	/**
	 * Execute the currently built SELECT query and retrieve all the aggregates as numbers.
	 * @param string[] $aSqlAggragateNames - the aggregate names to retrieve.
	 * @return number[] Returns the array of aggregate values.
	 */
	public function getAggregateResults( $aSqlAggragateNames=array('total_rows') )
	{
		$theResults = array();
		//$this->debugLog(__METHOD__.' sql='.$theSql->mySql.' params='.$this->debugStr($theSql->myParams));
		$theRow = $this->getTheRow();
		if (!empty($theRow)) {
			foreach ($aSqlAggragateNames as $theName)
			{
				$theResults[$theName] = $theRow[$theName]+0;
			}
		}
		return $theResults;
	}
	
	/**
	 * Standardized SQL failure log(s) meant to easily record DbExceptions on the
	 * server for postmortem debugging.
	 * @param string $aWhatFailed - the thing that failed, typically __METHOD__.
	 * @param string|object $aMsgOrException - error message or Exception object.
	 * @return SqlBuilder Returns $this for chaining.
	 */
	public function logSqlFailure($aWhatFailed, $aMsgOrException) {
		$theMsg = (is_object($aMsgOrException) && method_exists($aMsgOrException, 'getMessage'))
				? $aMsgOrException->getMessage()
				: $aMsgOrException
		;
		$this->errorLog($aWhatFailed . ' [1/3] failed: ' . $theMsg);
		$this->errorLog($aWhatFailed . ' [2/3] sql=' . $this->mySql);
		$this->errorLog($aWhatFailed . ' [3/3] params=' . $this->debugStr($this->myParams));
		return $this;
	}
	
	/**
	 * Create the DbException object and log the SQL failure.
	 * @param string $aWhatFailed - the thing that failed, typically __METHOD__.
	 * @param PDOException $aPdoException - the PDOException that occurred.
	 * @return DbException Return the new exception object.
	 */
	public function newDbException($aWhatFailed, $aPdoException) {
		$this->logSqlFailure($aWhatFailed, $aPdoException);
		return new DbException($aPdoException, $aWhatFailed . ' failed.');
	}
	
	/**
	 * Standardized SQL failure log(s) meant to easily record DbExceptions on the
	 * server for postmortem debugging.
	 * @param string $aWhatMethod - the thing that you are debugging, typically __METHOD__.
	 * @param string $aMsg - (optional) debug message.
	 * @return SqlBuilder Returns $this for chaining.
	 */
	public function logSqlDebug($aWhatMethod, $aMsg = '') {
		$this->debugLog($aWhatMethod . ' [1/2] ' . $aMsg . ' sql=' . $this->mySql);
		$this->debugLog($aWhatMethod . ' [2/2] params=' . $this->debugStr($this->myParams));
		return $this;
	}
	
	/**
	 * PDO requires all query parameters be unique. This poses an issue when multiple datakeys
	 * with the same name are needed in the query (especially true for MERGE queries). This
	 * method will check for any existing parameters named $aDataKey and will return a new
	 * name with a number for a suffix to ensure its uniqueness.
	 * @param string $aDataKey - the parameter/datakey name.
	 * @return string Return the $aDataKey if already unique, else a modified version to
	 *   ensure it is unique in the query-so-far.
	 */
	public function getUniqueDataKey($aDataKey) {
		$i = 1;
		$theDataKey = $aDataKey;
		while (array_key_exists($theDataKey, $this->myParams))
			$theDataKey = $aDataKey . strval(++$i);
		return $theDataKey;
	}
	
	/**
	 * Providing click-able headers in tables to easily sort them by a particular field
	 * is a great UI feature. However, in order to prevent SQL injection attacks, we
	 * must double-check that a supplied field name to order the query by is something
	 * we can sort on; this method makes use of the <code>Scene::isFieldSortable()</code>
	 * method to determine if the browser supplied field name is one of our possible
	 * headers that can be clicked on for sorting purposes. The Scene's properties called
	 * <code>orderby</code> and <code>orderbyrvs</code> are used to determine the result.
	 * @param object $aScene - the object, typically a Scene decendant, which is used
	 *     to call <code>isFieldSortable()</code> and access the properties
	 *     <code>orderby</code> and <code>orderbyrvs</code>.
	 * @param array $aDefaultOrderByList - (optional) default to use if no proper
	 *     <code>orderby</code> field was defined.
	 * @return array Returns the sanitized OrderBy list.
	 * @deprecated Please use SqlBuilder::applyOrderByListFromSanitizer()
	 */
	public function sanitizeOrderByList($aScene, $aDefaultOrderByList=null)
	{
		$theOrderByList = $aDefaultOrderByList;
		if (!empty($aScene) && !empty($aScene->orderby))
		{
			//does the object passed in even define our validation method?
			$theHeaderLabel = (method_exists($aScene, 'isFieldSortable'))
					? $aScene->isFieldSortable($aScene->orderby)
					: null
					;
			//only valid columns we are able to sort on will define a header label
			if (!empty($theHeaderLabel))
			{
				$theSortDirection = null;
				if (isset($aScene->orderbyrvs))
				{
					$theSortDirection = ($aScene->orderbyrvs)
							? self::ORDER_BY_DESCENDING
							: self::ORDER_BY_ASCENDING
							;
				}
				$theOrderByList = array( $aScene->orderby => $theSortDirection );
			}
		}
		return $theOrderByList;
	}
	
}//end class
	
}//end namespace
