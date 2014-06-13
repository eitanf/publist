<?php
/*
 *  PublistCache: Object to handle offline publist usage
 *  Copyright 2005 Stuart Prescott (publist@nanonanonano.net)
 *
 *  This file is part of the Publist package (publist.sf.net) 
 *  and is distributed under the terms of the GNU General Public License
 */
 
require_once 'publist.php';

/**
* PublistCache class -  a caching publist replacement
*
* PublistCache is a drop-in replacement for Publist that caches generated
* data to improve Publist's performance and reduce server load.
*
* (It's actually not a *replacement* as such, as Publist is still required,
* but the PublistCache object replaces the Publist object in all your pages.)
* 
* PublistCache does this by:
* - acting as a proxy to all of publist's 'public' methods
* - keep a cache directory (.pubcache) into which html snippets are thrown
*   once Publist generates them.
* - when a Publist function is called against PublistCache, a resource name
*   is generated for that request that includes the XML files involved, 
*   the macro filename, the ini filename, which function it was and what 
*   the arguments to that function were
* - if that resource is already in the cache directory, then the cached
*   version is quickly served
* - if the resource is not in the cache directory, then Publist is instantiated
*   and the resource is generated (with a copy saved into the cache)
*
* Note that the Publist object is only instantiated if it's needed so there's 
* no XML parsing and sorting overhead from using Publist. For sanity (and safety)
* PublistCache will call a cache miss and regenerate the content if any of the 
* XML, macro, or ini files are newer than the snippet stored in the cache.
*
* =============================== WARNING ===============================
* PublistCache will check that the requested sort does exist, so it is safe
* to allow user-supplied data for the sort (from $_GET['sort']) as in the 
* example below. However, NO OTHER DATA IS SANITISED, so do not allow
* data from $_GET, $_POST, $_SERVER, $_REQUEST anywhere near PublistCache.
*
* For example, if you were to allow a remote web client to insert data into the
* function call arguments (e.g. you were using a user-supplied search string like
*   $pubs->print_select_authors($_GET['author']);
* or any other user-supplied data), then you would create a potential
* DoS condition: your cache directory could be made to grow uncontrollably
* just by changing the URL string in the request.
* PublistCache is *not* suitable for such search data, use Publist directly.... 
* but why you'd want to cache search data anyway, I don't know. 
* =============================== WARNING ===============================
*
*
* Usage:
* In the instantiating PHP file (e.g. main.php):
* 	// change the 'require' line from publist.php to publistcache.php
* 	require 'publistcache.php'; 
* 	// change the 'new Publist' call to 'new PublistCache'
* 	$pubs = new PublistCache('pubs.xml', $_GET['sort'], 'macros.dat', 'local.ini');
* 	// ... use as you previously used Publist, for example:
* 	$pubs->print_all();
* 
* You can programatically change the cache directory if you want; relative
* paths are relative to the instantiating PHP file (e.g. main.php)
* 	$pubs->cache_dir = '/path/to/my/.cache_dir';
*
* You can force PublistCache to not serve the cached versions (but it 
* will still try to keep generated content in the cache):
* 	$pubs->offline = false;
*
* You can also stop PublistCache writing to the cache:
* 	$pubs->write_cache = false;
*
* You can stop errors writing to the cache from being shown to the user:
* 	$pubs->errors_to_browser = false;
*
*/


class PublistCache {
	var $publist = NULL;            // a PubList object that will do the hard work if needed
	var $offline = true;            // set to false to force cache miss every time
	var $errors_to_browser = true;  // send errors to the browser (change after instantiation)
	var $write_cache = true;        // write the data to disk

	var $sources = array();         // all the source files for this listing
	var $cache_dir = '.pubcache';   // location of the cache (change after instantiation)

	var $filenames;                 // list of XML files to be passed to Publist object
	var $insort;                    // the sort method to be passed to Publist object
	var $macrofn;                   // macros filename to be passed to Publist object
	var $configfn;                  // config filename to be passed to Publist object
	
	// Methods: 

################################################
	///// Constructor, receives the same parameters as the PubList constructor
	// as it is supposed to be a drop-in replacement for Publist.
	// filenames: an array of XML filenames containing pubs data
	// insort: Name of primary sort criterion (can be null)
	// macrofn: Filename of macros for pubs (can be null)
	// configfn: Local configuration .ini filename

	function PublistCache ($filenames, $insort, $macrofn, $configfn="publist.ini") {
		$this->insort    = $insort;
		$this->filenames = $filenames;
		$this->macrofn   = $macrofn;
		$this->configfn  = $configfn;
		
		// create an array of all the source files for the listing
		if (is_array($filenames)) {
			$this->sources = $filenames;
		} else {
			$this->sources[] = $filenames;
		}
		$this->sources[] = $macrofn;
		$this->sources[] = $configfn;
		// set the magic $php_errormsg variable to the last error message (like $! in perl)
		ini_set('track_errors', true); 
	}

################################################
	///// Show a "sort by" links bar 
	function show_sorts () {
		$this->cache_handler('show_sorts');
	}

################################################
	///// Show a "jump to" links bar
	function show_jumps ($teamonly=false) {
		$this->cache_handler('show_jumps', array($teamonly));
	}

################################################
	///// Display team publication list (front for print_all)
	function print_team() {
		$this->print_all(true);
	}

################################################
	///// Display entire publication list
	function print_all ($teamonly=false, $overrides=array()) {
		$this->cache_handler('print_all', array($teamonly, $overrides));
	}

################################################
	///// Display an individual publication given the publication key
	function print_from_key ($key, $overrides=array()) {
		$this->cache_handler('print_from_key', array($key, $overrides));
	}

################################################
	///// Display partial publication list, based on selection criteria (after sorting)
	function print_select ($field, $value) {
		$this->cache_handler('print_select', array($field, $value));
	}

################################################
	///// Display partial publication list, based on regular expression match to the author list 
	function print_select_author ($pattern) {
		$this->cache_handler('print_select', array($pattern));
	}

################################################
	///// Display partial publication list, based on selection criteria (after sorting)
	function print_select_generic ($func) {
		$this->cache_handler('print_select_generic', array($func));
	}

################################################
	///// Reset the citation counter for multiple sections within the one document
	function cites_reset ($refListName) {
		$this->cache_handler('cites_reset', array($refListName));
	}

################################################
	///// cite() receives a comma-separated list of keys, prints out a reference number
	function cite ($cites) {
		$this->cache_handler('cite', array($cites));
	}

################################################
	/////  Print a reference list of all the citations made so far
	function print_refs() {
		$this->cache_handler('print_refs');
	}

################################################
	///// Show a helper file (e.g. abstract( of an individual reference
	function show_file ($key, $fileclass='abstract') {
		// FIXME: could we do this in fact? there's no reason why the XML files have
		// to be parsed in order to fulfill this request, but it would require code
		// duplication from pub.php into here unless there were a way of calling
		// that section of pub.php as a static function
		die ("I can't do that in offline mode... please use Publist not PublistCache");
	}

################################################
	///// use or regenerate the cache for the user
	function cache_handler ($function, $args=array()) {
		$resource = $this->get_resource_name($function, $args);
		if ($this->offline && $this->cache_hit($resource)) {
			$this->show_cached_section($resource);
		} else {
			if ($this->publist === NULL) {
				$this->publist = new Publist($this->filenames, $this->insort, 
					                           $this->macrofn, $this->configfn);
			}
			$this->start_capture_output();
			// this is equivalent to: $this->publist->$function($args[0], $args[1], $args[2], ...)
			call_user_func_array(array($this->publist, $function), $args);
			$this->stop_capture_output($resource);
		}
	}

################################################
	///// look in the cache to see if the resource exists
	function cache_hit ($resource) {
		if (! file_exists($resource)) return false;
		
		$source_age = $this->source_age($this->filenames, $this->macrofn, $this->configfn);
		$cache_age = filemtime($resource);
		return $source_age < $cache_age;
	}

################################################
	///// find out the latest modified source file
	function source_age () {
		$latest = 0;
		foreach ($this->sources as $file) {
			$mtime = @ filemtime($file);
			if ($mtime === false) {
				// the file doesn't exist. Is that an error? pubconfig will silently
				// permit config files to not exist, so let's ignore it.
			} else {
				$latest = $latest < $mtime ? $mtime : $latest;
			}
		}
		return $latest;
	}

################################################
	///// show the cached version of a file
	function show_cached_section ($resource) {
		// echo "Cache hit for $resource\n";
		$status = @ readfile($resource);
		if ($status === false) {
			// Error reading the cached version of the file.
			// Always report this error to the user since there is no way of hiding this problem.
			print "<br />Sorry, I'm having trouble displaying this section.<br />";
			$this->log("Oops. An error occurred showing that section: $resource\n$php_errormsg\n");
		}
	}

################################################
	///// start capturing output from Publist so it can be saved to a file
	function start_capture_output () {
		// use PHP's output buffering to suspend writing to the browser for the meantime
		ob_start();
	}

################################################
	///// show the cached version of a file
	function stop_capture_output ($resource) {
		// get the contents of the output buffer (so it can be saved)
		$output = ob_get_contents();
		// now stop output buffering so the user sees what has been done
		ob_flush();
		// sanitise the data to make sure that the sort actually exists 
		// (NULL or empty is the default sort)
		if ($this->insort !== '' && $this->insort !== NULL &&
			        ! in_array($this->insort, $this->publist->config->get_sort_names())) {
			$this->log("Sort '{$this->insort}' is unknown.");
			return;
		}
		if (! $this->write_cache)  {
			$this->log("Not writing cache as per configuration.");
			return;
		}
		// and then write the data to the disk cache
		//echo "Using resource '$resource'";
		$fh = @ fopen($resource, 'w');
		if ($fh === false) {
			$this->log("Oops. I couldn't open $resource:\n$php_errormsg\n");
			return;
		}
		$statuswrite = @ fwrite($fh, $output);
		if ($statuswrite != strlen($output)) {
			$this->log("Oops. I couldn't write to disk $resource:\n$php_errormsg\n");
			return;
		}
		$statusclose = @ fclose($fh);
		if (! $statusclose) {
			$this->log("Oops. I couldn't close file $resource:\n$php_errormsg\n");
			return;
		}
	}

################################################
	///// calculate a unique name for this resource
	function get_resource_name ($function, $args) {
		// Combine the URL along with the source files (XML, macro, ini) required
		// the sort that is being used and the function that is called (plus
		// any arguments to the function) to create a unique name for theis request.
		// Use control characters to join the arguments together to prevent
		// namespace clashes from similar function arguments.
		$request = array( $_SERVER['SERVER_NAME'], $_SERVER['SCRIPT_NAME'],
			                join("\2",$this->sources), $this->insort,
			                $function);
		foreach ($args as $a) {
			if (is_array($a)) {
				$request[] = join("\2", $a);
			} else {
				$request[] = $a;
			}
		}
		// use the md5 of the above information to construct a hopefully unique filename
		$filename = md5(join("\1", $request));
		//error_log(join("\1", $request)." => $filename");
		return $this->cache_dir.DIRECTORY_SEPARATOR.$filename;
	}
	
################################################
	///// log errors to web-server's error log and optionally to the browser
	function log ($message) {
		// send message to web-server's error_log
		error_log($message);
		// and optionally to the browser as well
		if ($this->errors_to_browser) {
			print "<div class='error'>".preg_replace("@\n@", "<br />", $message)."</div>";
		}
	}

}

?>
