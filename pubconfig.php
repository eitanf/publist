<?php
/*
 *  PubConfig: Object for Publist's configuration management
 *  Copyright 2005--2014 by Eitan Frachtenberg (publist@frachtenberg.org)
 *  This program is distributed under the terms of the GNU General Public License
 */

require_once 'pubtype.php';

// Class PubConfig is responsible for reading, storing, manipulating, and
// retrieving the configuration parameters for publist, as read from the
// appropriate .ini files. It also stores a static array of all pub types.

class PubConfig {
	var $types = array();	        // All publication types
	var $type_names = "";	        // A concatenated string of all type names
	var $options = array();	        // Associative array with all .ini parameters
	var $sort_names = array();	// List of recognized sort names
	var $sort_indexes = array();	// Array of string indexes into $this->sorts
	
	var $formatting = array(
                                        'itemstart'     => '<li>',
                                        'itemstop'      => '</li>',
                                        'linkstart'     => '<div class="publinks">',
                                        'linkstop'      => '</div>',
                                        'linkseparator' => "&nbsp;&nbsp;&nbsp;\n"
                                );

	var $files = array();	        // Hash of recognized file types
	// Convert month number to name:
	var $months = array('1' => "January", "February", "March", "April",
		"May", "June", "July", "August", "September", "October",
		"November", "December");

	// Methods: 

################################################
	///// PubConfig constructor with filename to read configuration data
	// First, tries to find a "global" publist.ini file in the same dir
	// where the source files are places (i.e., pubconfig.php).
	// After reading that file, successfully or not, it tries to read
	// a local file, as passed by the parameter PubConfig

	function PubConfig ($filename) {
		$got_ini = false;

		// Try to read global .ini file
		#$globfn = preg_replace ("@(.*)/.*$@", "$1/publist.ini", __FILE__);
		$globfn = dirname (__FILE__) . DIRECTORY_SEPARATOR . "publist.ini";
		if (isset ($globfn) && is_readable ($globfn)) {
			$this->options = parse_ini_file ($globfn, true);
			$got_ini = true;
		}
		
		// Read local filename, if possible, and add to $this->options:
		if (isset ($filename) && is_readable ($filename)) {
			$tmp = parse_ini_file ($filename, true);
			foreach ($tmp as $section_name => $section) {
				if (isset ($this->options[$section_name])) {
					$this->options[$section_name] = 
						array_merge ($this->options[$section_name], $tmp[$section_name]);
				} else {
					$this->options[$section_name] = $tmp[$section_name];
				}
			}
			$got_ini = true;
		} 

		if (!$got_ini)
			die ("Critical error: cannot find any readable configuration file in $globfn or $filename\n");

		$this->parse_types ();
		$this->parse_sorts ();
		$this->parse_files ();
		$this->parse_formatting ();
		$this->parse_months ();
	}

################################################
	///// parse_types: Parse publication types from .ini into $types array
	function parse_types () {
		if (isset($this->options['Meta_Types']['order']) && ! empty($this->options['Meta_Types']['order'])) {
			$types = preg_split ('/\s+/', $this->options['Meta_Types']['order'], -1, PREG_SPLIT_NO_EMPTY);
		} else {
			$types = preg_grep ('/^Type_.+/', array_keys ($this->options));
			$types = preg_replace ('/^Type_(.+)/', '$1', $types);
		}
		if (! count($types))
			die ("Critical error: no types defined in configuration file\n");

		foreach ($types as $type) {
			$ltype = "Type_$type";
			if (strstr ($this->type_names, $type)) {
				echo "Warning: Type $type is repeated!\n";
			}
			$this->type_names .= "$type ";

			//	Create PubType
			$other = "";
			$pdata =& $this->options[$ltype];

			// 	Deal with globbed types first
			if (isset ($pdata["glob_with"])) {
				$other = $pdata["glob_with"];
				if (!isset ($this->types[$other])) {
					echo ("Cannot find type $other before type $type. ");
					echo ("Types found so far are: \n");
					print_r ($this->types);
					die ();
				}
				if (isset ($pdata["priority"]) && $pdata["priority"] != $this->types[$other]->priority)
					die ("Priority of type $type (" . $pdata["priority"]
					. ") is different than that of globbed type $other (" . $this->types[$other]->priority . ")");
				$header = $this->types[$other]->header;
				$priority = $this->types[$other]->priority;
			}
			else {	// Unglobbed types:
				if (!isset ($pdata["header"]))
					die ("Type $type needs a header\n");
				else
					$header = $pdata["header"];

				if (!isset ($pdata["priority"]))
					die ("Type $type needs a header\n");
				else
					$priority = $pdata["priority"];
			}

			if (!isset ($pdata["document"]))
				$document = $this->get ("Content", "document");
			else
				$document = $pdata["document"];

			if (!isset ($pdata["slides"]))
				$slides = $this->get("Content", "slides");
			else
				$slides = $pdata["slides"];

			if (!isset ($pdata["bibtex"]))
				$bibtex = "";
			else
				$bibtex = $pdata["bibtex"];

			if (!isset ($pdata["format"]))
				$format = "";
			else
				$format = $pdata["format"];

			$this->types[$type] = new PubType ($type, $header, $priority, 
				$other, $document, $slides, $format, $bibtex);
		}
	}

################################################
	///// parse_sorts: Parse sort options from .ini (in $options).
	// Also parses the sort-field order and whether they're ascending or descending.
	// Tries to check that all required data is available and sane.
	// Note: currently doesn't check for duplicates, which isn't a bug.

	function parse_sorts () {
		$sorts = preg_split ('/\s+/', $this->options['Meta_Sorts']['order']);
		if (!$sorts)
			die ("Critical error: no sorts defined in configuration file\n");
		else {
			$this->sort_names = array();
		}

		foreach ($sorts as $sort) {
			$this->sort_names[] = $sort;
			$lsort = "Sort_" . $sort;
			$sdata =& $this->options[$lsort];

			// Sanity checks:
			if ($sdata["name"] == "")
				die ("Sort $sort doesn't have a name field\n");
			if ($sdata["field_order"] == "")
				die ("Sort $sort doesn't have a field_order string\n");

			// Figure out types:
			if (!isset ($sdata["types"]) || preg_match ('/\*/', $sdata["types"])) { // All types:
				$types = $this->type_names;

			} elseif (preg_match ('/^\-/', $sdata["types"])) {	// Exclude list
				$types = $this->type_names;	// Add everything first
				$excludes = (preg_replace ('/^\-/', '', $sdata["types"])); // Optional +
				foreach ((explode (" ", $excludes)) as $ex) 
					$types = preg_replace ("@$ex@", "", $types);	// Remove excludes

			} else {		// Include list
				$types = (preg_replace ('/^\+/', '', $sdata["types"])); // Optional +
			}

			$this->options[$lsort]["types"] = $types;

			// Format fields string into array and find out sort order for each field:
			$fields = array();
			$ascending = array();
			$field_order = preg_split ('@\s@', $sdata["field_order"]);
			foreach ($field_order as $lfield) {
				$field = preg_replace ('@\W@', "", $lfield);
				assert ($field != '');
				$fields[] = $field;
				$ascending[$field] = (substr ($lfield, 0, 1) == '-')? false : true;
			}
			$this->options[$lsort]["fields"] = $fields;
			$this->options[$lsort]["ascending"] = $ascending;
			$this->options[$lsort]["primary"] = $fields[0];
		}
	}
			
################################################
	///// parse_formatting: Store HTML formatting info from .ini file in memory
	function parse_formatting () {
		$format = $this->options["Formatting"];
		foreach ($format as $key => $f) {
			$this->formatting[$key] = $f;
		}
	}

################################################
	///// parse_files: look up the file types that we have stored
	function parse_files () {
		if (! isset($this->options['Meta_Files']['order'])) {
			return;
		}
		foreach (preg_split('/\s+/', $this->options['Meta_Files']['order']) as $f) {
			if (isset($this->options["Files_$f"]) && is_array($this->options["Files_$f"])) {
				$this->files[$f] = $this->options["Files_$f"];
			}
		}
	}

################################################
	///// parse_months: If config file contains month names, replace
	// defaults with file data.
	function parse_months () {
		if (!isset ($this->options["Content"]["months"]) 
		    || $this->options["Content"]["months"] == "")
			return;

		$tmp = explode (" ", $this->options["Content"]["months"]);
		if (count ($tmp) != 12) 
			die ("Need 12 month names, and instead I found" . count ($tmp));

		for ($i = 1; $i <= 12; $i++)
			$this->months[$i] = array_shift ($tmp);
	}



//////////////////////////////// Query functions:

################################################
	///// get: Generic access function. Receives string parameter
	// of form "section.property", and returns the value associated
	// with that property in the given section.
	// If value is not defined, will return whatever is passed along with $default.
	// Direct external usage when a more specific function exists is discouraged.

	function get ($section, $property, $default=NULL) {
		return (isset ($this->options[$section][$property])? 
			$this->options[$section][$property] : $default);
	}

################################################
	///// get_type: Return a class PubType for a type name
	function get_type ($type) {
		if (isset ($this->types[$type]))
			return $this->types[$type];
		else
			die ("Type $type not found in configuration data");
	}

################################################
	///// get_sort_names: return array of sort names (indexes)
	function get_sort_names () {
		return $this->sort_names;
	}

################################################
	///// get_sort: Return assoc. array of data about a sort options
	function get_sort ($sort) {
		return $this->options["Sort_" . $sort];
	}

################################################
	///// get_formatting: Return assoc. array of formatting info
	function get_formatting ($overrides=array()) {
		return array_merge($this->formatting, $overrides);
	}

################################################
	///// list of known pload directories for files
	function get_files () {
		return array_keys($this->files);
	}

################################################
	///// names for uploaded files
	function get_file_name ($moniker) {
		return $this->get ("Files_$moniker", 'name', $moniker);
	}

################################################
	///// directories for uploaded files
	function get_file_dir ($moniker) {
		return $this->get ("Files_$moniker", 'directory', $moniker);
	}

################################################
	///// additional helpers for abstracts etc
	function get_file_helper ($moniker, $filename) {
		$helper = $this->get ("Files_$moniker", 'helper_url', 0);
		if ($helper && preg_match('@\.(.+?)$@', $filename, $ext)) {
			return preg_match("@\b$ext[1]\b@", $this->get ("Files_$moniker", 'helper_ext', 0))
				     ? $helper : '';
		} else {
			return '';
		}
	}

################################################
	///// type_of_file: Return the text of a file type by extension
	function get_file_type ($ext) {
		return $this->get ("File_extensions", strtolower ($ext));
	}

################################################
	///// boolean query function to check if this link bar item is actually from the XML file
	function is_file_xml_link ($moniker) {
		return (strpos($this->get ("Files_$moniker", 'directory', $moniker), 'xml:') === 0);
	}

################################################
	///// boolean query function to check if the hypertext link is actually from the XML file
	function is_file_xml_text ($moniker) {
		return (strpos($this->get ("Files_$moniker", 'name', $moniker), 'xml:') === 0);
	}

################################################
	///// which XML field is used to populate the link url
	function get_file_xml_link ($moniker) {
		return (substr($this->get ("Files_$moniker", 'directory', $moniker), 4));
	}

################################################
	///// which XML field is used to populate the hyperlink text
	function get_file_xml_text ($moniker) {
		return (substr($this->get ("Files_$moniker", 'name', $moniker), 4));
	}

################################################
	///// which XML field is used to populate the hyperlink text
	function get_file_xml_link_format ($moniker) {
		return $this->get ("Files_$moniker", 'link', '%s');
	}

################################################
	///// get_month_name: Return string name of a month number
	function get_month_name ($month) {
		if (!is_numeric ($month) || ($month < 1) || ($month > 12))
			die ("Month $month needs to be number between 1--12\n");
 
		return $this->months[$month];
	}
}

?>
