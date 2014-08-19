<?php
/**
 * @In the name of God!
 * @author: Apadana Development Team
 * @email: info@apadanacms.ir
 * @link: http://www.apadanacms.ir
 * @license: http://www.gnu.org/licenses/
 * @copyright: Copyright © 2012-2014 ApadanaCms.ir. All rights reserved.
 * @Apadana CMS is a Free Software
**/

defined('security') or exit('Direct Access to this location is not allowed.');

/**
 @author Nguyen Quoc Bao <quocbao.coder@gmail.com>
 @some_improvements Apadana Development Team <info@apadanacms.ir>
 @version 1.5
 @desc A simple object for processing download operation , support section downloading
 Please send me an email if you find some bug or it doesn't work with download manager.
 I've tested it with
 	- Reget
 	- FDM
 	- FlashGet
 	- GetRight
 	- DAP

 @copyright It's free as long as you keep this header .
 @example

 1: File Download
 	$object = new downloader;
 	$object->set_byfile($filename); //Download from a file
 	$object->use_resume = true; //Enable Resume Mode
 	$object->download(); //Download File

 2: Data Download
  $object = new downloader;
 	$object->set_bydata($data); //Download from php data
 	$object->use_resume = true; //Enable Resume Mode
 	$object->set_filename($filename); //Set download name
 	$object->set_mime($mime); //File MIME (Default: application/otect-stream)
 	$object->download(); //Download File

 	3: Manual Download
 	$object = new downloader;
 	$object->set_filename($filename);
	$object->download_ex($size);
	//output your data here , remember to use $this->seek_start and $this->seek_end value :)

**/

class httpdownload {

	var $data = null;
	var $data_len = 0;
	var $data_mod = 0;
	var $data_type = 0;
	var $protocol = null;
	/**
	 * @var ObjectHandler
	 **/
	var $handler = array('auth' => null);
	var $use_resume = true;
	var $use_autoexit = false;
	var $use_auth = false;
	var $filename = null;
	var $mime = null;
	var $bufsize = 2048;
	var $seek_start = 0;
	var $seek_end = -1;

	/**
	 * Total bandwidth has been used for this download
	 * @var int
	 */
	var $bandwidth = 0;
	/**
	 * Speed limit
	 * @var float
	 */
	var $speed = 0;

	/*-------------------
	| Download Function |
	-------------------*/
	/**
	 * Check authentication and get seek position
	 * @return bool
	 **/
	function initialize() {
		global $HTTP_SERVER_VARS;
		$this->protocol = isset($_SERVER['SERVER_PROTOCOL']) && !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : "HTTP/1.1";
		
		($hook = get_hook('download_init_start'))? eval($hook) : null;
		
		if ($this->use_auth) //use authentication
		{
			if (!$this->_auth()) //no authentication
			{
				header('WWW-Authenticate: Basic realm="Please enter your username and password"');
				header($this->protocol . ' 401 Unauthorized');
				header('status: 401 Unauthorized');
				if ($this->use_autoexit){
					exit();
				}
				else{
					define('no_headers',true);
					define('no_template',true);
				}
			}
			($hook = get_hook('download_use_auth'))? eval($hook) : null;
		}
		if ($this->mime == null) $this->mime = "application/force-download"; //default mime

		if (isset($_SERVER['HTTP_RANGE']) || isset($HTTP_SERVER_VARS['HTTP_RANGE']))
		{

			if (isset($HTTP_SERVER_VARS['HTTP_RANGE'])) $seek_range = substr($HTTP_SERVER_VARS['HTTP_RANGE'] , strlen('bytes='));
			else $seek_range = substr($_SERVER['HTTP_RANGE'] , strlen('bytes='));

			$range = explode('-',$seek_range);

			if ($range[0] > 0)
			{
				$this->seek_start = intval($range[0]);
			}

			if ($range[1] > 0) $this->seek_end = intval($range[1]);
			else $this->seek_end = -1;

			if (!$this->use_resume)
				$this->seek_start = 0;
		}
		else
		{
			$this->seek_start = 0;
			$this->seek_end = -1;
		}
		($hook = get_hook('download_init_end'))? eval($hook) : null;
		return true;
	}
	/**
	 * Send download information header
	 **/
	function header($size,$seek_start=null,$seek_end=null) {
		
		if ($this->use_resume)
		{
			header( $this->protocol . " 206 Partial Content" );
			header("Status: 206 Partial Content");
		}
		else{
			header( $this->protocol . " 200 OK" );
			header("Status: 200 OK");
		}

		header( "Pragma: public" );
		header( "Expires: 0" );
		header( "Cache-Control: must-revalidate, post-check=0, pre-check=0"); 
		header( "Cache-Control: private", false);
		header('Content-type: ' . $this->mime);
		header('Content-Disposition: attachment; filename="' . $this->filename . '"');
		header( "Content-Transfer-Encoding: binary" );
		header('Last-Modified: ' . (gmdate('D, d M Y H:i:s').' GMT') , $this->data_mod);
		
		if ($this->use_resume)
		{
			header('Accept-Ranges: bytes');
			header("Content-Range: bytes $seek_start-$seek_end/$size");
			header("Content-Length: " . ($seek_end - $seek_start + 1));
		}
		else
		{
			header("Content-Length: $size");
		}
		($hook = get_hook('download_header'))? eval($hook) : null;
	}

	function download_ex($size)
	{
		if (!$this->initialize()) return false;
		ignore_user_abort(true);
		//Use seek end here
		if ($this->seek_start > ($size - 1)) $this->seek_start = 0;
		if ($this->seek_end <= 0) $this->seek_end = $size - 1;
		$this->data_mod = time();
		($hook = get_hook('download_ex'))? eval($hook) : null;
		$this->header($size,$this->seek_start,$this->seek_end);
		return true;
	}

	/**
	 * Start download
	 * @return bool
	 **/
	function download() {
		if (!$this->initialize()) return false;

		$seek = $this->seek_start;
		$speed = $this->speed;
		$bufsize = $this->bufsize;
		$packet = 1;

		//do some clean up
		if (ob_get_length() !== FALSE){
			@ob_end_clean();
		}
		$old_status = ignore_user_abort(true);
		if (!ini_get('safe_mode')) {
			@set_time_limit(0);
		}
		$this->bandwidth = 0;

		$size = $this->data_len;
		($hook = get_hook('download_start'))? eval($hook) : null;
		if ($this->data_type == 0) //download from a file
		{

			$size = filesize($this->data);
			if ($seek > ($size - 1)) $seek = 0;
			if ($this->filename == null) $this->filename = basename($this->data);

			$res = fopen($this->data,'rb');
			if ($seek) fseek($res , $seek);
			if ($this->seek_end < $seek) $this->seek_end = $size - 1;

			$this->header($size,$seek,$this->seek_end); //always use the last seek
			$size = $this->seek_end - $seek + 1;

			while (!(connection_aborted() || connection_status() == 1) && $size > 0)
			{
				if ($size < $bufsize)
				{
					echo fread($res , $size);
					$this->bandwidth += $size;
				}
				else
				{
					echo fread($res , $bufsize);
					$this->bandwidth += $bufsize;
				}

				$size -= $bufsize;
				flush();

				if ($speed > 0 && ($this->bandwidth > $speed*$packet*1024))
				{
					sleep(1);
					$packet++;
				}
			}
			fclose($res);

		}
		elseif ($this->data_type == 1) //download from a string
		{
			if ($seek > ($size - 1)) $seek = 0;
			if ($this->seek_end < $seek) $this->seek_end = $this->data_len - 1;
			$this->data = substr($this->data , $seek , $this->seek_end - $seek + 1);
			if ($this->filename == null) $this->filename = time();
			$size = strlen($this->data);
			$this->header($this->data_len,$seek,$this->seek_end);
			while (!connection_aborted() && $size > 0) {
				if ($size < $bufsize)
				{
					$this->bandwidth += $size;
				}
				else
				{
					$this->bandwidth += $bufsize;
				}

				echo substr($this->data , 0 , $bufsize);
				$this->data = substr($this->data , $bufsize);

				$size -= $bufsize;
				flush();

				if ($speed > 0 && ($this->bandwidth > $speed*$packet*1024))
				{
					sleep(1);
					$packet++;
				}
			}
		}
		elseif($this->data_type == 2)
		{
			//just send a redirect header
			header('location: ' . $this->data);
		}
		($hook = get_hook('download_end'))? eval($hook) : null;
		if ($this->use_autoexit)
			exit();
		else
			define('no_headers',true);

		//restore old status
		ignore_user_abort($old_status);

		if (!ini_get('safe_mode')) {
			@set_time_limit(ini_get("max_execution_time"));
		}

		return true;
	}

	function set_byfile($dir) {
		if (is_readable($dir) && is_file($dir)) {
			$this->data_len = 0;
			$this->data = $dir;
			$this->data_type = 0;
			$this->data_mod = filemtime($dir);
			return true;
		} else return false;
	}

	function set_bydata($data) {
		if ($data == '') return false;
		$this->data = $data;
		$this->data_len = strlen($data);
		$this->data_type = 1;
		$this->data_mod = time();
		return true;
	}

	function set_byurl($data) {
		$this->data = $data;
		$this->data_len = 0;
		$this->data_type = 2;
		return true;
	}

	function set_filename($filename) {
		$this->filename = $filename;
	}

	function set_mime($mime) {
		$this->mime = $mime;
	}

	function set_lastmodtime($time) {
		$time = intval($time);
		if ($time <= 0) $time = time();
		$this->data_mod = $time;
	}

	/**
	 * Check authentication
	 * @return bool
	 **/
	function _auth() {
		if (!isset($_SERVER['PHP_AUTH_USER'])) return false;
		if (isset($this->handler['auth']) && function_exists($this->handler['auth']))
		{
			$auth = $this->handler['auth']('auth' , $_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']);
			($hook = get_hook('download_auth'))? eval($hook) : null;
			return $auth;
		}
		else return true; //you must use a handler
	}
}

?>