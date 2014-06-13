<?php
/*
 *  PubEdit Object for editing a publication
 *  Copyright 2005 Stuart Prescott (publist@nanonanonano.net)
 *
 *  This file is part of the Publist package (publist.sf.net) 
 *  and is distributed under the terms of the GNU General Public License
 */

require_once 'publist.php';
require_once 'pub.php';

// XML data structure used by Publist, defined as constants rather than embedding bits
// of it all through the code
define('XML_SECTION_NAME',       'pubDB');
define('XML_PUBLICATION_NAME',   'publication');
define('XML_KEY_NAME',           'key');

// magic data key within a publication into which xml can be saved for later return
define('XML_HIDDEN_VALUES',   '__xml_representation');

// pieces of ini fields used to determine backup behaviour 
// (see publistfields.ini:[backups] and PubEdit::backup_file() for more details)
define('BACKUP_FILE_XML',     'xml');
define('BACKUP_FILE_UPLOAD',  'upload');
define('BACKUP_FILE_EDIT',    'edit');


/**
 * PubEdit class
 *
 * this class is used for editing the stored data in the publication database and the
 * data associated with the reference (i.e. the XML entry as well as the bibtex, abstract files
 * and other uploaded files for a reference.
 */

class PubEdit {
	var $key = '';          // the key of the field being edited
	var $publist;           // Publist object
	var $xmlfile;           // filename for XML file
	var $basepath;          // location of the base of the Publist installation
	var $fieldini;          // contents of the ini file for the fields
	var $fields;            // structs parsed from ini file for defining the fields
	var $uploads;           // structs parsed from ini file for defining the uploads
	var $editlink = '';     // link to use in editing refs
	var $max_file_size = 2097152;   // advisory maximum upload size, 2MB (PHP default), see ini file
	
	var $status_message = '';   // error messages to be returned to the user
	var $DEBUG = 0;
	
	// Methods: 

################################################
	///// Constructor for the editing functions 
	// $basepath     string     relative path to the PubList installation (for finding ini files
	//                          and upload directories
	// $filename     string     xml file to edit (only one file can be edited at a time)
	// $sort         string     sort order to use when creating the Publist object
	// $admin_config array      parsed ini file as created by PubAdmin
	// $publist_ini  string     filename (relative to $basepath) for the local ini file for Publist
	function PubEdit ($basepath, $filename, $sort='unsorted', &$admin_config, $publistini='') {
		// ensure that the path finishes with the directory separator (/) 
		// so that it can be concatenated with paths and filenames.
		if (substr($basepath, -1) != DIRECTORY_SEPARATOR) {
			$basepath .= DIRECTORY_SEPARATOR;
		}
		$this->basepath = $basepath;
		$this->xmlfile = $filename;
		// check if the XML file exists and try creating it if it doesn't
		// note that due to the permissions usually used on the webserver, this is 
		// *likely* to fail!
		if (! $this->create_xml_file()) {
			 // FIXME: should this die since we probably can't do anything useful if this is the case
			echo "ERROR: Cannot create new XML file $this->xmlfile.\n"
			    ."Please do so by hand and set the permissions correctly.\n";
			return; 
		}
		// create a PubList object which will allow us to find references 
		// Ignore the macros.dat file for the time being... one day we might implement macro editing too
		$this->publist = new Publist($basepath.$filename, $sort, '', $basepath.$publistini);
		// the fields to be edited are defined in the field ini file.
		$this->load_config($admin_config);
	}

################################################
	///// Parse the config information passed in by the PubAdmin object in the constructor
	// $admin_config     array     ini struct created by the PubAdmin object
	function load_config(&$admin_config) {
		$this->fieldini = $admin_config; 
		$this->setup_fields();
		$this->setup_uploads();
		$this->max_file_size = isset($this->fieldini['Meta_Uploads']['max_size']) ?
			                           $this->fieldini['Meta_Uploads']['max_size'] : 
			                           $this->max_file_size;
	}

################################################
	///// Load the field format specification from the ini data
	function setup_fields() {
		// Each field to be edited is defined in a section of the ini file beginning with "field_"
		$fields = preg_split("@\s+@", $this->fieldini['Meta_Fields']['order']);
		foreach ($fields as $key) {
			$this->fields[$key] = $this->fieldini['Field_'.$key];
		}

		// find each publication type and add it to the list of known types
		foreach ($this->publist->config->types as $type) {
			$this->fields['type']['qs_vals'][$type->name] = "$type->header ($type->name)";
		}
	}

################################################
	///// Load the uploads specification (names for uploads, target directories etc)
	function setup_uploads() {
		// Each file upload type is defined in a section of the ini file beginning with "Upload_"
		$uploads = preg_split("@\s+@", $this->fieldini['Meta_Uploads']['order']);
		foreach ($uploads as $key) {
			$this->uploads[$key] = $this->fieldini['Upload_'.$key];
		}
	}

// *********************************************
//            add reference functions
// *********************************************

################################################
	///// Display a form to let the user add a reference
	function add_reference_form() {
		echo '<h2>Add reference</h2>';
		$this->compile_quick_lists();
		#echo 'Enter data into <b>one</b> of the forms below<br />';
		$blank = array();
		$tmp_pub = new Publication($blank, $this->publist->config);
		if ($this->fieldini['Add_Methods']['fields'])    
			$this->reference_form($tmp_pub, 'Add');
		if ($this->fieldini['Add_Methods']['uploads'])
			$this->uploads_form($tmp_pub, 'Add');
		if ($this->fieldini['Add_Methods']['bibtex'])    
			$this->add_reference_form_bibtex('Add');
		if ($this->fieldini['Add_Methods']['formatted']) 
			$this->add_reference_form_formatted('Add');
		return true;
	}
	
################################################
	///// Create a new entry and write it to the file
	function add_reference() {
		echo '<h2>Add reference</h2>';
		$pub = $this->parse_submission();
		if (! $pub) {
			return false;
		}
		$this->key = $pub->key;
		// how the regexp editing is done depends on whether there is already a publication  
		// in the xml file or not
		if (! count($this->publist->pubs)) {
			$regexp = '@(' . $this->tag_generate(XML_SECTION_NAME, true, true) .'\s+)@s';
			$replace = $this->tag_generate(XML_SECTION_NAME, true)."\n\n".$this->construct_xml($pub)."\n\n";
		} else {
			$regexp = '@(\s+' . $this->tag_generate(XML_PUBLICATION_NAME, true, true) .')@s';
			$replace = "\n\n".$this->construct_xml($pub)
			          ."\n\n".$this->tag_generate(XML_PUBLICATION_NAME, true);
		}
		$status = $this->edit_xml_file($regexp, $replace, $pub);
		$status = $status && $this->move_uploads($pub);
		$status = $status && $this->show_edited_ref($pub);
		return $status;
	}

// *********************************************
//           edit references functions
// *********************************************

################################################
	///// Find out what reference to edit
	function edit_reference_select() {
		echo '<h2>Edit reference</h2>';
		$overrides = array('extralink' => $this->editlink, 'dir_prefix' => $this->basepath);
		$this->publist->print_all(false, $overrides);
		return true;
	}

################################################
	///// create a form that allows the user to upload files for a reference
	// $key    string     BibTeX/XML key of the publication to edit
	function edit_uploads_form($key) {
		echo '<h2>Edit reference</h2>';
		echo "<input type='hidden' name='ref' id='ref' value='$key' />";
		$pub = $this->get_pub_by_key($key);
		$this->uploads_form($pub, 'Edit');
		return true;
	}
	
################################################
	///// upload files for a reference
	// $key    string     BibTeX/XML key of the publication to edit
	function edit_uploads($key) {
		echo '<h2>Edit reference</h2>';
		echo "<input type='hidden' name='ref' value='$key' />";
		$pub = $this->get_pub_by_key($key);
		$status = $this->move_uploads($pub);
		$status = $status && $this->show_edited_ref($pub);
		return $status;
	}

################################################
	///// open an individual text file for editing
	// $key    string     BibTeX/XML key of the publication to edit
	// $class  string     class of uploaded file to edit, must be a key of $this->uploads
	function edit_file_form($key, $class) {
		$this->log("edit_file_form($key, $class)",9);
		echo '<h2>Edit reference</h2>';
		$file = $this->get_current_files($class, $key);
		if (count($file)) {
			$this->log("edit_file_form(): using file $file[0]",10);
			$currentfile = join('', file($file[0]));   // assumes $key is not substring
		} else {
			$this->log("edit_file_form(): No file found, creating new",10);
			$currentfile = '';
		}
		$name = $this->uploads[$class]['name'];
		echo '<fieldset>';
		echo '<input name="ref" id="ref" type="hidden" value="'.$key.'" />';
		echo 'Edit '.$name.' file for reference:<br />';
		echo '<textarea name="filecontents" rows="20" cols="80">'.$currentfile.'</textarea><br />';
		echo '<input type="submit" name="submitfile" value="Update file" />';
		echo '</fieldset>';
		return true;
	}

################################################
	///// sync the edits on an individual text file back to disk
	// $key    string     BibTeX/XML key of the publication to edit
	// $class  string     class of uploaded file to edit, must be a key of $this->uploads
	function edit_file($key, $class) {
		echo '<h2>Edit reference</h2>';
		// find the current filename or generate one if needed
		$file = $this->get_current_files($class, $key);
		if (count($file)) {
			$filename = $file[0];   // assumes $key is not substring
		} else {
			$filename = $this->basepath.$this->uploads[$class]['directory']
				           .$key.'.'.$this->uploads[$class]['default_ext']; 
		}
		$newfile = stripslashes($_POST['filecontents']);
		$status = $this->backup_file($filename, BACKUP_FILE_EDIT);
		if (! $status) return false;
		$fh = @ fopen($filename, 'w');
		if ($fh === false) {
			$this->status_message .= "ERROR: could not open $filename\n"
			                         .(isset($php_errormsg) ? $php_errormsg : '');
			return false;
		}
		$status = @ fwrite($fh, $newfile, strlen($newfile));
		if ($status == 0 && strlen($newfile) != 0) {
			$this->status_message .= "ERROR: could not write to $filename\n"
			                         .(isset($php_errormsg) ? $php_errormsg : '');
			return false;
		}
		fclose ($fh);
		echo "<p>$class file $filename updated with $status bytes of data.</p>";
		$pub = $this->get_pub_by_key($key);
		$this->show_edited_ref($pub);
		return $key;
	}

################################################
	///// Display a form to let the user add a reference
	// $key    string     BibTeX/XML key of the publication to edit
	function edit_reference_form($key) {
		echo '<h2>Edit reference</h2>';
		echo "<input type='hidden' name='ref' value='$key' />";
		$this->compile_quick_lists();
		$pub = $this->get_pub_by_key($key);
		$this->reference_form($pub, 'Update');
		return true;
	}
	
################################################
	///// on input error, return a partially filled in form to help the user fix the data
	// Some well-behaved browsers will allow the user just to use the back button, but others
	// will will wipe out all the edits when the user hits back to fix data which is a pretty
	// unhappy event that everyone has sworn about at some stage...
	// $dataarray    array     ($field => $value) array of data to be displayed in the form
	// $startkey     string    the value of the publication key prior to editing
	function edit_error_form($dataarray, $startkey) {
		echo '<h3>Please try again</h3>';
		$this->compile_quick_lists();
		echo "<input type='hidden' name='ref' value='$startkey' />";
		$this->reference_form(new Publication($this->array_strip_slashes($dataarray),
			                                    $this->publist->config), 'Add or edit ');
		return false;
	}

################################################
	///// Write the edited reference to the xml file
	// $startkey    string     BibTeX/XML key of the publication to edit 
	// note that the key might have been changed in the editing process, so $startkey
	// is only what the key is in the XML at the moment not necessarily what is in the form now
	function edit_reference($startkey) {
		echo '<h2>Edit reference</h2>';
		$pub = $this->parse_submission($startkey);
		if (! $pub) {
			return false;
		}
		// What we basically want is (with whitespace \s* stuff removed):
		//    @(<publication>.+?<key>$key</key>.+?</publication>)@
		// but the first .+? is too greedy and will match everything from the first <publication>
		// in the file up to the key.
		//
		// So we use negative look-ahead assertion in the .+? sections to prevent publication tags
		// from being included in the match, thus ensuring that we have only the XML section 
		// corresponding to the publication of interest to us right now.
		// We start with the regexp from the J.Friedl's book "Mastering regular expressions"
		// that matches a pair html tags (<tag> and </tag>) without allowing <tag> or </tag> to
		// be within the two tags. (This regexp allows <tag foo=bar> too)
		//    @<tag([^>]*)>(((?!</?tag(?:[^>]*)>).)*)</tag>@si
		// Now, we change this a little bit to allow for additional whitespace within the tags:
		//    @<\s*tag([^>]*)>(((?!<\s*/?\s*tag(?:[^>]*)>).)*)<\s*/\s*tag\s*>@si
		// All that remains is to put the requirement to have <key>$key</key> in there (once
		// again allowing all manner of whitespace in there). The negative lookahead assertion
		// goes on both sides of the $key line to be sure (note: .+? should be fine after $key, but
		// it doesn't seem worth not doing it this way).
		//
		// Note that there have been some reports of PCRE running out of memory if there are 
		// too many characters between <tag> and </tag>. The limits seem to be about 
		// 2kB between the tags for Win32 systems and 10-12kB between the tags for linux systems
		// so this shouldn't be a problem for PubEdit. 
		// See PHP bug 25754 for more details on this:    http://bugs.php.net/bug.php?id=25754
		
		// Use $this->tag_generate() to actually create the tags programatically as it cuts 
		// down on a lot of copy-pasting of code... but it might obfuscate the regexp a little as
		// you don't see it all in once place... but since when were regexps like this one easy
		// to read anyway?
		$regexp = '@('.$this->tag_generate(XML_PUBLICATION_NAME, true, true)
		          // match individual characters but with a negative lookahead assertion that 
		          // the character not be the start of a <publication> or </publication> tag
		          .'(?:(?!<\s*/?\s*'.XML_PUBLICATION_NAME.'(?:[^>]*)>).)*'
		          // require <key>$key</key> to be in here somewhere
		          .$this->tag_generate(XML_KEY_NAME, true, true).'\s*'
		            .preg_quote($startkey, '@').'\s*'
		          .$this->tag_generate(XML_KEY_NAME, false, true) 
		          .'(?:(?!<\s*/?\s*'.XML_PUBLICATION_NAME.'(?:[^>]*)>).)*'
		          .$this->tag_generate(XML_PUBLICATION_NAME, false, true)
		          .')@si';
		// This regexp also works but is much less efficient with a .+ to start with
		// $regexp = '@.+('.XML_PUBLICATION_ON
		//          .'.*?'.XML_KEY_ON.'\s*'.preg_quote($startkey, '@').'\s*'.XML_KEY_OFF 
		//          .'.*?'.XML_PUBLICATION_OFF
		//          .')@s';
		// since the above regexp will match everything above into \1, it needs to be replaced back in
		$replace= $this->construct_xml($pub);
		$status = $this->edit_xml_file($regexp, $replace, $pub);
		$status = $status && $this->show_edited_ref($pub);
		return $status;
	}

################################################
	///// Show the edited publication for review by the user
	// $pub    Publication object     the publication to be displayed now it has been edited
	function show_edited_ref($pub) {
		echo '<div class="editpub"><p>Reference ('.$pub->key.') updated.</p>';
		echo '<ul>';
		$pub->print_pub($this->publist->config, array('dir_prefix' => $this->basepath));
		echo '</ul></div>';
		$this->key = $pub->key;   // save the current key as it is useful to refer to it later
		return true;
	}

// *********************************************
//       XML file manipulation functions
// *********************************************

################################################
	///// Create an empty XML file (if possible!) if it doesn't exist
	// This is likely to fail on most installations due to directory permissions, 
	// but we might as well try as not!
	function create_xml_file() {
		$filename = $this->basepath.$this->xmlfile;
		if (! file_exists($filename) || ! is_file($filename)        // must exist and be a file
		   || (file_exists($filename) && filesize($filename) == 0)  // must also have non-zero size
		   ) { 
			$newxml = $this->tag_generate(XML_SECTION_NAME, true)."\n\n"
				       .$this->tag_generate(XML_SECTION_NAME, false)."\n";
			$fh = fopen($filename, 'w');
			$status = fwrite($fh, $newxml, strlen($newxml));
			return $status;
		} else {
			return true;
		}
	}
	
################################################
	///// Write to the XML file using regexps to locate the correct part of the file to write to
	// performs a preg_replace using 
	// $regexp    string    existing reference (or top of file) to match
	// $replace   string    xml-representation of the publication to be included
	// $pub       Publication object     actual publication object, returned on success
	//
	############## FIXME ############
	## WARNING: This is a race condition between openning the file for read and writing 
	## the updated file -- running a second admin class in this time would lead to data loss.
	## (no locking is done here)
	############## FIXME ############
	function edit_xml_file($regexp, $replace, $pub) {
		$this->log("edit_xml_file being asked to:\n$regexp\n$replace", 10);
		$xmlfile = @ file($this->basepath.$this->xmlfile);
		if (! is_array($xmlfile)) {
			// error openning file
			$this->status_message .= "ERROR: could not read $this->xmlfile"
			                         .(isset($php_errormsg) ? $php_errormsg : '');
			return false;
		}
		// concatenate the file so we can manipulate it as one string
		$allxml = join($xmlfile, '');
		$starting_filesize = strlen($allxml);
		$status = true;
		// First make sure that the pattern is in there and then use the actual match to the pattern
		// to perform the replacement -- this speeds preg_replace by a factor of 3000. No really.
		//(Also, PHP4 can't check that the preg_replace worked (PHP5 can), so using preg_match also 
		// tells us that the replacement will work OK.)
		$match = array();
		$status = preg_match($regexp, $allxml, $match);
		if (! $status) {  // error condition finding the regexp.
			// display the regexp (html-ised) for debugging purposes.
			$this->status_message .= "ERROR: regexp did not work out for editing the file.\n"
			                          .preg_replace(array('/</', '/>/'), array('&lt;', '&gt;'), $regexp);
			return false;
		}
		$match = preg_quote($match[1], '@');
		$newxml = preg_replace("@$match@s", $replace, $allxml, 1);  // limit to 1 replace
		
		#echo "<pre>"; print_r($allxml); echo "</pre>";
		// The new contents of the xml file should now be in $newxml so we can open the xml file
		// for writing and dump out the contents. Note that fopen('w') truncates the file to zero length
		$status = $this->backup_file($this->basepath.$this->xmlfile, BACKUP_FILE_XML);
		if (! $status) return false;
		$fh = @ fopen($this->basepath.$this->xmlfile, 'w');
		if ($fh === false) {
			// error, couldn't open the file. 
			$this->status_message .= "ERROR: could not open $this->xmlfile.\n"
			                         .(isset($php_errormsg) ? $php_errormsg : '');
			return false;
		}
		$status = @ fwrite($fh, $newxml, strlen($newxml)); // suppress the error message into $php_errormsg
		if ($status == 0) {
			// error, couldn't write to the file. 
			$this->status_message .= "ERROR: could not write to $this->xmlfile.\n"
			                         .(isset($php_errormsg) ? $php_errormsg : '');
			return false;
		}
		// If we get this far then the write should have been successful and we can tell the user that
		// all was well
		fclose ($fh);
		echo "<p>XML file $this->xmlfile updated with ".($status-$starting_filesize)." bytes of data"
			  ." (filesize now $status bytes)</p>";
		return $pub->key;
	}

// *********************************************
//           form display functions
// *********************************************

################################################
	///// Display a form to let the user add a reference in BibTeX format
	// $submit    string    used on the submit button (e.g. "add" or "edit")
	function add_reference_form_bibtex($submit) {
		echo '<fieldset>';
		echo 'Add reference from existing BibTeX file:<br />';
		echo '<textarea name="rawbibtex" rows="10" cols="60"></textarea><br />';
		echo '<input type="submit" name="submitbibtex" value="'.$submit.' reference" />';
		echo '</fieldset>';
	}
	
################################################
	///// Display a form to let the user add a reference from a formatted source
	function add_reference_form_formatted($submit) {
	// $submit    string    used on the submit button (e.g. "add" or "edit")
		echo '<fieldset>';
		echo 'Add reference from existing formatted reference:<br />';
		echo '<textarea name="formatted" rows="3" cols="60"></textarea><br />';
		echo '<input type="submit" name="submitformatted" value="'.$submit.' reference" />';
		echo '</fieldset>';
	}

################################################
	///// Display a form to let the user add or edit a reference entry by entry
	// $pub           Publication object      Default data to load into the form for editing
	// $submit_verb   string                  Verb to be put on the submit button (e.g. "Add")
	function reference_form($pub, $submit_verb) {
		echo '<fieldset>';
		$this->copy_JavaScript();
		$this->key_generate_JavaScript();
		echo $submit_verb.' reference by editing individual fields:<br />';
		echo '<input type="hidden" name="ref" id="ref" value="'.$pub->key.'" />';
		echo '<table>';
		foreach ($this->fields as $fname => $f) {
			// each field is displayed in a table row 
			$this->reference_form_field($fname, $f, $pub);
		}
		echo '</table>';
		echo '<input type="submit" name="submitfields" value="'.$submit_verb.' reference" />';
		echo '<div>* required fields</div>';
		echo '</fieldset>';
	}

################################################
	///// Display an individual field inside a table row, formatting based on the settings for this field
	// $fname     string    name of the field (html name='' parameter), also the data key in $p
	// $f         array     component of the field array struct created by $this->setup_fields()
	// $p         Publication object       source of default data for this form
	function reference_form_field($fname, $f, $p) {
		// Each field has its own table row of the following columns:
		// Name       Data_entry        Quick_Select_list
		// unused Quick_select columns are removed with colspan 
		
		// Name column:
		echo '<tr><td>' . $f['name']
					.(isset($f['required']) ? '*' : '')    // Mark required fields with a *
					.'</td>';
		
		// Data entry column:
		// Field data entry widget; if there's no quickselect section then span two columns
		echo '<td'.(isset($f['quickselect']) && $f['quickselect'] ? '' : ' colspan="2"').'>';
		// generate the html based on the representation type
		$type = isset($f['type']) ? $f['type'] : 'text';
		if (isset($f['onchange']) && ! empty($f['onchange'])) { 
			$onchangecall = "{$f['onchange']}(\'$fname\')";
			$onchange = "onChange='{$f['onchange']}(\"$fname\")' ";
		} else {
			$onchangecall = "";
			$onchange = "";
		}
		switch ($type) {
			case 'text':
				echo '<input type="text" size="60" name="'.$fname.'" id="'.$fname.'" '.$onchange
							.$this->get_field_value($p, $fname, $f, NULL, 'value="%s"')
							.' />';
				break;
			case 'dropdown':
				echo '<select name="'.$fname.'" id="'.$fname.'" '.$onchange.'>'
							.$this->option_list($f['qs_vals'], $this->get_field_value($p, $fname, $f))
						.'</select>';
				break;
			case 'textarea':
				echo '<textarea cols="40" '
							.'rows="'.(isset($f['size']) && $f['size'] ? $f['size'] : '8').'" '.$onchange
							.'name="'.$fname.'" id="'.$fname.'">'
							.$this->get_field_value($p, $fname, $f)
							.'</textarea>';
				break;
			case 'checkbox':
				$f['checkbox_values'] = explode(',', isset($f['checkbox_values'])
																									? $f['checkbox_values'] : 'true,false');
				$val = $this->get_field_value($p, $fname, $f, 'true');
				echo '<input type="checkbox" name="'.$fname.'" id="'.$fname.'" '
							.'value="'.$f['checkbox_values'][0].'"'.$onchange
							.($val == $f['checkbox_values'][0] ? ' checked="checked"' : '')
							.' />';
				break;
		} 
		echo "</td>\n";
		
		// quick select parts if applicable
		if (isset($f['quickselect']) && $f['quickselect']) {
			$size = (isset($f['qs_size']) && $f['qs_size'] ? $f['qs_size'] : '8');
			echo '<td>'
						.'<select name="'.$fname.'list"'
							.'size="'.$size.'" '
							.'>'
							.$this->option_list($f['qs_vals'])
						.'</select><br />'
						// include a button to add the currently selected entry to the text entry
						.'<input type="button" size="6" name="'.$fname.'add" value="&laquo; add" '
							.'onclick="addTo(\''.$fname.'\','.($size<=1?'true':'false').', \''.$onchangecall.'\')"/>'
						.'</td>';
		}
		echo "</tr>\n";
	}

################################################
	///// print the existing values as a set of options for a select list.
	// $a      array      ($value => $text_name) list for options in an html select 
	// $val    string     default selected value
	// Generates html tags: <option value=$value>$text_name</option> 
	function option_list($a, $val=NULL) {
		$options = array();
		foreach ($a as $i => $e) {
			$options[] = '<option value="'.$i.'" '.($i==$val?'selected="selected"':'').'>'.$e
			             .'</option>';
		}
		return join($options, "\n");
	}

################################################
	///// Display a form to let the user upload source files
	// $p        Publication object     source of initial data for the form
	// $submit   string                 text for submit button
	function uploads_form($p, $submit) {
		echo '<fieldset>';
		//  MAX_FILE_SIZE is advisory to the browser
		echo '<input type="hidden" name="MAX_FILE_SIZE" value="'.$this->max_file_size.'" />';
		echo 'Upload files to the server for this reference: (max upload approx ' 
		      .ceil($this->max_file_size/1024).'kB)<br />';
		// JavaScript to generate the filename when a new file is being uploaded
		$this->filename_JavaScript();
		echo '<table width="100%">';
		echo '<tr><th>Directory</th><th>New file to upload</th><th>Current/Uploaded file details</th></tr>';
		// print a line item for each item with:
		//  Description         file upload form         current filename and size
		foreach ($this->uploads as $upname => $u) {
			if ($p->key !== '') {  // only worth checking for files if the key is defined
				$files = $this->get_current_files($u['directory'], $p->key);
			}
			echo '<tr><td>' . $u['name'] . '</td>';
			echo '<td><input name="upload_'.$upname.'" id="upload_'.$upname.'" type="file" onChange="upload_create_filename(\'upload_'.$upname.'\')" /></td>';
			if (isset($files[0]) && is_readable($files[0])) {
				$filename = $files[0];   // NOTE: this will pick up substring files too, but so will pub.php
				echo '<td><a href="'.$filename.'">'
				          .basename($filename).'</a> ('.ceil(filesize($filename)/1024).'kB)'
				          .'<div id="label_upload_'.$upname.'"></div></td>';
			} else {
				echo '<td><div id="label_upload_'.$upname.'"></div></td>';
			}
			echo "</tr>\n";
		}
		echo '</table>';
		echo '<input type="submit" name="submitfiles" value="'.$submit.' reference" />';
		echo '</fieldset>';
}

// *********************************************
//             lookup functions
// *********************************************

################################################
	///// Find the reference in the publist based on the key
	// $key    string     BibTeX/XML key of the publication to edit
	function get_pub_by_key($key) {
		// ensure that the quick look-up table for finding references in
		// the sorted reference list has been created
		$this->publist->make_lut();
		if (! array_key_exists($key, $this->publist->pubs_lut)) return false;
		return $this->publist->pubs[$this->publist->pubs_lut[$key]];
	}
	
################################################
	///// find out what files correspond to a reference key in a particular target directory
	// $target        string      one of the upload_* sections from the ini file
	// $key           string      reference key on which to search
	//
	// the directory is as specified in the ini file for this this target
	//
	// WARNING: if $key is the initial substring of some other 
	// publication's key (e.g. Smith2000 and Smith2000a)
	// then this function might return the wrong file. (This is the same
	// behaviour as pub.php::format_link() so it's at least consistent!)
	function get_current_files($target, $key) {
		$dir = isset($this->uploads[$target]['directory']) 
			        ? $this->uploads[$target]['directory'] : $target;
		$dir = (substr($dir, -1) == DIRECTORY_SEPARATOR) ? $dir : $dir.DIRECTORY_SEPARATOR;
		$filesearch = $this->basepath.$dir.$key .'*';
		$this->log("get_current_files($dir, $key): searching for $filesearch", 9);
		return glob($filesearch);
	}

################################################
	///// Obtains the current value, a default value or no value depending on settings for field
	// Correctly handles booleans, joins array data etc for constructing the html form
	//
	// $p     Publication Object     current Publication object being edited
	// $fname    string          field name being considered
	// $field    string          component of field definition array from $this->setup_fields()
	// $default  mixed           default value to return if none specified, (default: '')
	// $format   string          munge data through sprintf to sanitise it, (default: %s)
	function get_field_value($p, $fname, $field, $default='', $format='%s') {
		$value = NULL;
		// see if the publication has currently set data
		if (isset($p->data[$fname]) && $p->data[$fname] !== '') {
			// Since there is data present, the behviour depends on the current data type.
			if (is_array($p->data[$fname])) {
				#echo "Found array";
				$value = join($p->data[$fname], "\n")."\n";
			} elseif ($p->data[$fname] === true || $p->data[$fname] === false) {
				#echo "found boolean";
				$value = $p->data[$fname] ? $field['checkbox_values'][0] : $field['checkbox_values'][1];
			} else {
				#echo "found string";
				$value = $p->data[$fname];
			}
		// if no value is set, then see if this field has a default value set
		} elseif (isset($field['default'])) {
			#echo "found nothing -> default";
			$value = $field['default'];
		// finally, use the default value passed in to this function
		} else {
			$value = $default;
		}
		// If a value has been set for this field, then pass it through sprintf to sanitise the data
		if ($value !== NULL) {
			return sprintf($format, $value);
		}
		// don't actually return NULL as that will make later handling harder, return an empty string
		return '';
	}

################################################
	///// compile the lists of values that are already in the XML file to help speed data entry
	// This function relies on $this->fields which initialised by $this->setup_fields() 
	// and contains the setup information for each field in the XML file and 
	// how that field should be represented in an html form.
	function compile_quick_lists() {
		$qs_fields = array();
		// first, look through the defined fields to work out what fields should be collated
		foreach (array_keys($this->fields) as $k) {
			if (isset($this->fields[$k]['quickselect']) && $this->fields[$k]['quickselect']) {
				$qs_fields[] = $k;
				$this->fields[$k]['qs_vals'] = array();
			}
		}
		//$this->log('compile_quick_lists(): '. join($qs_fields, ' '));
		// run through each publication and record each entry from the identified fields
		// Only entries from user-specified publn types are collated (e.g. there's no point
		// in collating the name of a Book into the Title field as you'll only ever enter that
		// book once, but names of journals will come up repeatedly.
		foreach ($this->publist->pubs as $p) {
			foreach ($qs_fields as $k) {
				if (! isset($this->fields[$k]['qs_from_types']) 
				    || preg_match('@\b'.$p->data['type'].'\b@', $this->fields[$k]['qs_from_types'])) {
					// can't keep the data if it doesn't exist, and author arrays should be handled differently
					if (isset($p->data[$k])) {
						if (is_array($p->data[$k])) {
							$this->fields[$k]['qs_vals'] = array_merge($this->fields[$k]['qs_vals'], $p->data[$k]);
						} else {
							$this->fields[$k]['qs_vals'][] = $p->data[$k];
						}
					}
				}
			}
		}
		// finally, remove blank entries from the listing and sort the entries for display
		foreach ($qs_fields as $k) {
			$this->fields[$k]['qs_vals'] = preg_grep('/^\s*$/', 
			                                 array_unique($this->fields[$k]['qs_vals']),
			                                 PREG_GREP_INVERT);
			sort($this->fields[$k]['qs_vals']);
		}
	}

################################################
	///// work out how to parse the submitted data and return a Publication object (or false on error)
	// $startkey     string     initial key for the publication object, may have changed in editing
	function parse_submission($startkey='') {
		// parse the submission, working out what has been submitted (a bibtex entry, the xml fields etc)
		if (isset($_POST['rawbibtex']) && ! empty($_POST['rawbibtex'])) {
			$this->log("parse_submission(): Using BibTeX bib2xml",5);
			// this one's a bit of an exception as we can't really validate the entry properly
			return $this->parse_submission_bibtex();
		} elseif (isset($_POST['formatted']) && ! empty($_POST['formatted'])) {
			$dataarray = $this->parse_submission_formatted();
		} else {
			$dataarray = $this->parse_submission_fields();
		}
		if ($dataarray === false) {
			$this->log("parse_submission(): No data could be found",5);
			return false;
		}
		$cleandata = $this->check_submission($dataarray, $startkey);
		if (! $cleandata) {
			$this->edit_error_form($dataarray, $startkey); 
			return false;
		}
		// create the publication object all nicely parsed and looking good
		$pub = new Publication($cleandata, $this->publist->config);
		#echo "<pre>"; print_r($pub->data); echo "</pre>";
		return $pub;
	}
	
################################################
	///// parse the data that was submitted in the bibtex form and load it into a pub Object.
	// use bib2xml to do the actual parsing. Also, we keep the xml that has been generated rather than
	// making it again later.
	// This function requires two temp files: 
	//  * bib2xml requires a file for input 
	//  * the PubList constructor requires an XML file rather than an XML stream/string
	// Both are created using tempnam() which *should* be secure to tmpfile vulns (maybe)
	// and both are unlinked at the end of the function before returning.
	function parse_submission_bibtex() {
		$this->log("parse_submission_bibtex(): using bib2xml... good luck!",9);
		// call bib2xml.pl to do this
		$conv = $this->fieldini['Add_Methods']['bib2xml'];
		if (empty($conv) || ! is_executable($conv)) {
			$this->status_message = "ERROR: Couldn't find bib2xml: $conv";
			return false;
		}
		// put the bibtex data into a temp file 
		$tmpbibtex = tempnam("/tmp", "pubadmin");
		$handle    = fopen($tmpbibtex, "w");
		fwrite($handle, $_POST['rawbibtex']);
		fclose($handle);
		// use bib2xml to create the appropriate xml format in another temp file
		$tmpxml    = tempnam("/tmp", "pubadmin");
		// if bib2xml returns an exit code we could monitor that here:
		exec("$conv $tmpbibtex > $tmpxml");
		$tmppublist = new PubList($tmpxml, 'unsorted', '');
		#echo "<pre>"; print_r($tmppublist->pubs); echo "</pre>";
		// there should only be one publication in the file so only one pulication in the new Publist.
		$pub = array_shift($tmppublist->pubs);
		// read the xml generated by bib2xml in and store it for later use 
		// (strip the pubDB /pubDB bit though)
		$xml = file($tmpxml);
		array_shift($xml);
		array_pop($xml);
		$pub->data[XML_HIDDEN_VALUES] = join($xml ,''); // poke the XML in a private spot for future usage
		unlink($tmpbibtex);   // clean up temp files
		unlink($tmpxml);
		return $pub;
	}
	
################################################
	///// parse the data that was submitted in the formatted ref form and load it into a pub Object.
	function parse_submission_formatted() {
		$this->log("parse_submission_formatted(): UNIMPL",9);
		echo "FFU: Not implemented";
		// this needs the regexp from hell... and lots of hints from the user as to the format
		// the JabRef implementation of this is very impressive, but can't really be done on
		// a web page.
		return false;
	}
	
################################################
	///// parse the data that was submitted in the field-by-field form and load it into a pub Object.
	function parse_submission_fields() {
		$this->log('parse_submission_fields(): using $_POST data',9);
		return $_POST;
	}

################################################
	///// ensure that the submission data is valid
	// $subarray     array     input data array of ($field => $value)
	// $startkey     string    initial value of the publn key before editing
	function check_submission($subarray, $startkey) {
		$this->log("check_submission(): Checking data",9);
		// fix up the submitted data to make some sense of it
		$status = $this->fix_booleans($subarray);
		$status = $status && $this->concatenate_multiline($subarray);
		$status = $status && $this->check_required($subarray);
		$status = $status && $this->check_unique($subarray, $startkey);
		
		if (! $status) {
			$this->log("check_submission(): Data check failed:".$this->status_message,5);
			// each of the check functions above should raise it's own status message if something
			// went wrong, so we can just bail out
			return false;
		}
		$this->log("check_submission(): Combining submission with original data",10);
		$clean = array();
		// By default, preserve fields that are in the XML file (hence in the Publication object)
		// that are not explicitly listed in the Field_* sections of the ini file.
		if (! isset($this->fieldini['Meta_Fields']['preserve']) 
		         || $this->fieldini['Meta_Fields']['preserve']) {
			$oldpub = $this->get_pub_by_key($startkey);
			if ($oldpub) {
				$subarray  = array_merge($oldpub->data, $subarray);
				$fieldlist = array_keys(array_merge($oldpub->data, $this->fields));
			} else {
				$fieldlist = array_keys($this->fields);
			}
		} else {
			$fieldlist = array_keys($this->fields);
		}
		foreach($fieldlist as $field) {
			// only pull out data that corresponds to fields in which we are actually interested
			if (isset($subarray[$field]))
				$clean[$field] = $subarray[$field];
		}
		$clean = $this->array_strip_slashes($clean);
		return $clean;
	}

################################################
	///// fix up boolean variables
	// booleans are a bit of a mess when they come back from checkboxes on a form
	// we need to work out wehther a value was specified and if not what the default value is
	function fix_booleans(&$subarray) {
		foreach (array_keys($this->fields) as $k) {
			if (isset($this->fields[$k]['checkbox_values'])) {
				$bool = explode(',', $this->fields[$k]['checkbox_values']);
				$subarray[$k] = isset($subarray[$k]) && strlen($subarray[$k]) ? $bool[0] : $bool[1];
			}
		}
		// no need for error handling / input validation
		return true;
	}

################################################
	///// concatenate the lines for author, editor etc as required
	// &$subarray   arrray     input data array to have multiline fields munged
	function concatenate_multiline(&$subarray) {
		foreach (array_keys($this->fields) as $k) {
			if (isset($this->fields[$k]['concatenate_lines']) && $this->fields[$k]['concatenate_lines']) {
				// split the field up on end-of-line chars (from the textarea used to edit this field)
				$lines = preg_split("/[\r\n]+/", $subarray[$k]);
				$clean = array();
				// clean each line of the input individually and remove entirely blank lines
				foreach ($lines as $l) {
					$l = preg_replace("@\s+@s", " ", $l);
					$l = preg_replace("@[\r\n]@", '', $l);
					if (! preg_match('@^\s*$@s', $l)) $clean[] = $l;
				}
				// finally concatenate the lines with the designated separator
				$subarray[$k] = join($clean, $this->fields[$k]['concatenate_lines']);
			}
		}
		// no need for error handling / input validation
		return true;
	}

################################################
	///// check that fields marked "required" were indeed submitted
	// $subarray   arrray     input data array to have requisite fields checked
	function check_required($subarray) {
		$status = true;
		foreach (array_keys($this->fields) as $k) {
			// each field that is marked as "required" in the ini file should be checked
			if (isset($this->fields[$k]['required']) && $this->fields[$k]['required']) {
				if(!isset($subarray[$k]) || empty($subarray[$k])) {
					$status = false;
					$this->status_message .= "Required field $k was empty.\n";
				}
			}
		}
		return $status;
	}

################################################
	///// check that fields marked "unique" are unique in the XML file
	// $subarray   array      input data array to have requisite fields checked
	// $startkey   string     original key for this publication
	// note that $startkey is needed as none of the values will be unique if this reference
	// is already in the file. The publn that matches $startkey is thus removed from uniqueness tests.
	function check_unique($subarray, $startkey) {
		$status = true;
		// loop through each field and see it is supposed to be unique and then 
		// check it against each publication
		foreach (array_keys($this->fields) as $k) {
			if (isset($this->fields[$k]['unique']) && $this->fields[$k]['unique']) {
				$thisval = $subarray[$k];
				foreach ($this->publist->pubs as $p) {
					if ($p->key == $startkey) {
						break;     // we don't have to be unique compared with the reference we're editing
					}
					if ($p->data[$k] == $thisval) {
						$status = false;
						$this->status_message .= "Field $k should be unique but it is also in reference {$p->key}\n";
					}
				}
			}
		}
		return $status;
	}

	################################################
	///// construct an XML representation of the publication
	// $pub      Publication object     the object to be converted to XML
	// note that if $pub->data has the magic key (XML_HIDDEN_VALUES) within it then the contents
	// of this key will be returned instead. This allows us to use the xml generated by bib2xml.
	function construct_xml($pub) {
		if (isset($pub->data[XML_HIDDEN_VALUES]) && ! empty($pub->data[XML_HIDDEN_VALUES])) {
			return $pub->data[XML_HIDDEN_VALUES];
		}
		// start by pushing each part of the XML into an array and the join() that array at the end
		$xml = array();
		foreach (array_keys($pub->data) as $k) {
			$val = $pub->data[$k];
			if ( (! is_array($val) && ! empty($val))
							|| $val === '0' 
							|| (is_array($val) && count($val) && strlen($val[0]))
							|| isset($this->fields[$k]['checkbox_values'])
					) {
				$value = NULL;
				if (is_array($val)) {
					$glue = isset($this->fields[$k]['concatenate_lines']) 
											? $this->fields[$k]['concatenate_lines'] : " ";
					$value = join($val, $glue);
				} elseif (isset($this->fields[$k]['checkbox_values'])) {
					$bool = explode(',', $this->fields[$k]['checkbox_values']);
					$value = $val ? $bool[0] : $bool[1];
				} else {
					$value = $val;
				}
				// clean up XML bad values < and >
				$xml_badvals = array(
									'@<@'                             =>   '[[',          // see pub.php constructor
									'@>@'                             =>   ']]',          // see pub.php constructor
									'@&(?!\#\d{1,4};)@'               =>   '&amp;',
									'@&(?!\w{1,4};)@'                 =>   '&amp;',
									'@&((?!(?:amp|quot|lt|gt)));@'    =>   '&amp;$1;',    //FIXME: convert &times; to &amp;times;
							);
				$value = preg_replace(array_keys($xml_badvals), array_values($xml_badvals), $value);
				// push the constructed XML tag onto the stack
				$xml[] = "<$k>".$value."</$k>";
			}
		}
		$delim = "\n  ";  // include two spaces in the delimiter so that the tags are indented nicely
		return $this->tag_generate(XML_PUBLICATION_NAME, true).$delim
			       .join($xml, $delim)."\n"
			       .$this->tag_generate(XML_PUBLICATION_NAME, false);
	}

################################################
	///// move the user-uploaded files into the appropariate places and report success/failure
	// $pub    Publication object      publication for whom uploads are being managed
	function move_uploads($pub) {
		$this->key = $pub->key;
		$this->log("Trying to move uploaded files for $pub->key",9);
		$uploads = false;
		// look through the PHP $_FILES array to see if files were uploaded
		foreach (array_keys($this->uploads) as $upname) {
			if (array_key_exists('upload_'.$upname, $_FILES) && is_array($_FILES['upload_'.$upname]) 
				    && isset($_FILES['upload_'.$upname]['size']) && $_FILES['upload_'.$upname]['size']) {
				$uploads = true;
				break;
			}
		}
		// if there aren't any uploads then return straight away
		if (! $uploads) { 
			$this->log("No uploads to move this time.",5);
			return true;
		}
		
		// there are uploads present, so move them to the appropariate directory with the publn key
		// and the original file extension as the filename and report the success to the user
		$status = true;
		$errarray = array();
		echo '<table class="uploadsummary">';
		echo '<tr><th>File</th><th>Temporary File</th><th>Destination File</th>'
		        .'<th>File Size</th><th>Status</th></tr>';
		// iterate through the possible uploads and if files are present move them
		foreach ($this->uploads as $upname => $u) {
			if (is_array($_FILES['upload_'.$upname]) 
				     && isset($_FILES['upload_'.$upname]['size']) 
				     &&       $_FILES['upload_'.$upname]['size']) {
				//echo "<pre>"; print_r($_FILES[$upname]); echo "</pre>";
				echo '<tr><td>'.$upname.'</td>';
				$filename = basename($_FILES['upload_'.$upname]['name']);
				$basedir = (isset($u['directory']) ? $u['directory'] : $upname.DIRECTORY_SEPARATOR);
				// work out the filename that the uploaded file should have, autorename is the default
				if (! isset($u['autorename']) || $u['autorename']) {
					// what extension should the uploaded file have (doesn't include the dot, just txt pdf ppt etc)
					// FIXME: this will stuff up double extension files like .tar.gz
					$dotpos = strrpos($filename, '.');
					if ($dotpos === false) {
						// no . in the filename, so the destination filename is just the publn key
						$destfilename = $pub->key;
					} else {
						// the filename is then $key.$ext 
						$fileext = substr($filename, $dotpos+1);
						$destfilename = $pub->key . '.' . $fileext;
					}
				} else {
					$destfilename = $filename;
				}
				$destfile = $this->basepath . $basedir . $destfilename;
				// we need to know if there is already a file associated with this publn
				$oldfiles = $this->get_current_files($basedir, $pub->key);
				#echo "<pre>"; print_r($oldfiles); echo "</pre>";
				// backup existing files if they exist and according to the configuration in .ini file
				$thisstatus = $this->backup_file($destfile, BACKUP_FILE_UPLOAD);
				// move the uploaded file, clobbering the existing file if present
				$thisstatus = $thisstatus 
					        && @ move_uploaded_file($_FILES['upload_'.$upname]['tmp_name'], $destfile);
				if (! $thisstatus) $errarray[] = $php_errormsg;
				// if moving the file worked OK and the new filename is different to the old filename, 
				// we should remove the old file to make sure it doesn't get picked up by the glob() functions
				// that select which file to include in the links
				if ($thisstatus && isset($oldfiles[0]) && basename($oldfiles[0]) != $destfilename) {
					$thisstatus = @ unlink($oldfiles[0]);
					if (! $thisstatus) $errarray[] = $php_errormsg;
				}
				// check the filesizes to make sure that things worked, also checks that the file has moved
				// and is readable
				$size = 0;
				if ($thisstatus) {
					$size = @ filesize($destfile);
					if ($size === false) {
						$errarray[] = $php_errormsg;
					} elseif ($size !== $_FILES['upload_'.$upname]['size']) {
						$errarray[] = "Unknown error in upload, filesizes don\'t match: "
						            ."current size ($size B) != original size (".$_FILES['upload_'.$upname]['size']." B)";
					}
				}
				// report & record the status of this operation, showing the file size for the user to check
				$status &= $thisstatus;
				echo '<td>'.$_FILES['upload_'.$upname]['tmp_name'].'</td>';
				echo '<td>'.$destfile.'</td>';
				echo '<td>'.$size.'</td>';
				echo '<td>'.($thisstatus ? '<span class="pass">OK</span>' 
					                       : '<span class="fail">FAIL</span>').'</td>';
				echo '</tr>';
			}
		}
		echo '</table>';
		// put all the errors we got into an array for return to the user
		if (! $status) $this->status_message .= "ERROR(S) moving files:\n".join($errarray, "\n");
		return $status;
	}
		
################################################
	///// Keep a backup of files if desired
	// $filename   string            filename (full path or relative path) to backup
	// $option     string, optional  which backup options to check in ini file
	function backup_file($filename, $option=BACKUP_FILE_XML) {
		$this->log("backup_file(): backing up $filename with options $option",9);
		// if the file doesn't exist, we don't need to back it up
		if (! file_exists($filename)) {
			$this->log("backup_file(): $filename doesn't exist so doing nothing",7);
			return true;
		}
		if (isset($this->fieldini['Backups']['backup_'.$option]) 
			     && $this->fieldini['Backups']['backup_'.$option]) {
			if (isset($this->fieldini['Backups']['backup_'.$option.'_target']) 
				     && $this->fieldini['Backups']['backup_'.$option.'_target']) {
				$file = basename($filename);
				$targetfile = $this->fieldini['Backups']['backup_'.$option.'_target']
				                .DIRECTORY_SEPARATOR.$file.'.bak';
			} else {
				$targetfile = $filename.'.bak';
			}
			if (isset($this->fieldini['Backups']['backup_'.$option.'_keepbackups']) 
				     && $this->fieldini['Backups']['backup_'.$option.'_keepbackups']) {
				// if we are to keep backups, then we need a unique filename
				$targetfile .= '-'.date('Ymd-Hms').'-'.md5(uniqid(time()));
				if (file_exists($targetfile)) {  // should never happen, but you never know...
					$this->status_message = "ERROR: Backup file cannot be made, $targetfile already exists.\n";
					return false;
				}
			}
			return copy($filename, $targetfile);
		}
		return true;
	}

################################################
	///// Strip slashes from \\ \' etc in an array... loathe magic_quotes_gpc!
	function array_strip_slashes($dirty) {
		$clean = array();
		foreach ($dirty as $k => $b) {
			// if PHP's magic_quotes_gpc is on then the data needs cleaning of slashes
			$val = stripslashes($b);
			if ($val !== '') {
				$clean[$k] = $val;
			}
		}
		return $clean;
	}

################################################
	///// programatically generate XML tags or regexps for XML tags  
	// (only to reduce some code duplication, 
	// perhaps also makes the regexps a little more readable... perhaps... maybe...)
	function tag_generate($tag, $start=true, $regexp=false) {
		if (! $regexp &&   $start) return '<'.$tag.'>';
		if (! $regexp && ! $start) return '</'.$tag.'>';
		if (  $regexp &&   $start) return '<\s*'.$tag.'[^>]*>';   // allow whitespace around the tag
		if (  $regexp && ! $start) return '<\s*/\s*'.$tag.'\s*>'; // allow whitespace around the tag
	}

################################################
	///// Write a javascript quick-copy function to help consistent copying of data
	function copy_JavaScript() {
		echo <<<EOF

<script type="text/javascript">
	function addTo(target, overwrite, onchange) {
		sel = document.forms['publistform'].elements[target+'list'].selectedIndex;
		val = document.forms['publistform'].elements[target+'list'].options[sel].text;
		if (overwrite) {
			document.forms['publistform'].elements[target].value = val;
		} else {
			document.forms['publistform'].elements[target].value += val + "\\n";
		}
		if (onchange != '') eval(onchange);
	}
</script>

EOF;
	}

################################################
	///// Write a javascript function to show the user the filename that will be used for uploads
	function filename_JavaScript() {
		echo <<<EOF

<script type='text/javascript'>
	function upload_create_filename(id) {
		var infile = document.getElementById(id).value;
		var key  = '';
		key = document.getElementById('ref').value;
		if (key == '' || key == undefined)
			key = document.getElementById('key').value;
		var filename = infile.substring(1+(infile.lastIndexOf('/') == -1 ? infile.lastIndexOf('\\\\') : infile.lastIndexOf('/')));
		var ext = '';
		var dotpos = filename.lastIndexOf('.');
		if (dotpos != -1)
			ext = filename.substring(dotpos);
		var newFilename = key + ext;
		var label = document.getElementById('label_'+id);
		label.textContent = "Uploaded file name: " + newFilename;
	}
</script>

EOF;
	}

################################################
	///// Write a javascript function to show the user the filename that will be used for uploads
	function key_generate_JavaScript() {
		echo <<<EOF

<script type='text/javascript'>
	function pub_key_auto_create(id) {
		var keyField = document.getElementById('key');
		if (keyField.value != '') return;
		var authors = document.getElementById(id).value;
		var year = document.getElementById('year').value;
		var author1 = authors.substring(0, authors.indexOf(","));
		var newKey  = author1 + year;
		keyField.value = newKey;
	}
</script>

EOF;
	}

################################################
	///// logger function: control generation of debug information
	// $message   string   data to be logged within the html output stream
	// $priority  integer  verbosity level of the data (higher numbers more verbose)
	// Not all functions have lots of log() statements in them, only the ones where
	// some debug writes were necessary for tracing execution and working out WTF.
	//
	// In this class: 
	// DEBUG=5  will tell you about unusual events (bad data etc)
	// DEBUG=9  will tell you about going in and out of functions
	// DEBUG=10 will include gratuitous data on regexps being used etc
	function log ($message, $priority=10) {
		if ($priority <= $this->DEBUG) {
			$html_badchars = array(
				         '/&/'      => '&amp;',
				         '/</'      => '&lt;',
				         '/>/'      => '&gt;',
				         "/\n/"     => '<br />',   
				   );
			echo '<div class="log">'.__CLASS__.'::'
				.preg_replace(array_keys($html_badchars), 
				              array_values($html_badchars),
				               $message)
				.'</div>'."\n";
		}
	}


}

?>
