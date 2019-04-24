<?php

/**
 * Draugiem.lv API library for PHP
 *
 * Class for easier integration of your application with draugiem.lv API.
 * Supports both draugiem.lv Passport applications and iframe based applications.
 * All user data information returned in functions is returned in following format:
 * 	array (
 * 		'uid' => 491171,
 * 		'name' => 'John',
 * 		'surname' => 'Birch',
 * 		'nick' => 'Johnny',
 * 		'place' => 'Riga',
 * 		'age' => 26,
 * 		'adult' => true,
 * 		'img' => 'http://i2.ifrype.com/91/171/491171/sm_2008100616435822749.jpg',
 * 		'sex' => 'M',
 * 	)
 * If data of multiple users is returned, multiple user data items are placed in array
 * with user IDs as array keys.
 *
 * @copyright SIA Draugiem, 2019
 * @version 1.3.7 (2019-04-24)
 */
class DraugiemApi {

	/**
	 * @var int Application ID
	 */
	private $appId;
	/**
	 * @var string Application key
	 */
	private $appKey;
	/**
	 * @var bool|string API key of the user
	 */
	private $userApiKey = false;
	/**
	 * @var bool|string Session key
	 */
	private $sessionKey = false;
	/**
	 * @var array Logged in users info
	 */
	private $userInfo = array();
	/**
	 * @var array last value cache of "total" attributes of friend list requests
	 */
	private $lastTotal = array();
	/**
	 * @var int Error code for last failed API request
	 */
	public $lastError = 0;
	/**
	 * @var int Error description for last failed API request
	 */
	public $lastErrorDescription = '';

	/**
	 * Draugiem.lv API URL
	 */
	const API_URL = 'https://api.draugiem.lv/php/';
	/**
	 * Draugiem.lv passport login URL
	 */
	const LOGIN_URL = 'https://api.draugiem.lv/authorize/';
	/**
	 * Iframe scripts URL
	 */
	const JS_URL = '//ifrype.com/applications/external/draugiem.js';

	/**
	 * Timeout in seconds for session_check requests
	 */
	const SESSION_CHECK_TIMEOUT = 180;

	/**
	 * Constructs Draugiem.lv API object
	 *
	 * @param int $appId your application ID
	 * @param string $appKey application API key
	 * @param string $userKey user API key (or empty if no user has been authorized)
	 */
	public function __construct($appId, $appKey, $userKey = ''){
		$this->appId = (int)$appId;
		$this->appKey = $appKey;
		$this->userApiKey = $userKey;
	}

	/**
	 * Load draugiem.lv user data and validate session.
	 *
	 * If session is new, makes API authorize request and loads user data, otherwise
	 * gets user info stored in PHP session. Draugiem.lv session status is re-validated automatically
	 * by performing session_check requests in intervals specified by SESSION_CHECK_TIMEOUT constant.
	 *
	 * @return boolean Returns true on successful authorization or false on failure.
	 */
	public function getSession(){
		$this->sessionStart();

		if($this->queryGet('dr_auth_status') && $this->queryGet('dr_auth_status') != 'ok'){
			$this->clearSession();
		}elseif($this->queryGet('dr_auth_code') && (!$this->sessionFetch('draugiem_auth_code') || ($this->queryGet('dr_auth_code') != $this->sessionFetch('draugiem_auth_code')))){ // New session authorization

			$this->clearSession(); //Delete current session data to prevent overwriting of existing session

			//Get authorization data
			$response = $this->apiCall('authorize', array(
				'code' => $this->queryGet('dr_auth_code')
			));

			if($response && isset($response['apikey'])){ //API key received
				//User profile info
				$userData = reset($response['users']);

				if(!empty($userData)){
					if($this->queryGet('session_hash')){ //Internal application, store session key to recheck if draugiem.lv session is active

						$this->sessionPut('draugiem_lastcheck', time());

						$this->sessionPut('draugiem_session', $this->queryGet('session_hash'));
						$this->sessionKey = $this->queryGet('session_hash');

						if($this->queryGet('domain')){ //Domain for JS actions
							$this->setSessionDomain($this->queryGet('domain'));
						}

						if(!empty($response['inviter'])){ //Fill invitation info if any
							$this->sessionPut('draugiem_invite', array(
								'inviter' => (int)$response['inviter'],
								'extra' => isset($response['invite_extra']) ? $response['invite_extra'] : false,
							));
						}
					}

					$this->sessionPut('draugiem_auth_code', $this->queryGet('dr_auth_code'));

					// User API key
					$this->userApiKey = $response['apikey'];
					$this->sessionPut('draugiem_userkey',$response['apikey']);

					// User language
					$this->sessionPut('draugiem_language', $response['language']);

					// Profile info
					$this->userInfo = $userData;
					$this->sessionPut('draugiem_user', $userData);

					return true;
				}
			}

		}elseif($this->sessionFetch('draugiem_user')){ //Existing session

			// Load data from session
			$this->userApiKey = $this->sessionFetch('draugiem_userkey');
			$this->userInfo = $this->sessionFetch('draugiem_user');

			if($this->sessionFetch('draugiem_lastcheck') && $this->sessionFetch('draugiem_session')){ //Iframe app session

				if($this->queryGet('dr_auth_code') && $this->queryGet('domain')){
					 // Fix session domain if changed
					$this->setSessionDomain($this->queryGet('domain'));
				}

				$this->sessionKey = $this->sessionFetch('draugiem_session');

				// Session check timeout not reached yet, do not check session
				if($this->sessionFetch('draugiem_lastcheck') > (time() - self::SESSION_CHECK_TIMEOUT)){
					return true;
				}

				// Session check timeout reached, recheck draugiem.lv session status
				$response = $this->apiCall('session_check', array('hash' => $this->sessionKey));

				if(!empty($response['status']) && $response['status'] == 'OK'){
					$this->sessionPut('draugiem_lastcheck', time());
					return true;
				}

			}else{
				return true;
			}
		}
		return false;
	}

	/**
	 * Get user API key from current session. The function must be called after getSession().
	 *
	 * @return string API key of current user or false if no user has been authorized
	 */
	public function getUserKey(){
		return $this->userApiKey;
	}

	/**
	 * Get language setting of currently authorized user. The function must be called after getSession().
	 *
	 * @return string Two letter country code (lv/ru/en/de/hu/lt)
	 */
	public function getUserLanguage(){
		return $this->sessionFetch('draugiem_language', 'lv');
	}

	/**
	 * Get draugiem.lv user ID for currently authorized user
	 *
	 * @return int Draugiem.lv user ID of currently authorized user or false if no user has been authorized
	 */
	public function getUserId(){
		if($this->userApiKey && !$this->userInfo) { //We don't have user data, request
			$this->userInfo = $this->getUserData();
		}
		if(isset($this->userInfo['uid'])){
			return $this->userInfo['uid'];
		}
		return false;
	}


	/**
	 * Return user data for specified Draugiem.lv user IDs
	 *
	 * If a single user ID is passed  to this function, a single user data element is returned.
	 * If an array of user IDs is passed to this function, an array of user data elements is returned.
	 * Function can return only information about users that have authorized the application.
	 *
	 * @param mixed $ids array of user IDs or a single user ID (this argument can also be false. In that case, user data of current user will be returned)
	 * @return array Requested user data items or false if API request has failed
	 */
	public function getUserData($ids = false){
		$returnSingle = false;

		if(is_array($ids)){ //Array of IDs
			$ids = implode(',', $ids);
		}else{
			$returnSingle = true;

			if($this->userInfo && ($ids == $this->userInfo['uid'] || $ids === false)){ //If we have userinfo of active user, return it immediately
				return $this->userInfo;
			}

			if($ids !== false){
				$ids = (int)$ids;
			}
		}

		$response = $this->apiCall('userdata', array('ids' => $ids));
		if($response){
			$userData = $response['users'];
			if($returnSingle){ //Single item requested
				if(!empty($userData)){ //Data received
					return reset($userData);
				}else{ //Data not received
					return false;
				}
			}else{ //Multiple items requested
				return $userData;
			}
		}
		return false;
	}

	/**
	 * Get user profile image URL with different size
	 * @param string $img User profile image URL from API (default size)
	 * @param string $size Desired image size (icon/small/medium/large)
	 * @return string
	 */
	public function imageForSize($img, $size){
		$sizes = array(
			'icon' => 'i_', //50x50px
			'small' => 'sm_', //100x100px (default)
			'medium' => 'm_', //215px wide
			'large' => 'l_', //710px wide
		);
		if(isset($sizes[$size])){
			$img = str_replace('/sm_', '/' . $sizes[$size], $img);
		}
		return $img;
	}


	/**
	 * Check if two application users are friends
	 *
	 * @param int $uid User ID of the first user
	 * @param bool|int $uid2 User ID of the second user (or false to use current user)
	 * @return boolean Returns true if the users are friends, false otherwise
	 */
	public function checkFriendship($uid, $uid2 = false){
		$response = $this->apiCall('check_friendship', array('uid' => $uid, 'uid2' => $uid2));
		return isset($response['status']) && $response['status'] == 'OK';
	}

	/**
	 * Get number of user friends within application
	 *
	 * To reach better performance, it is recommended to call this function after getUserFriends() call
	 * (in that way, a single API request will be made for both calls).
	 *
	 * @return integer Returns number of friends or false on failure
	 */
	public function getFriendCount(){
		if(isset($this->lastTotal['friends'][$this->userApiKey])){
			return $this->lastTotal['friends'][$this->userApiKey];
		}
		$response = $this->apiCall('app_friends_count');
		if(isset($response['friendcount'])){
			$this->lastTotal['friends'][$this->userApiKey] = (int)$response['friendcount'];
			return $this->lastTotal['friends'][$this->userApiKey];
		}
		return false;
	}

	/**
	 * Get list of friends of currently authorized user that also use this application.
	 *
	 * @param integer $page Which page of data to return (pagination starts with 1, default value 1)
	 * @param integer $limit Number of users per page (min value 1, max value 200, default value 20)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array List of user data items/user IDs or false on failure
	 */
	public function getUserFriends($page = 1, $limit = 20, $return_ids = false){
		$response = $this->apiCall('app_friends', array(
			'show' => ($return_ids ? 'ids' : false),
			'page' => $page, 'limit' => $limit
		));

		if($response){
			$this->lastTotal['friends'][$this->userApiKey] = (int)$response['total'];
			if($return_ids){
				return $response['userids'];
			}else{
				return $response['users'];
			}
		}

		return false;
	}

	/**
	 * Get list of all friends of currently authorized user.
	 *
	 * @param integer $page Which page of data to return (pagination starts with 1, default value 1)
	 * @param integer $limit Number of users per page (min value 1, max value 200, default value 20)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array List of user data items/user IDs or false on failure
	 */
	public function getAllUserFriends($page = 1, $limit = 20, $return_ids = false){
		$response = $this->apiCall('app_all_friends', array(
			'show' => ($return_ids ? 'ids' : false),
			'page' => $page, 'limit' => $limit
		));

		if($response){
			$this->lastTotal['friends'][$this->userApiKey] = (int)$response['total'];
			if($return_ids){
				return $response['userids'];
			}else{
				return $response['users'];
			}
		}

		return false;
	}

	/**
	* Get list of all permissions, and if user has accepted them
	*
	* @return array|bool List of all permissions with value 1 or 0 ( user has accepted that permission or not )
	*/
	public function getPermissions() {
		return $this->apiCall('get_permissions');
	}

	/**
	 * Get list of friends of currently authorized user that also use this application and are currently logged in draugiem.lv.
	 * Function available only to integrated applications.
	 *
	 * @param integer $limit Number of users per page (min value 1, max value 100, default value 20)
	 * @param boolean $in_app Whether to return friends that currently use app (true - online in app, false - online in portal)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array|bool List of user data items/user IDs or false on failure
	 */
	public function getOnlineFriends($limit = 20, $in_app=false, $return_ids = false){
		$response = $this->apiCall('app_friends_online', array( 'show'=>($return_ids?'ids':false), 'in_app'=>$in_app, 'limit'=>$limit ));
		if($response){
			if($return_ids){
				return $response['userids'];
			} else {
				return $response['users'];
			}
		}
		return false;
	}

	/**
	 * Get number of users that have authorized the application
	 *
	 * To reach better performance, it is recommended to call this function after getAppUsers() call
	 * (in that way, a single API request will be made for both calls).
	 *
	 * @return integer Returns number of users or false on failure
	 */
	public function getUserCount(){
		if(isset($this->lastTotal['users'])){
			return $this->lastTotal['users'];
		}
		$response = $this->apiCall('app_users_count');
		if(isset($response['usercount'])){
			$this->lastTotal['users'] = (int)$response['usercount'];
			return $this->lastTotal['users'];
		}
		return false;
	}

	/**
	 * Get list of users that have authorized this application.
	 *
	 * @param integer $page Which page of data to return (pagination starts with 1, default value 1)
	 * @param integer $limit Number of users per page (min value 1, max value 200, default value 20)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array List of user data items/user IDs or false on failure
	 */
	public function getAppUsers($page = 1, $limit = 20, $return_ids = false){
		$response = $this->apiCall('app_users', array( 'show'=>($return_ids?'ids':false), 'page'=>$page, 'limit'=>$limit ));
		if($response){
			$this->lastTotal['users'] = (int)$response['total'];
			if($return_ids){
				return $response['userids'];
			} else {
				return $response['users'];
			}
		}
		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Draugiem.lv passport and Draugiem ID functions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get URL for Draugiem.lv Passport or Draugiem ID login page to authenticate user
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @return string URL of Draugiem.lv Passport or Draugiem ID login page
	 */
	public function getLoginURL($redirect_url){
		$hash = md5($this->appKey . $redirect_url); // Request checksum
		$link = self::LOGIN_URL . '?app=' . $this->appId . '&hash=' . $hash . '&redirect=' . urlencode($redirect_url);
		return $link;
	}

	/**
	 * Get HTML for Draugiem.lv Passport login button with Draugiem.lv Passport logo.
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @param boolean $popup Whether to open authorization page within a popup window (true - popup, false - same window).
	 * @return string HTML of Draugiem.lv Passport login button
	 */
	public function getLoginButton($redirect_url, $popup = true){
		$url = htmlspecialchars($this->getLoginUrl($redirect_url));

		if($popup){
			$js = "if(handle=window.open('$url&amp;popup=1','Dr_{$this->appId}' ,'width=400, height=400, left='+(screen.width?(screen.width-400)/2:0)+', top='+(screen.height?(screen.height-400)/2:0)+',scrollbars=no')){handle.focus();return false;}";
			$onclick = ' onclick="' . $js . '"';
		}else{
			$onclick = '';
		}
		return '<a href="' . $url . '"' . $onclick . '><img border="0" src="http://api.draugiem.lv/authorize/login_button.png" alt="draugiem.lv" /></a>';
	}

	/**
	 * Get HTML for Draugiem.lv Draugiem ID login button
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @param boolean $popup Whether to open authorization page within a popup window (true - popup, false - same window).
	 * @return string HTML of Draugiem ID login button
	 */
	public function getDraugiemIDButton($redirect_url, $popup = true){
		$url = htmlspecialchars($this->getLoginUrl($redirect_url));

		if($popup){
			$js = "if(handle=window.open('$url&amp;popup=1','Dr_{$this->appId}' ,'width=400, height=400, left='+(screen.width?(screen.width-400)/2:0)+', top='+(screen.height?(screen.height-400)/2:0)+',scrollbars=no')){handle.focus();return false;}";
			$onclick = ' onclick="' . $js . '"';
		}else{
			$onclick = '';
		}
		return '<a href="' . $url . '"' . $onclick . '><img border="0" width="148" height="32" src="//api.draugiem.lv/authorize/draugiem_id.svg" onerror="this.src=\'//api.draugiem.lv/authorize/draugiem_id\'+ (window.devicePixelRatio >= 2 ? \'@2x\' : \'\') +\'.png\'; this.onerror=null;" alt="Draugiem ID" /></a>';
	}

	/*
	|--------------------------------------------------------------------------
	| Iframe application functions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get draugiem.lv domain (usually "www.draugiem.lv" but can be different for international versions of the portal) for current iframe session.
	 *
	 * This function has to be called after getSession()). Domain name should be used when linking to user profiles or other targets within draugiem.lv.
	 *
	 * @return string Draugiem.lv domain name that is currently used by application user
	 */
	public function getSessionDomain(){
		return $this->sessionFetch('draugiem_domain', 'www.draugiem.lv');
	}

	private function setSessionDomain($domain){
		$this->sessionPut('draugiem_domain', preg_replace('/[^a-z0-9\.]/', '', $domain));
	}

	/**
	 * Get information about accepted invite from current session.
	 *
	 * If user has just joined the application after accepting an invitation, function returns array
	 * with two elements:
	 * inviter - User ID of the person who sent invitation.
	 * extra - Extra string data attached to invitation (or false if there are no data)
	 * This function has to be called after getSession().
	 *
	 * @return array Invitation info or false if no invitation has been accepted.
	 */
	public function getInviteInfo(){
		return $this->sessionFetch('draugiem_invite', false);
	}

	/**
	 * Get HTML for embedding Javascript code to allow to resize application iframe and perform other actions.
	 *
	 * Javascript code will automatically try to resize iframe according to the height of DOM element with ID that is passed
	 * in $resize_container parameter.
	 *
	 * Function also enables Javascript callback values if $callback_html argument is passed. It has to contain full
	 * address of the copy of callback.html on the application server (e.g. http://example.com/callback.html).
	 * Original can be found at http://www.draugiem.lv/applications/external/callback.html
	 *
	 * This function has to be called after getSession().
	 *
	 * @param bool|string $resize_container DOM element ID of page container element
	 * @param bool|string $callback_html address of callback.html Optional if no return values for Javascript API functions are needed.
	 * @return string HTML code that needs to be displayed to embed Draugiem.lv Javascript
	 */
	public function getJavascript($resize_container = false, $callback_html = false){
		$data = '<script type="text/javascript" src="'.self::JS_URL.'" charset="utf-8"></script>'."\n";
		$data.= '<script type="text/javascript">'."\n";
		if($resize_container){
			$data.= " var draugiem_container='$resize_container';\n";
		}
		if($this->getSessionDomain()){
			$data.= " var draugiem_domain='".$this->getSessionDomain()."';\n";
		}
		if($callback_html){
			$data.= " var draugiem_callback_url='".$callback_html."';\n";
		}
		$data.='</script>'."\n";
		return $data;
	}

	/**
	 * Workaround for cookie creation problems in iframe with IE and Safari.
	 *
	 * This function has to be called before getSession() and after session_start()
	 */
	public function cookieFix(){

		$agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

		// IE cookie fix (http://stackoverflow.com/questions/389456/ (Must be sent every time))
		if(strpos($agent, 'MSIE') || (strpos($agent, 'rv:11') && strpos($agent, 'like Gecko'))){
			header('P3P:CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
		}

		$isSafariBrowser = strpos($agent, 'Safari') !== false && strpos($agent, 'Chrome') === false;
		$sessionCookieExists = !empty($_COOKIE[$this->sessionGetCookieName()]);

		// Safari cookie fix
		if(!$sessionCookieExists && $isSafariBrowser && $this->queryGet('dr_auth_code') && !$this->queryGet('dr_cookie_fix')){

			$formAction = '?' . http_build_query($this->queryGetAll());
			$formInputs = '';
			foreach($this->postGetAll() as $k => $v){
				$formInputs .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
			}
			
			echo "
			<html>
				<head>
					<script type='text/javascript'>
						window.onload = function() {
							fix();
						};
						function fix() {
							var w = window.open('/callback.html');
							w.onload = function() {
								w.document.cookie = 'safari_fix=1; path=/';
								w.close();
								document.getElementById('fixLink').innerHTML = 'Loading...';
								document.getElementById('fixForm').submit();
							};
						}
					</script>
				</head>
				<body>
					<form id='fixForm' method='post' action='{$formAction}'>{$formInputs}</form>
					<div id='fixLink'><input type='button' onclick='fix();' value='Enable Safari cookies'/></div>
				</body>
			</html>
			";
			exit;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Functions available only for approved applications
	|--------------------------------------------------------------------------
	*/

	/**
	 * Adds entry to current user's profile activity feed. Function available only for selected applications.
	 * Contact api@draugiem.lv to request posting permissions for your application.
	 * @param string $text Link text of the activity
	 * @param string $prefix Text before the activity link
	 * @param string $link Target URL of the activity link (must be in the same domain as application).
	 * @param boolean $pageId Business page id.
	 * @return boolean Returns true if activity was created successfully or false if it was not created (permission was denied or activity posting limit was reached).
	 */
	public function addActivity($text, $prefix = '', $link = '', $pageId = false){
		$response = $this->apiCall('add_activity', array('text' => $text, 'prefix' => $prefix, 'link' => $link, 'page_id' => $pageId));
		if(!empty($response['status'])){
			if($response['status'] == 'OK'){
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds notification to current user's profile news box. Function available only for selected applications.
	 * Contact api@draugiem.lv to request posting permissions for your application.
	 * @param string $text Link text of the notification
	 * @param string $prefix Text before the notification link
	 * @param string $link Target URL of the notification link (must be in the same domain as application).
	 * @param int $creator User ID of the user that created the notification (if it is 0, application name wil be shown as creator)
	 * @return boolean Returns true if notification was created successfully or false if it was not created (permission was denied or posting limit was reached).
	 */
	public function addNotification($text, $prefix = '', $link = '', $creator = 0){
		$response = $this->apiCall('add_notification', array('text' => $text, 'prefix' => $prefix, 'link' => $link, 'creator' => $creator));
		if(!empty($response['status'])){
			if($response['status'] == 'OK'){
				return true;
			}
		}
		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Utility functions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Inner function that calls Draugiem.lv API and returns response as an array
	 *
	 * @param string $action API action that has to be called
	 * @param array $args Key/value pairs of additional parameters for the request (excluding app, apikey and action)
	 * @param string $method
	 * @return mixed API response data or false if the request has failed
	 */
	public function apiCall($action, $args = array(), $method = 'GET'){

		if(($method == 'POST') && function_exists('curl_init')){
			$params = array();
			$params['app'] = $this->appKey;
			if($this->userApiKey){//User has been authorized
				$params['apikey'] = $this->userApiKey;
			}
			$params['action'] = $action;
			$params += $args;

			$ch = curl_init(self::API_URL);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			$response = curl_exec($ch);
			curl_close($ch);
			return $response;
		}

		$url =self::API_URL.'?app='.$this->appKey;
		if($this->userApiKey){//User has been authorized
			$url.='&apikey='.$this->userApiKey;
		}
		$url.='&action='.$action;
		if(!empty($args)){
			foreach($args as $k=>$v){
				if($v!==false){
					$url.='&'.urlencode($k).'='.urlencode($v);
				}
			}
		}

		$response = false;

		if(function_exists('curl_init')){
			$ch = curl_init();
			$timeout = 5;
			curl_setopt($ch,CURLOPT_URL,$url);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
			curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
			$response = curl_exec($ch);
			curl_close($ch);
		}elseif(ini_get('allow_url_fopen') == 1){
			$response = @file_get_contents($url);//Get API response (@ to avoid accidentaly displaying API keys in case of errors)
		}else{
			$parts = parse_url($url);
			$target = $parts['host'];
			$port = 80;

			$page    = isset($parts['path'])        ? $parts['path']            : '';
			$page   .= isset($parts['query'])       ? '?' . $parts['query']     : '';
			$page   .= isset($parts['fragment'])    ? '#' . $parts['fragment']  : '';
			$page    = ($page == '')                ? '/'                       : $page;
			if($fp = fsockopen($target, $port, $errno, $errstr, 30))
			{
				$headers  = "GET $page HTTP/1.1\r\n";
				$headers .= "Host: {$parts['host']}\r\n";
				$headers .= "Connection: Close\r\n\r\n";
				if(fwrite($fp, $headers)){
					$response = '';
					while (!feof($fp)) {
						$response .= fgets($fp, 128);
					}
					$response = substr($response, stripos($response, 'a:'));
				}
				fclose($fp);
			}
		}

		if($response === false){//Request failed
			$this->lastError = 1;
			$this->lastErrorDescription = 'No response from API server';
			return false;
		}

		$response = unserialize($response);

		if(empty($response)){
			$this->lastError = 2;
			$this->lastErrorDescription = 'Empty API response';
			return false;
		} else {
			if(isset($response['error'])){
				$this->lastError = $response['error']['code'];
				$this->lastErrorDescription = 'API error: '.$response['error']['description'];
				return false;
			} else {
				return $response;
			}
		}
	}

	public function clearSession(){
		$this->sessionRemove('draugiem_auth_code');
		$this->sessionRemove('draugiem_session');
		$this->sessionRemove('draugiem_userkey');
		$this->sessionRemove('draugiem_user');
		$this->sessionRemove('draugiem_lastcheck');
		$this->sessionRemove('draugiem_language');
		$this->sessionRemove('draugiem_domain');
		$this->sessionRemove('draugiem_invite');
	}

	/*
	|-----------------------------------------------------------------------------
	| Session mechanism functions. Override if needed for specific frameworks, etc
	|-----------------------------------------------------------------------------
	*/

	/**
	 * Get value from session
	 * @param string $key
	 * @param mixed|null $default Default value to return, if no value exists
	 * @return mixed
	 */
	protected function sessionFetch($key, $default = null){
		return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
	}

	/**
	 * Store value in session
	 * @param string $key
	 * @param mixed $value
	 */
	protected function sessionPut($key, $value){
		$_SESSION[$key] = $value;
	}

	/**
	 * Unset value from session
	 * @param $key
	 */
	protected function sessionRemove($key){
		unset($_SESSION[$key]);
	}

	/**
	 * Begin session (if not stated already)
	 */
	protected function sessionStart(){
		if(session_id() == ''){
			session_start();
		}
	}

	/**
	 * Get session name (cookie name for session)
	 * @return string
	 */
	protected function sessionGetCookieName(){
		return session_name();
	}

	/**
	 * Get a value from query string ($_GET)
	 * @param string $key
	 * @param mixed $default Default value
	 * @return string
	 */
	protected function queryGet($key, $default = null){
		return isset($_GET[$key]) ? $_GET[$key] : $default;
	}

	/**
	 * @return array Whole $_GET array
	 */
	protected function queryGetAll(){
		return $_GET;
	}

	/**
	 * @return array Whole $_POST data array
	 */
	protected function postGetAll(){
		return $_POST;
	}
}

class Draugiem_Api extends DraugiemApi {}
