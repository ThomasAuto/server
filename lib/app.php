<?php
/**
 * ownCloud
 *
 * @author Frank Karlitschek
 * @author Jakob Sack
 * @copyright 2012 Frank Karlitschek frank@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * This class manages the apps. It allows them to register and integrate in the
 * owncloud ecosystem. Furthermore, this class is responsible for installing,
 * upgrading and removing apps.
 */
class OC_App{
	static private $activeapp = '';
	static private $navigation = array();
	static private $settingsForms = array();
	static private $adminForms = array();
	static private $personalForms = array();
	static private $appInfo = array();
	static private $appTypes = array();
	static private $loadedApps = array();
	static private $checkedApps = array();
	static private $altLogin = array();

	/**
	 * @brief loads all apps
	 * @param array $types
	 * @return bool
	 *
	 * This function walks through the owncloud directory and loads all apps
	 * it can find. A directory contains an app if the file /appinfo/app.php
	 * exists.
	 *
	 * if $types is set, only apps of those types will be loaded
	 */
	public static function loadApps($types=null) {
		// Load the enabled apps here
		$apps = self::getEnabledApps();
		// prevent app.php from printing output
		ob_start();
		foreach( $apps as $app ) {
			if((is_null($types) or self::isType($app, $types)) && !in_array($app, self::$loadedApps)) {
				self::loadApp($app);
				self::$loadedApps[] = $app;
			}
		}
		ob_end_clean();

		if (!defined('DEBUG') || !DEBUG) {
			if (is_null($types)
				&& empty(OC_Util::$core_scripts)
				&& empty(OC_Util::$core_styles)) {
				OC_Util::$core_scripts = OC_Util::$scripts;
			OC_Util::$scripts = array();
			OC_Util::$core_styles = OC_Util::$styles;
			OC_Util::$styles = array();
		}
	}
		// return
	return true;
}

	/**
	 * load a single app
	 * @param string $app
	 */
	public static function loadApp($app) {
		if(is_file(self::getAppPath($app).'/appinfo/app.php')) {
			self::checkUpgrade($app);
			require_once $app.'/appinfo/app.php';
		}
	}

	/**
	 * check if an app is of a specific type
	 * @param string $app
	 * @param string/array $types
	 * @return bool
	 */
	public static function isType($app, $types) {
		if(is_string($types)) {
			$types=array($types);
		}
		$appTypes=self::getAppTypes($app);
		foreach($types as $type) {
			if(array_search($type, $appTypes)!==false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * get the types of an app
	 * @param string $app
	 * @return array
	 */
	private static function getAppTypes($app) {
		//load the cache
		if(count(self::$appTypes)==0) {
			self::$appTypes=OC_Appconfig::getValues(false, 'types');
		}

		if(isset(self::$appTypes[$app])) {
			return explode(',', self::$appTypes[$app]);
		}else{
			return array();
		}
	}

	/**
	 * read app types from info.xml and cache them in the database
	 */
	public static function setAppTypes($app) {
		$appData=self::getAppInfo($app);

		if(isset($appData['types'])) {
			$appTypes=implode(',', $appData['types']);
		}else{
			$appTypes='';
		}

		OC_Appconfig::setValue($app, 'types', $appTypes);
	}

	/**
	 * check if app is shipped
	 * @param string $appid the id of the app to check
	 * @return bool
	 */
	public static function isShipped($appid){
		$info = self::getAppInfo($appid);
		if(isset($info['shipped']) && $info['shipped']=='true'){
			return true;
		} else {
			return false;
		}
	}

	/**
	 * get all enabled apps
	 */
	public static function getEnabledApps() {
		if(!OC_Config::getValue('installed', false))
			return array();
		$apps=array('files');
		$query = OC_DB::prepare( 'SELECT `appid` FROM `*PREFIX*appconfig` WHERE `configkey` = \'enabled\' AND `configvalue`=\'yes\'' );
		$result=$query->execute();
		while($row=$result->fetchRow()) {
			if(array_search($row['appid'], $apps)===false) {
				$apps[]=$row['appid'];
			}
		}
		return $apps;
	}

	/**
	 * @brief checks whether or not an app is enabled
	 * @param string $app app
	 * @return bool
	 *
	 * This function checks whether or not an app is enabled.
	 */
	public static function isEnabled( $app ) {
		if( 'files'==$app or 'yes' == OC_Appconfig::getValue( $app, 'enabled' )) {
			return true;
		}

		return false;
	}

	/**
	 * @brief enables an app
	 * @param mixed $app app
	 * @return bool
	 *
	 * This function set an app as enabled in appconfig.
	 */
	public static function enable( $app ) {
		if(!OC_Installer::isInstalled($app)) {
			// check if app is a shipped app or not. OCS apps have an integer as id, shipped apps use a string
			if(!is_numeric($app)) {
				$app = OC_Installer::installShippedApp($app);
			}else{
				$download=OC_OCSClient::getApplicationDownload($app, 1);
				if(isset($download['downloadlink']) and $download['downloadlink']!='') {
					$app=OC_Installer::installApp(array('source'=>'http', 'href'=>$download['downloadlink']));
				}
			}
		}
		if($app!==false) {
			// check if the app is compatible with this version of ownCloud
			$info=OC_App::getAppInfo($app);
			$version=OC_Util::getVersion();
			if(!isset($info['require']) or ($version[0]>$info['require'])) {
				OC_Log::write('core', 'App "'.$info['name'].'" can\'t be installed because it is not compatible with this version of ownCloud', OC_Log::ERROR);
				return false;
			}else{
				OC_Appconfig::setValue( $app, 'enabled', 'yes' );
				return true;
			}
		}else{
			return false;
		}
	}

	/**
	 * @brief disables an app
	 * @param string $app app
	 * @return bool
	 *
	 * This function set an app as disabled in appconfig.
	 */
	public static function disable( $app ) {
		// check if app is a shiped app or not. if not delete
		OC_Appconfig::setValue( $app, 'enabled', 'no' );
	}

	/**
	 * @brief adds an entry to the navigation
	 * @param string $data array containing the data
	 * @return bool
	 *
	 * This function adds a new entry to the navigation visible to users. $data
	 * is an associative array.
	 * The following keys are required:
	 *   - id: unique id for this entry ('addressbook_index')
	 *   - href: link to the page
	 *   - name: Human readable name ('Addressbook')
	 *
	 * The following keys are optional:
	 *   - icon: path to the icon of the app
	 *   - order: integer, that influences the position of your application in
	 *     the navigation. Lower values come first.
	 */
	public static function addNavigationEntry( $data ) {
		$data['active']=false;
		if(!isset($data['icon'])) {
			$data['icon']='';
		}
		OC_App::$navigation[] = $data;
		return true;
	}

	/**
	 * @brief marks a navigation entry as active
	 * @param string $id id of the entry
	 * @return bool
	 *
	 * This function sets a navigation entry as active and removes the 'active'
	 * property from all other entries. The templates can use this for
	 * highlighting the current position of the user.
	 */
	public static function setActiveNavigationEntry( $id ) {
		// load all the apps, to make sure we have all the navigation entries
		self::loadApps();
		self::$activeapp = $id;
		return true;
	}

	/**
	 * @brief gets the active Menu entry
	 * @return string id or empty string
	 *
	 * This function returns the id of the active navigation entry (set by
	 * setActiveNavigationEntry
	 */
	public static function getActiveNavigationEntry() {
		return self::$activeapp;
	}

	/**
	 * @brief Returns the Settings Navigation
	 * @return array
	 *
	 * This function returns an array containing all settings pages added. The
	 * entries are sorted by the key 'order' ascending.
	 */
	public static function getSettingsNavigation() {
		$l=OC_L10N::get('lib');

		$settings = array();
		// by default, settings only contain the help menu
		if(OC_Config::getValue('knowledgebaseenabled', true)==true) {
			$settings = array(
				array( "id" => "help", "order" => 1000, "href" => OC_Helper::linkToRoute( "settings_help" ), "name" => $l->t("Help"), "icon" => OC_Helper::imagePath( "settings", "help.svg" ))
				);
		}

		// if the user is logged-in
		if (OC_User::isLoggedIn()) {
			// personal menu
			$settings[] = array( "id" => "personal", "order" => 1, "href" => OC_Helper::linkToRoute( "settings_personal" ), "name" => $l->t("Personal"), "icon" => OC_Helper::imagePath( "settings", "personal.svg" ));

			// if there are some settings forms
			if(!empty(self::$settingsForms))
				// settings menu
				$settings[]=array( "id" => "settings", "order" => 1000, "href" => OC_Helper::linkToRoute( "settings_settings" ), "name" => $l->t("Settings"), "icon" => OC_Helper::imagePath( "settings", "settings.svg" ));

			//SubAdmins are also allowed to access user management
			if(OC_SubAdmin::isSubAdmin(OC_User::getUser())) {
				// admin users menu
				$settings[] = array( "id" => "core_users", "order" => 2, "href" => OC_Helper::linkToRoute( "settings_users" ), "name" => $l->t("Users"), "icon" => OC_Helper::imagePath( "settings", "users.svg" ));
			}


			// if the user is an admin
			if(OC_User::isAdminUser(OC_User::getUser())) {
				// admin apps menu
				$settings[] = array( "id" => "core_apps", "order" => 3, "href" => OC_Helper::linkToRoute( "settings_apps" ).'?installed', "name" => $l->t("Apps"), "icon" => OC_Helper::imagePath( "settings", "apps.svg" ));

				$settings[]=array( "id" => "admin", "order" => 1000, "href" => OC_Helper::linkToRoute( "settings_admin" ), "name" => $l->t("Admin"), "icon" => OC_Helper::imagePath( "settings", "admin.svg" ));
			}
		}

		$navigation = self::proceedNavigation($settings);
		return $navigation;
	}

	/// This is private as well. It simply works, so don't ask for more details
	private static function proceedNavigation( $list ) {
		foreach( $list as &$naventry ) {
			if( $naventry['id'] == self::$activeapp ) {
				$naventry['active'] = true;
			}
			else{
				$naventry['active'] = false;
			}
		} unset( $naventry );

		usort( $list, create_function( '$a, $b', 'if( $a["order"] == $b["order"] ) {return 0;}elseif( $a["order"] < $b["order"] ) {return -1;}else{return 1;}' ));

		return $list;
	}

	/**
	 * Get the path where to install apps
	 */
	public static function getInstallPath() {
		if(OC_Config::getValue('appstoreenabled', true)==false) {
			return false;
		}

		foreach(OC::$APPSROOTS as $dir) {
			if(isset($dir['writable']) && $dir['writable']===true)
				return $dir['path'];
		}

		OC_Log::write('core', 'No application directories are marked as writable.', OC_Log::ERROR);
		return null;
	}


	protected static function findAppInDirectories($appid) {
		static $app_dir = array();
		if (isset($app_dir[$appid])) {
			return $app_dir[$appid];
		}
		foreach(OC::$APPSROOTS as $dir) {
			if(file_exists($dir['path'].'/'.$appid)) {
				return $app_dir[$appid]=$dir;
			}
		}
		return false;
	}
	/**
	* Get the directory for the given app.
	* If the app is defined in multiple directory, the first one is taken. (false if not found)
	*/
	public static function getAppPath($appid) {
		if( ($dir = self::findAppInDirectories($appid)) != false) {
			return $dir['path'].'/'.$appid;
		}
		return false;
	}

	/**
	* Get the path for the given app on the access
	* If the app is defined in multiple directory, the first one is taken. (false if not found)
	*/
	public static function getAppWebPath($appid) {
		if( ($dir = self::findAppInDirectories($appid)) != false) {
			return OC::$WEBROOT.$dir['url'].'/'.$appid;
		}
		return false;
	}

	/**
	 * get the last version of the app, either from appinfo/version or from appinfo/info.xml
	 */
	public static function getAppVersion($appid) {
		$file= self::getAppPath($appid).'/appinfo/version';
		if(is_file($file) && $version = trim(file_get_contents($file))) {
			return $version;
		}else{
			$appData=self::getAppInfo($appid);
			return isset($appData['version'])? $appData['version'] : '';
		}
	}

	/**
	 * @brief Read all app metadata from the info.xml file
	 * @param string $appid id of the app or the path of the info.xml file
	 * @param boolean $path (optional)
	 * @return array
	 * @note all data is read from info.xml, not just pre-defined fields
	*/
	public static function getAppInfo($appid, $path=false) {
		if($path) {
			$file=$appid;
		}else{
			if(isset(self::$appInfo[$appid])) {
				return self::$appInfo[$appid];
			}
			$file= self::getAppPath($appid).'/appinfo/info.xml';
		}
		$data=array();
		$content=@file_get_contents($file);
		if(!$content) {
			return null;
		}
		$xml = new SimpleXMLElement($content);
		$data['info']=array();
		$data['remote']=array();
		$data['public']=array();
		foreach($xml->children() as $child) {
			/**
			 * @var $child SimpleXMLElement
			 */
			if($child->getName()=='remote') {
				foreach($child->children() as $remote) {
					/**
					 * @var $remote SimpleXMLElement
					 */
					$data['remote'][$remote->getName()]=(string)$remote;
				}
			}elseif($child->getName()=='public') {
				foreach($child->children() as $public) {
					/**
					 * @var $public SimpleXMLElement
					 */
					$data['public'][$public->getName()]=(string)$public;
				}
			}elseif($child->getName()=='types') {
				$data['types']=array();
				foreach($child->children() as $type) {
					/**
					 * @var $type SimpleXMLElement
					 */
					$data['types'][]=$type->getName();
				}
			}elseif($child->getName()=='description') {
				$xml=(string)$child->asXML();
				$data[$child->getName()]=substr($xml, 13, -14);//script <description> tags
			}else{
				$data[$child->getName()]=(string)$child;
			}
		}
		self::$appInfo[$appid]=$data;

		return $data;
	}

	/**
	 * @brief Returns the navigation
	 * @return array
	 *
	 * This function returns an array containing all entries added. The
	 * entries are sorted by the key 'order' ascending. Additional to the keys
	 * given for each app the following keys exist:
	 *   - active: boolean, signals if the user is on this navigation entry
	 */
	public static function getNavigation() {
		$navigation = self::proceedNavigation( self::$navigation );
		return $navigation;
	}

	/**
	 * get the id of loaded app
	 * @return string
	 */
	public static function getCurrentApp() {
		$script=substr($_SERVER["SCRIPT_NAME"], strlen(OC::$WEBROOT)+1);
		$topFolder=substr($script, 0, strpos($script, '/'));
		if (empty($topFolder)) {
			$path_info = OC_Request::getPathInfo();
			if ($path_info) {
				$topFolder=substr($path_info, 1, strpos($path_info, '/', 1)-1);
			}
		}
		if($topFolder=='apps') {
			$length=strlen($topFolder);
			return substr($script, $length+1, strpos($script, '/', $length+1)-$length-1);
		}else{
			return $topFolder;
		}
	}


	/**
	 * get the forms for either settings, admin or personal
	 */
	public static function getForms($type) {
		$forms=array();
		switch($type) {
			case 'settings':
			$source=self::$settingsForms;
			break;
			case 'admin':
			$source=self::$adminForms;
			break;
			case 'personal':
			$source=self::$personalForms;
			break;
			default:
			return array();
		}
		foreach($source as $form) {
			$forms[]=include $form;
		}
		return $forms;
	}

	/**
	 * register a settings form to be shown
	 */
	public static function registerSettings($app, $page) {
		self::$settingsForms[]= $app.'/'.$page.'.php';
	}

	/**
	 * register an admin form to be shown
	 */
	public static function registerAdmin($app, $page) {
		self::$adminForms[]= $app.'/'.$page.'.php';
	}

	/**
	 * register a personal form to be shown
	 */
	public static function registerPersonal($app, $page) {
		self::$personalForms[]= $app.'/'.$page.'.php';
	}

	public static function registerLogIn($entry) {
		self::$altLogin[] = $entry;
	}

	public static function getAlternativeLogIns() {
		return self::$altLogin;
	}

	/**
	 * @brief: get a list of all apps in the apps folder
	 * @return array or app names (string IDs)
	 * @todo: change the name of this method to getInstalledApps, which is more accurate
	 */
	public static function getAllApps() {

		$apps=array();

		foreach ( OC::$APPSROOTS as $apps_dir ) {
			if(! is_readable($apps_dir['path'])) {
				OC_Log::write('core', 'unable to read app folder : ' .$apps_dir['path'], OC_Log::WARN);
				continue;
			}
			$dh = opendir( $apps_dir['path'] );

			while( $file = readdir( $dh ) ) {

				if ($file[0] != '.' and is_file($apps_dir['path'].'/'.$file.'/appinfo/app.php')) {

					$apps[] = $file;

				}

			}

		}

		return $apps;
	}

	/**
	 * @brief: Lists all apps, this is used in apps.php
	 * @return array
	 */
	public static function listAllApps() {
		$installedApps = OC_App::getAllApps();

		//TODO which apps do we want to blacklist and how do we integrate blacklisting with the multi apps folder feature?

		$blacklist = array('files');//we dont want to show configuration for these
		$appList = array();

		foreach ( $installedApps as $app ) {
			if ( array_search( $app, $blacklist ) === false ) {

				$info=OC_App::getAppInfo($app);

				if (!isset($info['name'])) {
					OC_Log::write('core', 'App id "'.$app.'" has no name in appinfo', OC_Log::ERROR);
					continue;
				}

				if ( OC_Appconfig::getValue( $app, 'enabled', 'no') == 'yes' ) {
					$active = true;
				} else {
					$active = false;
				}

				$info['active'] = $active;

				if(isset($info['shipped']) and ($info['shipped']=='true')) {
					$info['internal']=true;
					$info['internallabel']='Internal App';
				} else {
					$info['internal']=false;
					$info['internallabel']='3rd Party App';
				}

				$info['preview'] = OC_Helper::imagePath('settings', 'trans.png');
				$info['version'] = OC_App::getAppVersion($app);
				$appList[] = $info;
			}
		}
		$remoteApps = OC_App::getAppstoreApps();
		if ( $remoteApps ) {
	// Remove duplicates
			foreach ( $appList as $app ) {
				foreach ( $remoteApps AS $key => $remote ) {
					if (
						$app['name'] == $remote['name']
			// To set duplicate detection to use OCS ID instead of string name,
			// enable this code, remove the line of code above,
			// and add <ocs_id>[ID]</ocs_id> to info.xml of each 3rd party app:
			// OR $app['ocs_id'] == $remote['ocs_id']
						) {
						unset( $remoteApps[$key]);
				}
			}
		}
		$combinedApps = array_merge( $appList, $remoteApps );
	} else {
		$combinedApps = $appList;
	}	
	return $combinedApps;	
}

	/**
	 * @brief: get a list of all apps on apps.owncloud.com
	 * @return array, multi-dimensional array of apps. Keys: id, name, type, typename, personid, license, detailpage, preview, changed, description
	 */
	public static function getAppstoreApps( $filter = 'approved' ) {
		$catagoryNames = OC_OCSClient::getCategories();
		if ( is_array( $catagoryNames ) ) {
			// Check that categories of apps were retrieved correctly
			if ( ! $categories = array_keys( $catagoryNames ) ) {
				return false;
			}

			$page = 0;
			$remoteApps = OC_OCSClient::getApplications( $categories, $page, $filter );
			$app1 = array();
			$i = 0;
			foreach ( $remoteApps as $app ) {
				$app1[$i] = $app;
				$app1[$i]['author'] = $app['personid'];
				$app1[$i]['ocs_id'] = $app['id'];
				$app1[$i]['internal'] = $app1[$i]['active'] = 0;

				// rating img
				if($app['score']>=0     and $app['score']<5)	$img=OC_Helper::imagePath( "core", "rating/s1.png" );
				elseif($app['score']>=5 and $app['score']<15)	$img=OC_Helper::imagePath( "core", "rating/s2.png" );
				elseif($app['score']>=15 and $app['score']<25)	$img=OC_Helper::imagePath( "core", "rating/s3.png" );
				elseif($app['score']>=25 and $app['score']<35)	$img=OC_Helper::imagePath( "core", "rating/s4.png" );
				elseif($app['score']>=35 and $app['score']<45)	$img=OC_Helper::imagePath( "core", "rating/s5.png" );
				elseif($app['score']>=45 and $app['score']<55)	$img=OC_Helper::imagePath( "core", "rating/s6.png" );
				elseif($app['score']>=55 and $app['score']<65)	$img=OC_Helper::imagePath( "core", "rating/s7.png" );
				elseif($app['score']>=65 and $app['score']<75)	$img=OC_Helper::imagePath( "core", "rating/s8.png" );
				elseif($app['score']>=75 and $app['score']<85)	$img=OC_Helper::imagePath( "core", "rating/s9.png" );
				elseif($app['score']>=85 and $app['score']<95)	$img=OC_Helper::imagePath( "core", "rating/s10.png" );
				elseif($app['score']>=95 and $app['score']<100)	$img=OC_Helper::imagePath( "core", "rating/s11.png" );

				$app1[$i]['score'] = '<img src="'.$img.'"> Score: '.$app['score'].'%';
				$i++;
			}
		}

		if ( empty( $app1 ) ) {
			return false;
		} else {
			return $app1;
		}
	}

	/**
	 * check if the app need updating and update when needed
	 */
	public static function checkUpgrade($app) {
		if (in_array($app, self::$checkedApps)) {
			return;
		}
		self::$checkedApps[] = $app;
		$versions = self::getAppVersions();
		$currentVersion=OC_App::getAppVersion($app);
		if ($currentVersion) {
			$installedVersion = $versions[$app];
			if (version_compare($currentVersion, $installedVersion, '>')) {
				$info = self::getAppInfo($app);
				OC_Log::write($app, 'starting app upgrade from '.$installedVersion.' to '.$currentVersion, OC_Log::DEBUG);
				try {
					OC_App::updateApp($app);
					OC_Hook::emit('update', 'success', 'Updated '.$info['name'].' app');
				}
				catch (Exception $e) {
					echo 'Failed to upgrade "'.$app.'". Exception="'.$e->getMessage().'"';
					OC_Hook::emit('update', 'failure', 'Failed to update '.$info['name'].' app: '.$e->getMessage());
					die;
				}
				OC_Appconfig::setValue($app, 'installed_version', OC_App::getAppVersion($app));
			}
		}
	}

	/**
	 * check if the current enabled apps are compatible with the current
	 * ownCloud version. disable them if not.
	 * This is important if you upgrade ownCloud and have non ported 3rd
	 * party apps installed.
	 */
	public static function checkAppsRequirements($apps = array()) {
		if (empty($apps)) {
			$apps = OC_App::getEnabledApps();
		}
		$version = OC_Util::getVersion();
		foreach($apps as $app) {
			// check if the app is compatible with this version of ownCloud
			$info = OC_App::getAppInfo($app);
			if(!isset($info['require']) or (($version[0].'.'.$version[1])>$info['require'])) {
				OC_Log::write('core', 'App "'.$info['name'].'" ('.$app.') can\'t be used because it is not compatible with this version of ownCloud', OC_Log::ERROR);
				OC_App::disable( $app );
				OC_Hook::emit('update', 'success', 'Disabled '.$info['name'].' app because it is not compatible');
			}
		}
	}

	/**
	 * get the installed version of all apps
	 */
	public static function getAppVersions() {
		static $versions;
		if (isset($versions)) {   // simple cache, needs to be fixed
			return $versions; // when function is used besides in checkUpgrade
		}
		$versions=array();
		$query = OC_DB::prepare( 'SELECT `appid`, `configvalue` FROM `*PREFIX*appconfig` WHERE `configkey` = \'installed_version\'' );
		$result = $query->execute();
		while($row = $result->fetchRow()) {
			$versions[$row['appid']]=$row['configvalue'];
		}
		return $versions;
	}

	/**
	 * update the database for the app and call the update script
	 * @param string $appid
	 */
	public static function updateApp($appid) {
		if(file_exists(self::getAppPath($appid).'/appinfo/preupdate.php')) {
			self::loadApp($appid);
			include self::getAppPath($appid).'/appinfo/preupdate.php';
		}
		if(file_exists(self::getAppPath($appid).'/appinfo/database.xml')) {
			OC_DB::updateDbFromStructure(self::getAppPath($appid).'/appinfo/database.xml');
		}
		if(!self::isEnabled($appid)) {
			return;
		}
		if(file_exists(self::getAppPath($appid).'/appinfo/update.php')) {
			self::loadApp($appid);
			include self::getAppPath($appid).'/appinfo/update.php';
		}

		//set remote/public handlers
		$appData=self::getAppInfo($appid);
		foreach($appData['remote'] as $name=>$path) {
			OCP\CONFIG::setAppValue('core', 'remote_'.$name, $appid.'/'.$path);
		}
		foreach($appData['public'] as $name=>$path) {
			OCP\CONFIG::setAppValue('core', 'public_'.$name, $appid.'/'.$path);
		}

		self::setAppTypes($appid);
	}

	/**
	 * @param string $appid
	 * @return OC_FilesystemView
	 */
	public static function getStorage($appid) {
		if(OC_App::isEnabled($appid)) {//sanity check
			if(OC_User::isLoggedIn()) {
				$view = new OC_FilesystemView('/'.OC_User::getUser());
				if(!$view->file_exists($appid)) {
					$view->mkdir($appid);
				}
				return new OC_FilesystemView('/'.OC_User::getUser().'/'.$appid);
			}else{
				OC_Log::write('core', 'Can\'t get app storage, app '.$appid.', user not logged in', OC_Log::ERROR);
				return false;
			}
		}else{
			OC_Log::write('core', 'Can\'t get app storage, app '.$appid.' not enabled', OC_Log::ERROR);
			return false;
		}
	}
}
