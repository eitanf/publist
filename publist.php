<?php
/*
 *  Publist: Object for list of publications
 *  Copyright 2003--2014 by Eitan Frachtenberg (publist@frachtenberg.org)
 *  This program is distributed under the terms of the GNU General Public License
 */

require_once 'pub.php';
require_once 'pubconfig.php';

// Publist class stores an array of Publication-class elements, as well
// as presentation supplementary information (configuration, citations, etc.)

define('PUBLIST_VERSION', '2.0');       // publist version

class Publist {
    var $pubs = array();   	// Publication list
    var $pubs_lut;          // lookup table for the sorted publications list
    var $sort = array();	// Sort options
    var $config = 0;	// Configuration data (PubConfig)
    var $macros = array();	// Macro replacements
    var $baseurl = "";	// URL to prepend to all sort links

    var $citer  = 0;   	// Citation counter
    var $cites = array();	// Citation references (keys)
    var $reflistname = '';  // prepended to anchors for links from citation to reference list

    var $_firstlist = true;  // boolean: is this the first list of pubs to be printed

    // Methods:

    ################################################
	///// Constructor, receives the following parameters:
	// filenames: an array of XML filenames containing pubs data
	// insort: Name of primary sort criterion (can be null)
	// macrofn: Filename of macros for pubs (can be null)
	// configfn: Local configuration .ini filename

	function Publist ($filenames, $insort, $macrofn, $configfn="publist.ini", $baseurl = "") {
	$this->read_macros ($macrofn);
	$this->config = new PubConfig ($configfn);
	$this->pubs = $this->read_from_files ($filenames);
	$this->citer = 0;
	if (($sortname = $insort) == "" || $insort == 'unsorted') {
	    $names = $this->config->get_sort_names();
	    $sortname = $names[0];
	}
	$this->sort = $this->config->get_sort ($sortname);
	if ($insort != 'unsorted') {
	    $this->sort_all();
	}
	$this->baseurl = $baseurl;
    }

    ################################################
	///// Parse a publication-fields array into Publication object:
	function parse_pub ($pvalues) {
	for ($i = 0; $i < count ($pvalues); $i++) {
	    $value = isset ($pvalues[$i]['value'])? $pvalues[$i]['value'] : '';
	    if ($value)
		$pub[$pvalues[$i]["tag"]] = trim ($value);
	}
	return new Publication($pub, $this->config);
    }

    ################################################
	///// Read macros from macro file:
	// Macro file should have two entries per line, separated by one tab
	function read_macros ($filename) {
	$fin = @fopen ($filename, "r");
	if (!$fin)
	    return;
	while (($buf = fgetcsv ($fin, 500, "\t"))) {
	    $macro = "@\b$buf[0]\b@";
	    if (isset ($buf[1])) {
		$this->macros[$macro] = $buf[1];
	    }
	}
	fclose ($fin);
    }

    ################################################
	///// Read XML file(s) of publications (receives array of filenames):
	function read_from_files ($filenames) {
	$tdb = array();
	if (! is_array($filenames)) {
	    $filenames = array($filenames);
	}
	foreach ($filenames as $key => $fn) {
	    $data = implode ("", file($fn));
	    // Expand macros:
	    $data = @preg_replace (array_keys($this->macros), array_values($this->macros), $data);

	    // Read all data into $values and $tags
	    clearstatcache();
	    $parser = xml_parser_create();
	    xml_parser_set_option ($parser,XML_OPTION_CASE_FOLDING,0);
	    xml_parser_set_option ($parser,XML_OPTION_SKIP_WHITE,1);
	    xml_parse_into_struct ($parser,$data,$values,$tags);
	    xml_parser_free ($parser);

	    // Loop and fill Publication objects:
	    foreach ($tags as $key=>$val) {
		if ($key == "publication") {
		    $ranges = $val;
		    for ($i=0; $i < count($ranges); $i+=2) {
			$offset = $ranges[$i] + 1;
			$len = $ranges[$i + 1] - $offset;
			$p = $this->parse_pub (array_slice ($values, $offset, $len));

			if (!$p->key)		// Verify key is defined
			    die ("Publication with title '" . $p->data['title'] . "' has no key!\n");
			elseif (isset ($tdb[$p->key])) // Check for repeated keys
			    die ("Key '" . $p->key . "' is repeated!\n");
			else
			    $tdb[$p->key] = $p;
		    }

		} else {	// Not a publication
		    continue;
		}
	    }
	}
	return $tdb;
    }

    ################################################
	///// Show a "sort by" links bar
	// Uses PubConfig's sort data and formatting data
	function show_sorts () {
	$sorts = $this->config->get_sort_names();
	$start = $this->config->get ("Formatting", "barstart");
	$stop  = $this->config->get ("Formatting", "barstop");
	$sep   = $this->config->get ("Formatting", "barseparator", "&nbsp;&nbsp;");
	$desc  = $this->config->get ("Formatting", "sort_description");

	$links = array();   // store all links in an array and then join the array using the separator
	foreach ($sorts as $sort) {
	    $tmp = $this->config->get_sort ($sort);
	    $name = $tmp["name"];
	    $url = '<a href = "';
	    $url .= ($this->baseurl == "")?
		$_SERVER['PHP_SELF'] . '?sort=' . $sort . '">' :
		$this->baseurl .  '-' . $sort . '.html">';
	    $links[] = $url . "$name</a>";
	}
	print '<div class="publistsorts">'. $start . $desc . join($sep, $links) . $stop . "</div>\n";
    }

    ################################################
	///// Show a "jump to" links bar
	// The 'teamonly' boolean argument indicates whether only team==true
	// publications should be included

	function show_jumps ($teamonly=false) {
	// backwards compatibilty to allow string 'false' as well as boolean false
	$team = (is_string ($teamonly))? (strtolower ($teamonly) == 'true') : $teamonly;
	$count = array();
	$start = $this->config->get ("Formatting", "barstart");
	$stop  = $this->config->get ("Formatting", "barstop");
	$sep   = $this->config->get ("Formatting", "barseparator", "&nbsp;&nbsp;");
	$desc  = $this->config->get ("Formatting", "jump_description");

	$links = array();   // store all links in an array and then join the array using the separator
	foreach ($this->pubs as $p) {
	    if (is_a ($p, "publication") && $this->is_visible ($p, $teamonly)) {
		$header = $p->get_header ($this->sort["primary"]);
		$idx = preg_replace ('@\W@', '_', $header);        //replace non-word characters for legal xhtml

		if (!isset ($count[$idx])) {
		    $count[$idx] = 0;
		    $links[] = '<a href="#publist' . $idx . '">'
			. (isset ($header)? $header : $idx)
			. "</a>";
		    $count[$idx]++;
		}
	    }
	}
	if (count($links)) {
	    print '<div class="publistjumps">'. $start . $desc . join($sep, $links) . $stop . "</div>\n";
	}
    }

    ################################################
	///// Print a header (category) to the following publications (one or more).
	// Checks whether header changed from last time before printing.
	// Returns currently used header.

	function print_header ($p, $last) {
	$header = $p->get_header ($this->sort["primary"]);
	if ($last != $header) {             // changed sort so print the next header
	    $start     = $this->config->get ("Formatting", "headerstart", "<h2>");
	    $stop      = $this->config->get ("Formatting", "headerstop", "</h2>");
	    $liststart = $this->config->get ("Formatting", "liststart", "<ul>");
	    $liststop  = $this->config->get ("Formatting", "liststop", "</ul>");

	    if (! $this->_firstlist) print $liststop;
	    print "\n\n<!-- ============== $header ============== -->\n"; # To prettify HTML
									      //replace non-word characters for legal xhtml and create the actual link from the format
									      print sprintf($start, preg_replace('@\W@', '_', $header))
									      . $header
									      . "$stop\n";
	    print $liststart;
	    $last = $header;
	}
	$this->_firstlist = false;
	return $last;
    }

    ################################################
	///// Display team publication list (front for print_all)
	function print_team() {
	$this->print_all(true);
    }

    ################################################
	///// Display entire publication list
	// teamonly determines if only team==true publications are displayed
	function print_all ($teamonly=false, $overrides=array()) {
	$last_header = "";
	foreach ($this->pubs as $p) {
	    if (is_a ($p, "publication") && $this->is_visible ($p, $teamonly)) {
		$last_header = $this->print_header ($p, $last_header);
		$p->print_pub ($this->config, $overrides);
	    }
	}
	print $this->config->get ("Formatting", "liststop", "</ul>");
	$this->link_home();
    }

    ################################################
	///// Determine whether a publication is visible with the current
	// sort and team settings. Returns boolean (true==visible)
	// teamonly determines if only team==true publications are displayed

	function is_visible ($p, $teamonly) {
	if ($teamonly && !$p->is_team ())
	    return false;
	$type = $p->type->get_name();
	return (preg_match ("/\b$type\b/", $this->sort["types"]));
    }

    ################################################
	///// Include name of program, version, and link home
	// Enabled by configuration paramter: [Content][show_version]
	function link_home() {
	if (!$this->config->get ("Content", "show_version", true))
	    return;

	print "<div class='publistcredit'><small>"
	    ."List generated automatically with "
	    .$this->get_link_home()
	    ."</small></div>\n";
    }

    ################################################
	///// Actually print the credits
	function get_link_home() {
	return  '<small><a class="link_home" href="http://publist.sf.net/">Publist</a> '
	    .'v. <a href="http://sf.net/projects/publist/">'. PUBLIST_VERSION ."</a></small>\n"
	    ."<!-- Generated automatically with Publist (c) 2003-2014 version "
	    .PUBLIST_VERSION.", by Eitan Frachtenberg (publist@frachtenberg.org) -->\n";
    }

    ################################################
	///// Display an individual publication given the publication key
	// No headers are used (so print_header not called).
	// $key         string   publn key in XML file
	// $overrides   array    (optional) programatically change config (for admin classes)
	function print_from_key ($key, $overrides=array()) {
	print $this->config->get ("Formatting", "liststart", "<ul>");
	$this->make_lut();
	$this->pubs[$this->pubs_lut[$key]]->print_pub($this->config, $overrides);
	print $this->config->get ("Formatting", "liststop", "</ul>");
    }

    ################################################
	///// Display partial publication list, based on selection criteria (after sorting)
	// Note dependency on sort type for is_visible: initialize Publist accordingly!
	// Additionally, no headers are used (so print_header not called).
	function print_select ($field, $value, $print_headers=false, $overrides=array()) {
	if (! $print_headers) print $this->config->get ("Formatting", "liststart", "<ul>");
	$last_header = "";
	foreach ($this->pubs as $p) {
	    if (is_a ($p, "publication") && $p->is_match ($value, $field) && $this->is_visible ($p, false)) {
		if ($print_headers) $last_header = $this->print_header ($p, $last_header);
		$p->print_pub ($this->config, $overrides);
	    }
	}
	print $this->config->get ("Formatting", "liststop", "</ul>");
    }

    ################################################
	///// Display partial publication list, based on regular expression match to the author list
	// Note dependency on sort type for is_visible: initialize Publist accordingly!
	// Additionally, no headers are used (so print_header not called)
	function print_select_author ($pattern) {
	print $this->config->get ("Formatting", "liststart", "<ul>");
	foreach ($this->pubs as $p) {
	    if (is_a ($p, "publication") &&
		$this->is_visible ($p, false) &&
		$p->is_author ($pattern)) {
		$p->print_pub ($this->config);
	    }
	}
	print $this->config->get ("Formatting", "liststop", "</ul>");
    }

    ################################################
	///// Display partial publication list, based on selection criteria (after sorting)
	// Note dependency on sort type for is_visible: initialize Publist accordingly!
	// Additionally, no headers are used (so print_header not called)

	function print_select_generic ($func) {
	print $this->config->get ("Formatting", "liststart", "<ul>");
	foreach ($this->pubs as $p) {
	    if (is_a ($p, "publication") && $this->is_visible ($p, false) && $func($p)) {
		$p->print_pub ($this->config);
	    }
	}
	print $this->config->get ("Formatting", "liststop", "</ul>");
    }

    ################################################
	///// Reset the citation counter for multiple sections within the one document
	// $refListName is the name to prepend to the links between the citation and the reference list
	function cites_reset ($refListName) {
	$this->citer  = 0;
	$this->cites = array();
	$this->_firstlist = true;
	$this->reflistname = $refListName;
    }

    ################################################
	///// cite() receives a comma-separated list of keys, prints out a reference number
	// for each key, and stores the information about citations for print_refs
	function cite ($cites) {
	$keys = preg_split ('/[ ,]/', $cites);
	$numbers = array();
	foreach ($keys as $k) {
	    if (!$k) 	continue;
	    if (!array_key_exists ($k, $this->cites))
		$this->cites[$k] = ++$this->citer;
	    $numbers[] = $this->cites[$k];
	}
	sort($numbers);
	print '[';
	for($i=0; $i<count($numbers); $i++) {
	    print '<a href="#ref'.$this->reflistname.$numbers[$i].'">'.$numbers[$i].'</a>';
	    if ($i+1 < count($numbers)) print ', ';
	}
	print ']';
    }

    ################################################
	/////  Print a reference list of all the citations made so far
	// Note: It is the responsibility of the caller to enter the proper list
	// environment (typically <ol>)
	// This function does not use "visible" criteria like most other print_* functions
	// Additionally, no headers are used (so print_header not called)

	function print_refs() {
	$this->make_lut();
	$start = $this->config->get ("Formatting", "citeliststart", "<ol>");
	$stop  = $this->config->get ("Formatting", "citeliststop", "</ol>");
	print $start;
	foreach ($this->cites as $k=>$n) {
	    $linkid = array('linkid' => $this->reflistname.$n);
	    $this->pubs[$this->pubs_lut[$k]]->print_pub($this->config, $linkid);
	}
	print $stop;
	$this->link_home();
    }

    ################################################
	///// Show a helper file (e.g. abstract) of an individual reference
	// $key       string       BibTeX/XML key of the publication to be used
	// $fileclass string       class of associated file (from ini [Files_*] sections)
	//                         to be shown
	function show_file ($key, $fileclass='abstract') {
	$this->make_lut();
	$this->pubs[$this->pubs_lut[$key]]->print_file($fileclass, $this->config);
    }

    ################################################
	///// Sort publication list according to sort criterion
	function sort_all () {
	$this->pubs_lut = NULL;
	usort ($this->pubs, array ($this,"compare_pubs"));
    }

    ################################################
	///// Make a look-up-table for the publications to map key to pubs array idx
	function make_lut() {
	if (is_array($this->pubs_lut))
	    return;
	$this->pubs_lut = array();
	foreach ($this->pubs as $idx => $p) {
	    $this->pubs_lut[$p->key] = $idx;
	}
    }

    ################################################
	//// compare_pubs: top-level comparison function
	//   Receives two publications to compare, and tries using
	//   any of the specific functions for known fields,
	//   or the generic cmp for non-specific field comparisons.
	//   Returns negative if $a<$b, positive if $a>$b, zero when $a=$b.
	//   Loops over fields to sort by, determining for each one
	//   whether it's ascending or descending, but return the first
	//   field for which a comparison results in nonzero.

	function compare_pubs ($a, $b) {
	$fields = $this->sort["fields"];
	$ascending = $this->sort["ascending"];

	foreach ($fields as $field) {
	    $reverse = ($ascending[$field])? 1 : -1;
	    switch ($field) {
	    case "date":
		$val = $a->compare_date ($b); break;
	    case "author":
		$val = $a->compare_authors ($b); break;
	    case "area":
		$val = $a->compare_area ($b); break;
	    case "type":
		$val = $a->compare_type ($b); break;
	    default:	// Non-specific function
		$val = $a->compare_generic ($b, $field); break;
	    }

	    $val *= $reverse;	// If sort descending, reverse comparison
	    if ($val)
		return $val;	// Done comparing

	}
	assert ($val == 0);
	return ($val);
    }
}

?>
