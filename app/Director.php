<?php
/*
 * Copyright (C) 2012 Blackmoon Info Tech Services
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

namespace BitsTheater;
use BitsTheater\Model;
use BitsTheater\DbConnInfo;
use BitsTheater\res\ResException;
use com\blackmoonit\AdamEve as BaseDirector;
use com\blackmoonit\Strings;
use com\blackmoonit\Arrays;
use com\blackmoonit\database\DbUtils;
use \ArrayAccess;
use \ReflectionClass;
use \ReflectionMethod;
use \ReflectionException;
use \Exception;
{//begin namespace

class Director extends BaseDirector implements ArrayAccess {
	/**
	 * Normal website operation mode.
	 * @var string
	 */
	const SITE_MODE_NORMAL = 'normal';
	/**
	 * Refuse connections while the site is being worked on.
	 * @var string
	 */
	const SITE_MODE_MAINTENANCE = 'maintenance';
	/**
	 * Use local resources as much as possible (little/no net connection)
	 * @var string
	 */
	const SITE_MODE_DEMO = 'demo';
	
	public $account_info = null;//array('account_id'=>-1, 'account_name'=>'', 'email'=>'', 'groups'=>array(), 'tz'=>'',);
	public $dbConnInfo = array(); //database connections to share with the models
	protected $_propMaster = array(); //cache models created so app doesn't need to create 12 instances of any single model
	protected $_resManager = null;
	protected $_resMaster = array(); //cache of res classes
	protected $auth = null; //cache of Auth model

	public function setup() {
		if (session_id() === '') try {
			session_start();
		} catch (Exception $e) {
			$this->resetSession();
		}
		if ($this->isInstalled()) {
			$this['app_id'] = configs\Settings::APP_ID;
		}
		$this->bHasBeenSetup = true;
	}

	public function cleanup() {
		if (session_id()!='') {
			session_write_close();
		}
		unset($this->account_info);
		//destroy all cashed models
		array_walk($this->_propMaster, function(&$n) {$n['model'] = null;} );
		unset($this->_propMaster);
		//disconnect dbs
		array_walk($this->dbConnInfo, function(&$dbci) {$dbci->disconnect();} );
		unset($this->dbConnInfo);
		//free all resources
		$this->freeRes();
		//call parent
		parent::cleanup();
	}
	
	//----- methods required for various IMPLEMENTS interfaces
	//NOTE: $this['key'] works for simple types, but not arrays.  Avoid arrays!
	public function offsetSet($aOffset, $aValue) {
		$_SESSION[$aOffset] = $aValue;
	}

	public function offsetExists($aOffset) {
		return isset($_SESSION[$aOffset]);
	}

	public function offsetUnset($aOffset) {
		unset($_SESSION[$aOffset]);
	}

	public function offsetGet($aOffset) {
		return isset($_SESSION[$aOffset])?$_SESSION[$aOffset]:null;
	}
	
	//----- IMPLEMENTS handled, get on with being a Director below -----

	public function resetSession() {
		//throw new \Exception('resetSession');
		session_unset();
		session_destroy();
		session_write_close();
		setcookie(session_name(),'',0,'/');
		session_regenerate_id(true);
	}
	
	public function isInstalled() {
		return class_exists(BITS_NAMESPACE_CFGS.'Settings');
	}

	public function canCheckTickets() {
		return $this->canConnectDb() && class_exists(BITS_NAMESPACE_MODELS.'Auth');
	}
	
	public function canConnectDb($aDbConnName='webapp') {
		return $this->getDbConnInfo($aDbConnName)->canAttemptConnectDb();
	}
	
	public function canGetRes() {
		return class_exists(BITS_NAMESPACE_CFGS.'I18N');
	}
	
	
	//===========================================================
	//=                     Actor methods                       =
	//===========================================================
	static public function getActorClass($anActorName) {
		$theActorClass = BITS_NAMESPACE_ACTORS.$anActorName;
		if (!class_exists($theActorClass)) {
			$theActorClass = WEBAPP_NAMESPACE.'actors\\'.$anActorName;
		}
		return $theActorClass;
	}

	public function raiseCurtain($anActorName, $anAction=null, $aQuery=array()) {
		$theActorClass = self::getActorClass($anActorName);
		//Strings::debugLog('rC: class='.$theActorClass.', exist?='.class_exists($theActorClass));
		if (class_exists($theActorClass)) {
			$theAction = (!empty($anAction)) ? $anAction : $theActorClass::DEFAULT_ACTION;
			$methodExists = method_exists($theActorClass,$theAction) && is_callable(array($theActorClass,$theAction));
			if ($methodExists && $theActorClass::isActionUrlAllowed($theAction)) {
				if ($this->isInstalled()) {
					$this['played'] = configs\Settings::APP_ID; //app_id -> play_id -> "played"
				}
				//Strings::debugLog('raiseCurtain: '.$theActorClass.', '.$theAction.', '.Strings::debugStr($aQuery));
				$theActorClass::perform($this,$theAction,$aQuery);
				return true;
			} else {
        	    return false;
			}
		} else {
			Strings::debugLog(__NAMESPACE__.': cannot find Actor class: '.$theActorClass);
			return false;
		}
	}
	
	public function cue($aScene, $anActorName, $anAction, $args=array()) {
		$theActorClass = self::getActorClass($anActorName);
		//Strings::debugLog('rC: class='.$theActorClass.', exist?='.class_exists($theActorClass));
		if (class_exists($theActorClass)) {
			$theAction = (!empty($anAction)) ? $anAction : $theActorClass::DEFAULT_ACTION;
			try {
				$theMethod = new ReflectionMethod($theActorClass,$theAction);
				//if no exception, instantiate the class and call the method
				$theActor = new $theActorClass($this,$theAction);
				$theMethod->setAccessible(true); //protected from direct "raiseCurtain" calls, but ok for cue().
				$args['aScene'] = $aScene; //append the scene of our caller as last param in case called method wants it
				$theResult = $theMethod->invokeArgs($theActor,$args);
				if (empty($theResult)) {
					$s = $theActor->renderFragment($anAction);
					unset($theActor);
					return $s;
				} else {
					header('Location: '.$theResult);
				}
			} catch (ReflectionException $e) {
				//no method to call, just ignore it
			}
		}
	}
	
	
	//===========================================================
	//=                   Model methods                         =
	//===========================================================
	/**
	 * Returns the correct namespace associated with the model name/ReflectionClass.
	 * @param string/ReflectionClass $aModelName - model name as string or 
	 * ReflectionClass of model in question.
	 * @return string Returns the model class name with correct namespace.
	 */
	static public function getModelClass($aModelName) {
		if (is_string($aModelName)) {
			$theModelClass = BITS_NAMESPACE_MODELS.$aModelName;
			if (!class_exists($theModelClass)) {
				$theModelClass = WEBAPP_NAMESPACE.'models\\'.$aModelName;
			}
		} elseif ($aModelName instanceof ReflectionClass) {
			$theModelClass = $aModelName->getName();
		}
		return $theModelClass;
	}
	
	public function getDbConnInfo($aDbConnName='webapp') {
		if (empty($this->dbConnInfo[$aDbConnName])) {
			$this->dbConnInfo[$aDbConnName] = new DbConnInfo($aDbConnName);
		}
		return $this->dbConnInfo[$aDbConnName];
	}
	
	public function getModel($aModelClass) {
		$theModelClass = self::getModelClass($aModelClass);
		if (class_exists($theModelClass)) {
			if (empty($this->_propMaster[$theModelClass])) {
				$this->_propMaster[$theModelClass]['model'] = new $theModelClass($this);
				$this->_propMaster[$theModelClass]['ref_count'] = 0;
			}
			$this->_propMaster[$theModelClass]['ref_count'] += 1;
			return $this->_propMaster[$theModelClass]['model'];
		} else {
			Strings::debugLog(__NAMESPACE__.': cannot find Model class: '.$theModelClass);
		}
	}
	
	public function unsetModel($aModel) {
		if (isset($aModel)) {
			$theModelClass = get_class($aModel);
			if (isset($this->_propMaster[$theModelClass])) {
				$this->_propMaster[$theModelClass]['ref_count'] -= 1;
				if ($this->_propMaster[$theModelClass]['ref_count']<1) {
					$this->_propMaster[$theModelClass]['model'] = null;
					unset($this->_propMaster[$theModelClass]);
				}
			}
			$aModel = null;
		}
	}
	
	//alias for getModel
	public function getProp($aModelClass) {
		return $this->getModel($aModelClass);
	}
	
	public function returnProp($aModel) {
		$this->unsetModel($aModel);
	}

	/**
	 * Calls methodName for every model class that matches the class patern and returns an array of results.
	 * @param string $aModelClassPattern - NULL for all non-abstract models, else a result from getModelClassPattern.
	 * @param string $aMethodName - method to call.
	 * @param mixed $args - arguments to pass to the method to call.
	 * @return array Returns an array of key(model class name) => value(function result);
	 * @see Model::foreachModel()
	 */
	public function foreachModel($aModelClassPattern, $aMethodName, $args=null) {
		return Model::foreachModel($this, $aModelClassPattern, $aMethodName, $args);
	}
	
	
	//===========================================================
	//=                  Scene methods                          =
	//===========================================================
	
	static public function getSceneClass($anActorName) {
		$theSceneClass = BITS_NAMESPACE_SCENES.$anActorName;
		if (!class_exists($theSceneClass)) {
			$theSceneClass = WEBAPP_NAMESPACE.'scenes\\'.$anActorName;
		}
		if (!class_exists($theSceneClass))
			$theSceneClass = BITS_NAMESPACE.'Scene';
		return $theSceneClass;
	}
	
	
	//===========================================================
	//=               RESOURCE management                       =
	//===========================================================
	
	public function getRes($aResName) {
		if (empty($this->_resManager)) {
			if ($this->isInstalled()) {
				//TODO create a user config for "en/US" and pass that into the constructor. (lang/region) 
				$this->_resManager = new configs\I18N();
			} else {
				$theInstallResMgr = BITS_NAMESPACE_RES.'ResI18N';
				$this->_resManager = new $theInstallResMgr('en/US');
			}
		}
		//explode on "\" or "/"
		$theResUri = explode('/',str_replace('\\','/',$aResName));
		//$this->debugPrint($this->debugStr($theResUri));
		if (count($theResUri)>=2) {
			$theResClassName = Strings::getClassName(array_shift($theResUri));
			$theRes = array_shift($theResUri);
		} else {
			$theResClassName = 'Resources';
			$theRes = array_shift($theResUri);
		}
		//$this->debugPrint('res name='.$theResClassName.', res class='.$theResClass.' / '.$this->debugStr($theRes));
		try {
			$theResClass = $this->_resManager->includeResClass($theResClassName);
			//Strings::debugLog('res='.$aResName.' cls='.$theResClass);
			if (!empty($theResUri))
				return $this->loadRes($theResClass,$theRes,$theResUri);
			else
				return $this->loadRes($theResClass,$theRes);
		} catch (ResException $re) {
			if ($this->_resManager->isUsingDefault()) {
				throw $re;
			} else {
				$theResClass = $this->_resManager->includeDefaultResClass($theResClassName);
				if (!empty($theResUri))
					return $this->loadRes($theResClass,$theRes,$theResUri);
				else
					return $this->loadRes($theResClass,$theRes);
			}
		}
	}
	
	protected function loadRes($aResClass, $aRes, $args=null) {
		if (empty($this->_resMaster[$aResClass])) {
			$this->_resMaster[$aResClass] = new $aResClass($this);
		}
		$resObj = $this->_resMaster[$aResClass];
		if (is_callable(array($resObj,$aRes))) {
			try {
				return call_user_func_array(array($resObj,$aRes),$args);
			} catch (Exception $e) {
				throw new ResException($this->_resManager,$aRes,$aResClass,$args,$e);
			}
		} else {
			if (isset($resObj->$aRes)) {
				if (isset($args)) {
					//$this->debugPrint('b: '.$resObj->$aRes.Strings::debugStr($args));
					try {
						return call_user_func_array(array('com\blackmoonit\Strings','format'),Arrays::array_prepend($args,$resObj->$aRes));
					} catch (Exception $e) {
						throw new ResException($this->_resManager,$aRes,$aResClass,$args,$e);
					}
				} else {
					return $resObj->$aRes;
				}
			} else {
				throw new ResException($this->_resManager,(isset($resObj) ? $aResClass.'/' : '').$aRes);
			}
		}			
	}
	
	public function freeRes() {
		array_walk($this->_resMaster, function(&$n) {$n = null;} );
	}

	//===========================================================
	//=                   LOGIN INFO                            =
	//===========================================================
	
	public function admitAudience() {
		if ($this->canCheckTickets()) {
			$this->auth = $this->getProp('Auth'); //director will close this on cleanup
			return $this->auth->checkTicket();
		}
		return false;
	}
	
	public function isAllowed($aNamespace, $aPermission, $acctInfo=null) {
		if (isset($this->auth))
			return $this->auth->isAllowed($aNamespace, $aPermission, $acctInfo);
		else
			return false;
	}
	
	public function isGuest() {
		if (isset($this->auth) && $this->auth->isCallable('isGuest')) {
			return $this->auth->isGuest();
		} else {
			return (empty($this->account_info) || empty($this->account_info['groups']) || count($this->account_info['groups'])<1);
		}
	}
	
	public function logout() {
		if (!$this->isGuest() && isset($this->auth)) {
			$this->auth->ripTicket();
			unset($this->account_info);
		}
		return BITS_URL;
	}
	
	/**
	 * Returns the URL for this site appended with relative path info.
	 * @param mixed $aRelativeURL - array of path segments OR a bunch of string parameters
	 * equating to path segments.
	 * @return string - returns the site domain + relative path URL.
	 */
	public function getSiteURL($aRelativeURL='', $_=null) {
		$theResult = BITS_URL;
		if (!empty($aRelativeURL)) {
			$theArgs = (is_array($aRelativeURL)) ? $aRelativeURL : func_get_args();
			foreach ($theArgs as $pathPart) {
				$theResult .= ((!empty($pathPart) && $pathPart[0]!='/') ? '/' : '' ) . $pathPart;
			}
		}
		return $theResult;
	}
	
	/**
	 * Returns the chat forum this site is mated with, or "" if not.
	 * @return string URL of the forum, if any.
	 */
	public function getForumUrl() {
		if ($this->auth->isCallable('getForumUrl')) {
			return $this->auth->getForumUrl();
		} else {
			return "";
		}
	}

	/**
	 * Get the current mode of the site (normal/maintenance/demo).
	 * @return string Returns one of the MODE_* constants.
	 */
	public function getSiteMode() {
		try {
			$dbConfig = $this->getProp('Config');
			return $dbConfig['site/mode'];
		} catch (Exception $e) {
			return self::SITE_MODE_NORMAL;
		}
	}
	
}//end class

}//end namespace
