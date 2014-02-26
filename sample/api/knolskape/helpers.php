<?php
/**
 * Created on Jul 16, 2009
 * Modified on Jun 29, 2012
 *
 * @description Helpers php framework helps you stay DRY!
 * @author Shrikant Sharat KANDULA
 * @modified-by Anjaneyulu Reddy BEERAVALLI <anji.t6@gmail.com>
 * @copyright KNOLSKAPE Solutions Pte. Ltd.
 **/

/*
TODO (in order of importance)
- [URGENT] Fix dump_on_done(). It is trying to set headers after all output is sent.
  Need to do output buffering, since it cannot do anything until all output is sent.
- Improve XML support. It has become almost unreliable due to lack of need and
  knowledge about PHP's XML APIs.
- Put the error reports along with date/time information into a persistent log
  storage, to help debug errors in production environment.
- Improve request method warnings to more accurately detect inconsistencies.
- Test automation support with (complete?) output buffering (Plan draft pending).
- API for Result set pagination
- Support for mod_rewrite and beautiful urls (Plan draft pending).
*/

mysqli_report(MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_INDEX);

$inc_path = dirname(__FILE__) . '/helpers-inc';

class Helpers {

	/**
	 * Database setting to use.
	 */
	public static $use_db = 'test';

	/**
	 * Array of databases.
	 */
	public static $databases = array();

	/**
	 * Default serialization format. Currently supported values are `json` (default) and `xml`.
	 */
	public static $dataformat = 'json';

	/**
	 * The session name used for this project. It is recommended to set this in your project's
	 * config file and not use the provided default.
	 */
	public static $session_name = '_knolskape_session';

	/**
	 * Session paramaters to look for when validation session. Default is an array containing the string
	 * `user_id`. You can add more to it, or completely replace it with a new array.
	 */
	public static $session_params = array('user_id');

	private static $dump_on_done = null;

	/**
	 * An array that holds all the 'scanned' parameters this script needs. In other words, it will include
	 * all the stuff from previous `session_check` and `check_params` calls. You can simply extract this after
	 * your calls to these functions and simply use the variables independent of their source.
	 */
	public static $env = array();

	public static $db_charset = 'utf8';

	private static $db_con = null;
	
	private static $pdo_db_con = null;

	private static $last_query = null;

	private static $debug_mode = false;


	public static function debug_mode($mode = null) {
		if($mode !== null and $mode !== self::$debug_mode) {
			self::$debug_mode = $mode;
			FB::setEnabled($mode);
		}
		return self::$debug_mode;
	}

	/**
	 * Connect to the database according to the current configuration. If a connection is already available,
	 * return a link to that connection.
	 */
	public static function db_connect() {
		if(self::$db_con === null) {
			$db = self::$databases[self::$use_db];
			self::$db_con = new mysqli($db['hostname'], $db['username'], $db['password'], $db['database']);
			if(self::$db_con->connect_error) {
				self::err_kill('H-01', 'Unable to connect to db');
			}
			self::$db_con->set_charset(self::$db_charset);
		}
		return self::$db_con;
	}

	/**
	* PDO Connecto to database according to the current configuration. If a connection is already available,
	* return a link to that connection.
	*/
	public static function pdo_db_connect() {
		if(self::$pdo_db_con === null) {
			$db = self::$databases[self::$use_db];
			$dbhost = $db['hostname'];
			if( isset($db['database']) ){
				$dbname = $db['database'];
				self::$pdo_db_con = new PDO("mysql:host=$dbhost;dbname=$dbname", $db['username'], $db['password']);
			} else {
				self::$pdo_db_con = new PDO("mysql:host=$dbhost;", $db['username'], $db['password']);
			}
			self::$pdo_db_con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		}
		return self::$pdo_db_con;
	}
	
	/**
	 * Close remembered database connection so as to force a new connection in the next pdo_db_connect call.
	 * This function should not be necessary in most cases, but it present in case the need arises.
	*/
	public static function pdo_db_close() {
		self::$pdo_db_con = null;
	}
	
	/**
	 * Close remembered database connection so as to force a new connection in the next db_connect call.
	 * This function should not be necessary in most cases, but is present in case the need arises.
	 */
	public static function db_close() {
		(self::$db_con === null) ? self::$db_con->close() : null;
		self::$db_con = null;
	}

	public static function execute_query($con, $sql, $params, $msg = null) {
		try {
			$stmt = $con->prepare($sql);
			foreach($params as $key => &$value) {
				$stmt->bindParam("$key", $value);
			}
			$stmt->execute();
			return $stmt;
		}
		catch(PDOException $e) {
			// TODO: log the error in error table
			// exit here
			$error = array();
			$error['success'] = 0;
			$error['error']['details'] = $e->getMessage();
			$error['error']['message'] = $msg;
			
			exit(json_encode($error));
		}
	}
	
	public static function sql($sql, $desc = null) {

		if(in_array(strtoupper(substr($sql, 0, 6)), array('INSERT', 'DELETE', 'UPDATE'))
			and $_SERVER['REQUEST_METHOD'] == 'GET') {
			FB::warn('It is recommended to use `POST` method on requests that do database manipulation.');
		}

		if(strtoupper(substr($sql, 0, 6)) == 'SELECT' and $_SERVER['REQUEST_METHOD'] == 'POST') {
			FB::warn('It is recommended to use `GET` method on requests that don\'t do database manipulation');
		}

		self::db_connect();
		self::$last_query = $sql;

		$result = self::$db_con->query($sql);

		if(!$result and $desc) {
			self::err_kill('H-03', sprintf("The query {%s} has failed", $desc));
		}

		return $result;

	}

	/**
	 * Check if the session is still available or not according to the current configuration. If the session
	 * seemed expired, terminate with an error message immediately.
	 */
	public static function session_check() {

		if(self::$session_name === '' or count(self::$session_params) === 0) {
			return array();
		}

		session_name(self::$session_name);
		session_start();

		return self::check_params(self::$session_params, $_SESSION, 'Session Expired. `%s` not found in session.');

	}

	/**
	 * Check if all expected parameters are available in the expected request method's request array. The
	 * first parameter is an array of strings which are the expected parameters to check for. The second
	 * is a string stating the request method to look in. The second parameter can be any of "get", "post", "put",
	 * "delete", "request" or any associative array, which will be used to check the parameters in.
	 * Returns an associative array containing all the paramters requested to check for, with values that are
	 * run through real_escape_string. Note that this does not escape '_' and '%' characters that have special
	 * meaning only with the LIKE statement. These are also added to $env.
	 */
	public static function check_params($params, $REQ, $error_msg = null) {

		if(is_string($params)) {
			$params = preg_split('/[\s,]+/', $params);
		}

		if($error_msg == null) {
			$error_msg = 'Parameter `%s` is not passed';
		}

		if(is_string($REQ)) {

			$req_type = strtolower($REQ);
			//$REQ = ${'_' . strtoupper($req_type)};

			switch($req_type) {
				case 'get': $REQ = $_GET; break;
				case 'post': $REQ = $_POST; break;
				case 'session': $REQ = $_SESSION; break;
				default: $REQ = $_REQUEST; break;
			}

			if(in_array($req_type, array('get', 'post')) and
				$req_type != strtolower($_SERVER['REQUEST_METHOD'])) {
				self::err_kill('H-04', "Unexpected Request Method. Instead of the expected "
					. strtoupper($req_type) . ", I got $_SERVER[REQUEST_METHOD]");
			}

		}

		$request = array();

		foreach($params as $p) {

			if(!isset($REQ[$p])) {
				self::err_kill('H-05', sprintf($error_msg, $p));
			}

			self::db_connect();

			$val = $REQ[$p];
			if(is_string($val)) {
				$val = self::$db_con->escape_string($val);
			}

			self::$env[$p] = $request[$p] = $val;

		}

		return $request;

	}

	/**
	 * @param String query This is a string containing the SQL query.
	 * @return String xml in a string containing the result from the query
	 */
	public static function get_xml($sql, $escape_chars=true) {
		self::db_connect();

		self::$last_query = $sql;

		$result = self::$db_con->query($sql) or self::err_kill('H-06', "Error executing query");
		$result->num_rows($result) or self::err_kill('H-07', "Zero row response");

		$cols = array();
		while ($col = $result->fetch_field($result)) {
			$cols[] = $col->name;
		}
		$column_count = sizeof($cols);

		$response = '<response success="1">';
		while ($row = $result->fetch_array($result)) {
			if (in_array('id', $cols)) {
				$response .= '<item id="' . $row['id'] . '">';
			} else {
				$response .= "<item>";
			}

			for ($i = 0; $i < $column_count; $i++) {
				$colName = $cols[$i];
				if($colName != 'id') {
					$response .= "<$colName>" . ($escape_chars ? htmlentities($row[$i]) : $row[$i]) . "</$colName>";
				}
			}
			$response .= "</item>";
		}
		$response .= "</response>";
		return $response;
	}

	/**
	 * Convenient function to show the xml output of the query and exit
	 * @param String query This is a string containing the SQL query.
	 */
	public static function show_xml($query, $escape_chars=true) {
		$response = self::get_xml($query, $escape_chars);
		self::content_header('xml');
		die($response);
	}

	/**
	 * @param String query This is a string containing the SQL query.
	 * @return json encoded text of the result from the query
	 */
	public static function get_json_obj($sql, $type_casts=null) {
		self::db_connect();

		self::$last_query = $sql;

		$result = self::sql($sql) or self::err_kill('H-08', "Error executing query");
		//$result->num_rows($result) or self::err_kill('H-09', "Zero row response");

		$data = array();

		while ($row = $result->fetch_assoc()) {

			if($type_casts != null) {
				foreach($type_casts as $col=>$type) {
					if(!isset($row[$col])) continue;

					switch($type) {
						case 'int': $row[$col] = (int) $row[$col]; break;
						case 'float': $row[$col] = (float) $row[$col]; break;
						case 'bool': $row[$col] = (bool) $row[$col]; break;
						case 'array': $row[$col] = explode(',', $row[$col]); break;
						case 'json': $row[$col] = json_decode($row[$col], true); break;
						case 'json-list': $row[$col] = json_decode('[' . $row[$col] . ']', true); break;
					}
				}
			}

			$data[] = $row;
		}

		return $data;
	}

	/**
	 * Convenient function to print json encoded text of the result from the query
	 * @param String query This is a string containing the SQL query.
	 */
	public static function show_json($query, $type_casts=null) {
		$response = self::base_response('json');
		$response['data'] = self::get_json_obj($query, $type_casts);
		self::content_header('json');
		die(json_encode($response));
	}

	/**
	 * Set the `Content-Type` header to an appropriate value corresponding to the
	 * optional parater type. If not type is given, the default data format is used.
	 * Can be any of "xml", "json", "html".
	 */
	public static function content_header($type = null) {

		if($type === null) {
			$type = self::$dataformat;
		}

		switch($type) {
		case 'xml': $h = 'text/xml'; break;
		case 'json': $h = 'application/json'; break;
		case 'html': $h = 'text/html'; break;
		case 'text': $h = 'text/plain'; break;
		default: $h = 'text/plain'; break;
		}

		header('Content-Type: ' . $h . ';charset=UTF-8' );

	}

	/**
	 * Returns an empty response object corresponding to the data type sent as argument of the default
	 * data type, if not arugument is given.
	 */
	public static function base_response($mode=null) {

		$mode = self::saner_mode($mode);

		$response = '';

		switch($mode) {
		case 'xml':
			$response = '<response success="1"></response>';
			break;
		case 'json':
			$response = array('success' => true);
			break;
		}

		return $response;

	}

	public static function err_kill($errno, $detail, $mode=null) {

		$mode = self::saner_mode($mode);

		self::content_header($mode);

		switch ($mode) {
		case 'xml':
			$msg = '<response success="0"><error code="' . $errno . '">'
				. "<detail>$detail</detail>"
				. "</error></response>";
			break;
		case 'json':
			$response = self::base_response('json');
			$response = array_merge($response, array(
				'success' => false,
				'error' => array(
					'code' => $errno,
					'detail' => $detail
				)
			));
			$msg = json_encode($response);
			break;
		default:
			self::content_header('html');
			$msg = '<h2>'
				. "Failure:<br/>"
				. "[ERROR-$errno] $detail<br/>"
				. '</h2>';
		}

		FB::error($detail);

		self::log_info('More Information (from err_kill)', false);

		die($msg);

	}

	public static function dump_on_done() {
		self::$dump_on_done = true;
		ob_start();
	}

	public static function log_info($msg = null, $collapsed = false) {

		if($msg == null) {
			$msg = 'Debug Information';
		}

		FB::group($msg, array( 'Collapsed' => $collapsed ));

		FB::log($_SERVER['SCRIPT_FILENAME'], 'File');
		FB::log($_SERVER['REQUEST_METHOD'], 'Request Method');
		FB::log(self::$env, 'Collected env');
		FB::log(self::$last_query, 'Last db query run');

		if(self::$db_con) {
			if(self::$db_con->connect_error) {
				FB::log(self::$db_con->connect_error, 'Connection Error');
			} else {
				FB::log(self::$db_con->error, 'Last db error');
			}
		} else {
			FB::log('No db connection available');
		}

		$super_globals = array(
			'_SERVER',
			'_GET',
			'_POST',
			'_FILES',
			'_REQUEST',
			'_SESSION',
			'_COOKIE',
			'_ENV'
		);

		FB::group('Variables Set');
		$exclude_vars = array_merge($super_globals, array(
			'exclude_vars',
			'super_globals',
			'GLOBALS'
		));
		foreach($GLOBALS as $key => $value) {
			if(in_array($key, $exclude_vars)) continue;
			FB::log($value, $key);
		}
		FB::groupEnd();

		FB::group('Functions Available');
		$all_funcs = get_defined_functions();
		$funcs = $all_funcs['user'];
		$exclude_funcs = array('fb');
		foreach($funcs as $f) {
			if(in_array($f, $exclude_funcs)) continue;
			FB::log($f);
		}
		FB::groupEnd();

		FB::group('Superglobal Arrays', array( 'Collapsed' => true ));
		foreach($super_globals as $vname) {
			if(isset($GLOBALS[$vname])) {
				FB::log($GLOBALS[$vname], $vname);
			} else {
				FB::log('-undefined-', $vname);
			}
		}
		FB::groupEnd();

		FB::trace('Stack Trace', array( 'Collapsed' => false ));

		FB::groupEnd();

	}

	/**
	 * Sanitize the data type given as a paramter and try to return a data type that makes most sense
	 * in the current environment and curcumstances.
	 */
	public static function saner_mode($mode) {
		return in_array($mode, array('xml', 'json')) ? $mode : self::$dataformat;
	}

	public static function _on_shutdown() {
		if(Helpers::$db_con !== null) {
			Helpers::$db_con->close();
		}
		if(self::$dump_on_done) {
			Helpers::log_info("Requested Dump on Done", true);
			ob_end_flush();
		}
	}

	public static function no_cache_headers() {

		// Code from PHP Cookbook, 2nd Ed.

		header("Expires: 0");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		header("Cache-Control: no-store, no-cache, must-revalidate");

		// Add some IE-specific options
		header("Cache-Control: post-check=0, pre-check=0", false);

		// For HTTP/1.0
		header("Pragma: no-cache");

	}

	// get ip address
	public static function get_ipaddress() {
		
		$ip = "127.0.0.1";
		
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {	//check ip from share internet
	      $ip = $_SERVER['HTTP_CLIENT_IP'];
	    }
	    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {	//to check ip is pass from proxy
	      $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	    }
	    else {
	      $ip = $_SERVER['REMOTE_ADDR'];
	    }
	
	    return $ip;
	}
	public static function get_browser_info(){
		return $_SERVER['HTTP_USER_AGENT'];
	}
	
	public static function get_fancy_time($time) {

		$delta = time() - Date($time);
		
		if ($delta < 60) {
			return 'less than a minute ago.';
		} else if ($delta < 120) {
		  	return 'about a minute ago.';
		} else if ($delta < (45 * 60)) {
		  	return floor($delta / 60) . ' minutes ago.';
		} else if ($delta < (90 * 60)) {
		  	return 'about an hour ago.';
		} else if ($delta < (24 * 60 * 60)) {
		  	return 'about ' . floor($delta / 3600) . ' hours ago.';
		} else if ($delta < (48 * 60 * 60)) {
		  	return '1 day ago.';
		} else {
		  	return floor($delta / 86400) . ' days ago.';
		}
	}
	public static function template($t_string, $t_array) {
		$i = 1;
		foreach($t_array as $key=>$val){
			$t_string = str_replace('$' . $i, '<b>' . $val . '</b>', $t_string);
			$i++;
		}
		return $t_string;
	}
}

//register_shutdown_function(create_function('', 'Helpers::_on_shutdown();'));


// Setup FirePHP Environment
require_once($inc_path . '/fb.php');

$firephp = FirePHP::getInstance(true);

$firephp->registerErrorHandler(true);
$firephp->registerExceptionHandler();
$firephp->registerAssertionHandler(true, false);
$firephp->setEnabled(false);

unset($firephp);


// Include the Project's database configurations
require_once($inc_path . '/database.php');

/*
// Include Project's configurations (live production settings)
@include_once($inc_path . '/conf.login.php');

// Include labs deployment's configurations

require_once($inc_path . '/conf.php');

// Include local development configurations
*/
@include_once($inc_path . '/conf.loc.php');

unset($inc_path);

if(isset($_SERVER['PATH_INFO']) and ($path_info = $_SERVER['PATH_INFO']) != '') {
	require_once(trim($path_info, '/') . '.php');
	die();
}
