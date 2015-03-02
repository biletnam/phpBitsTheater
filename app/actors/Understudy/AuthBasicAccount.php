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

namespace BitsTheater\actors\Understudy;
use BitsTheater\actors\Understudy\ABitsAccount as BaseActor;
use BitsTheater\scenes\Account as MyScene;
	/* @var $v MyScene */
use BitsTheater\models\Accounts;
	/* @var $dbAccounts Accounts */
use BitsTheater\models\Auth;
	/* @var $dbAuth Auth */
use BitsTheater\models\AuthGroups;
	/* @var $dbAuthGroups AuthGroups */
use com\blackmoonit\Strings;
{//namespace begin

class AuthBasicAccount extends BaseActor {
	const DEFAULT_ACTION = 'register';

	public function view($aAcctId=null) {
		//shortcut variable $v also in scope in our view php file.
		$v =& $this->scene;
		
		$theImmediateRedirect = parent::view($aAcctId);
		if (empty($theImmediateRedirect)) {
			if (!empty($aAcctId) && $this->isAllowed('account','modify') && !empty($v->ticket_info)) {
				$dbAuth = $this->getProp('Auth');
				$theAuthRow = $dbAuth->getAuthByAccountId($aAcctId);
				$v->ticket_info->email = $theAuthRow['email'];
			}
		}
		return $theImmediateRedirect;
	}
	
	protected function registerNewAccount($aAcctName, $aAcctPw, $aAcctEmail, $aRegCode, $bAllowGroup0toAutoRegister=true) {
		//shortcut variable $v also in scope in our view php file.
		$v =& $this->scene;
		if (!empty($aAcctName) && !empty($aAcctPw) && !empty($aAcctEmail) && !empty($aRegCode)) {
			$dbAccounts = $this->getProp('Accounts');
			$dbAuth = $this->getProp('Auth');
			$theCanRegisterResult = $dbAuth->canRegister($aAcctName, $aAcctEmail);
			if ($theCanRegisterResult==$dbAuth::REGISTRATION_SUCCESS) {
				//see if there is a registration code that maps to a particular group
				$dbAuthGroups = $this->getProp('AuthGroups');
				$theDefaultGroup = $dbAuthGroups->findGroupIdByRegCode($this->getAppId(), $aRegCode);
				if ($theDefaultGroup!=0) {
					$theVerifiedTs = $dbAccounts->utc_now();
				} else if (!$bAllowGroup0toAutoRegister) {
					return $dbAuth::REGISTRATION_REG_CODE_FAIL;
				} else {
					$theVerifiedTs = null;
				}
				//now that we have a proper group and account name, lets save stuff!				
				$theNewId = $dbAccounts->add(array('account_name' => $aAcctName));
				if (!empty($theNewId)) {
					$theNewAcct = array(
							'email' => $aAcctEmail,
							'account_id' => $theNewId,
							'pwinput' => $aAcctPw,
							'verified_timestamp' => $theVerifiedTs,
					);
					$dbAuth->registerAccount($theNewAcct,$theDefaultGroup);
					return $dbAuth::REGISTRATION_SUCCESS;
				} else {
					return $dbAuth::REGISTRATION_UNKNOWN_ERROR;
				}
			} else {
				return $theCanRegisterResult;
			}
		}
	}
	
	public function register($aTask='data-entry') {
		//shortcut variable $v also in scope in our view php file.
		$v =& $this->scene;
		//make sure user/pw reg fields will not interfere with any login user/pw field in header
		$userKey = $this->scene->getUsernameKey().'_reg';
		$pwKey = $this->scene->getPwInputKey().'_reg';
		if ($aTask==='new' && !empty($theRegCode) && $this->scene->$pwKey===$this->scene->password_confirm) {
			$dbAuth = $this->getProp('Auth');
			$theRegResult = $this->registerNewAccount($this->scene->$userKey, $this->scene->$pwKey, 
					$this->scene->email, $this->scene->reg_code);
			if (isset($theRegResult)) {
				if ($theRegResult===$dbAuth::REGISTRATION_EMAIL_TAKEN) {
					$v->addUserMsg($this->getRes('account/msg_acctexists/'.$this->getRes('account/label_email')),$v::USER_MSG_ERROR);
					return $this->getMyUrl('register');
				} else if ($theRegResult===$dbAuth::REGISTRATION_NAME_TAKEN) {
					$v->addUserMsg($this->getRes('account/msg_acctexists/'.$this->getRes('account/label_name')),$v::USER_MSG_ERROR);
					return $this->getMyUrl('register');
				}
			} else {
				return $this->getMyUrl('register');
			}
		} else {
			//$this->scene->err_msg = $_SERVER['QUERY_STRING'];
			//$this->scene->err_msg = array_key_exists('err_msg',$_GET)?$_GET['err_msg']:null;
			$this->scene->form_name = 'register_user';
			$this->scene->action_url_register = $this->getMyUrl('register/new');
			$this->scene->post_key = $this->getAppId();
			$dbAccounts = $this->getProp('Accounts');
			if ($dbAccounts->isEmpty()) {
				$this->scene->redirect = $this->getMyUrl('/rights');
				$this->scene->reg_code = $this->getAppId();
			} else {
				$this->scene->redirect = $this->getHomePage();
			}
		}
		//indicate what top menu we are currently in
		$this->setCurrentMenuKey('account');
	}
	
	public function modify() {
		$dbAccounts = $this->getProp('Accounts');
		$theAcctId = $this->scene->ticket_num;
		$dbAuth = $this->getProp('Auth');
		$pwKeyOld = $this->scene->getPwInputKey().'_old';
		if ($dbAuth->isCallable('cudo') && $dbAuth->cudo($theAcctId,$this->scene->$pwKeyOld)) {
			//if current pw checked out ok, see if its our own acct or have rights to modify other's accounts.
			if ($theAcctId==$this->director->account_info['account_id'] || $this->isAllowed('account','modify')) {
				$theOldEmail = trim($this->scene->ticket_email);
				$theNewEmail = trim($this->scene->email);
				/* !== returned TRUE, === returned FALSE, but strcmp() returned 0 (meaning they are the same) O.o
				$b1 = ($theOldEmail!==$theNewEmail);
				$b2 = ($theOldEmail===$theNewEmail);
				$b3 = (strcmp($theOldEmail,$theNewEmail)!=0);
				Strings::debugLog('b:'.$b1.','.$b2.',',$b3);
				Strings::debugLog(Strings::bin2hex($theOldEmail));
				Strings::debugLog(Strings::bin2hex($theNewEmail));
				/* */
				if (strcmp($theOldEmail,$theNewEmail)!=0) {
					//Strings::debugLog('email is not 0:'.strcmp($theOldEmail,$theNewEmail));
					if ($dbAuth->getAuthByEmail($theNewEmail)) {
						return $this->getMyUrl('view',
								array('err_msg'=>$this->getRes('account/msg_acctexists/'.$this->getRes('account/label_email'))));
					} else {
						$theSql = 'UPDATE '.$dbAuth->tnAuth.' SET email = :email WHERE account_id=:acct_id';
						$dbAuth->execDML($theSql,array('acct_id'=>$theAcctId, 'email'=>$theNewEmail));
					}
				}
				$pwKeyNew = $this->scene->getPwInputKey().'_new';
				if (!empty($this->scene->$pwKeyNew) && $this->scene->$pwKeyNew===$this->scene->password_confirm) {
					$thePwHash = Strings::hasher($this->scene->$pwKeyNew);
					$theSql = 'UPDATE '.$dbAuth->tnAuth.' SET pwhash = :pwhash WHERE account_id=:acct_id';
					$dbAuth->execDML($theSql,array('acct_id'=>$theAcctId, 'pwhash'=>$thePwHash));
				}
				return $this->getMyUrl('view',
						array('err_msg'=>$this->getRes('account/msg_update_success')));
			}
		} else {
			return $this->getMyUrl('view',
					array('err_msg'=>$this->getRes('generic/msg_permission_denied')));
		}
	}
	
	/**
     * Register a user via mobile app rather than on web page.
	 * POST vars expected: name, salt, email, code, fingerprints
     * @return Returns JSON encoded array[code, user_token, auth_token]
	 */
	public function registerViaMobile() {
		//shortcut variable $v also in scope in our view php file.
		$v =& $this->scene;
		$this->renderThisView = 'results_as_json';
		$dbAuth = $this->getProp('Auth');
		//$this->debugLog('regargs='.$v->name.', '.$v->salt.', '.$v->email.', '.$v->code);
		$theRegResult = $this->registerNewAccount($v->name, $v->salt, $v->email, $v->code, false);
		if ($theRegResult===$dbAuth::REGISTRATION_SUCCESS) {
			$theUserToken = $dbAuth->registerMobileFingerprints($dbAuth->getAuthByEmail($v->email), $v->fingerprints);
			$v->results = array('code' => $theRegResult, 'user_token' => $theUserToken);
			$this->debugLog($v->name.' successfully registered an account via mobile: '.$theUserToken);
		} else {
			if (!isset($theRegResult))
				$theRegResult = $dbAuth::REGISTRATION_UNKNOWN_ERROR;
			$v->results = array('code' => $theRegResult);
			$this->debugLog($v->name.' unsuccessfully tried to register an account via mobile. ('.$theRegResult.')');
		}
	}
	
	/**
	 * Mobile auth is a bit more involved than Basic HTTP auth, use this mechanism
	 * for authenticating mobile devices (which may be rooted).
	 * @param string $aPing - (optional) ping string which could be used to pong a response.
     * @return Returns JSON encoded array[account_name, user_token, auth_token, api_version_seq]
	 */
	public function requestMobileAuth($aPing=null) {
		//shortcut variable $v also in scope in our view php file.
		$v =& $this->scene;
		$this->renderThisView = 'results_as_json';
		if (empty($aPing)) {
			if (!$this->isGuest()) {
				$v->results = $dbAuth->requestMobileAuthAfterPwLogin(
						$this->director->account_info, $v->fingerprints);
			} else {
				$v->results = $dbAuth->requestMobileAuthAutomatedByTokens(
						$v->account_name, $v->user_token, $v->fingerprints);
			}
			if (empty($v->results)) {
				$this->director->logout();
			}
		}
		
		/* exmple of descendant code 
		//shortcut variable $v also in scope in our view php file.
		$v =& $this->scene;
		parent::requestMobileAuth($aPing);
		if (empty($v->results) && $aPing==='ping') {
			$v->results = array(
					'user_token' => $aPing,
					'auth_token' => 'pong',
					'api_version_seq' => $this->getRes('website/api_version_seq'),
			);
		}
		*/
	}
	
}//end class

}//end namespace

