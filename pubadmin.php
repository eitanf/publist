<?php
/*
 *  PubAdmin: Object for administering a list of publications
 *  Copyright 2005 Stuart Prescott (publist@nanonanonano.net)
 *
 *  This file is part of the Publist package (https://github.com/eitanf/publist)
 *  and is distributed under the terms of the GNU General Public License
 */

/**
 * PubAdmin class: track the status of the editing transactions
 *
 * Since http is a stateless protocol, what seems like just one activity
 * (e.g. "edit a reference") actually includes multiple http requests
 * ("show the user the current values" and "sync changes to disk").
 * Here, we use the data from the browser (a dummy variable initially supplied
 * by this class in the previous transaction) to triage the transaction.
 * The GET or POST field used for this is "action"; this is stored within this
 * class as $this->action.  Similarly, there is a next_action that should
 * be what happens next in the workflow (e.g. a common sequence would be:
 * select reference to edit, edit form data, sync to disk).
 *
 * Typical usage:
 * create a PubAdmin instance, with the path to the publist installation to be
 * administered
 * also works out what action to perform and what action comes next
 * badmin = new PubAdmin('..', array('pubs.xml', 'books.xml'), 'local.ini', 'admin.ini');
 * use the form_start() and form_stop() methods to embed extra data in the form
 * badmin->form_start();
 * display the actions that are available to the user
 * $pubadmin->show_actions();
 * now actually do what we've been told to do
 * $pubadmin->perform_action();
 * $pubadmin->form_stop();
 *
 */

require_once 'pubedit.php';

class PubAdmin {
    var $listedit;          // PublistEdit object
    var $xmlfile;           // filename for XML file to edit
    var $files;             // array of filenames to consider for editing
    var $basepath;          // location of the base of the Publist installation
    var $publistini;        // which user ini file for publist should be used
    var $admin_config;      // loaded and parse ini file
    var $action = '';       // the action ('add', 'edit' etc) from action triage
    var $next_action = '';  // the *next* action that should be performed

    var $DEBUG = 0;        // verbosity level of the class (0..10)

    // Methods:

    ################################################
    ///// Constructor for Publist Admin class:
    // $basepath    string  path to publist installation (if current directory use ".")
    // $files       mixed   array of xml filenames (or one file), as for Publist class
    // $publistini  string  path & filename of ini file with which to initialise the Publist class
    // $adminini    string  path & filename of ini file with which to initialise this class
    // Both ini files are located relative to $basepath
    function PubAdmin ($basepath, $files, $publistini='', $adminini='') {
        // make sure the path ends with the directory separator (/ on unix)
        if (substr($basepath, -1) != DIRECTORY_SEPARATOR) {
            $basepath .= DIRECTORY_SEPARATOR;
        }
        $this->basepath   = $basepath;
        $this->publistini = $publistini;
        // make sure that $files is an array (but also permit the user to
        // specify an individual xml file  just as "p.xml", not array("p.xml").
        if (! is_array($files)) {
            $files = array($files);
        }
        $this->files      = $files;
        //load the config data in a two-step ini file routine: default + user
        $this->load_config($adminini);
        // This class has to do a lot of error handling with the file
        // operations, so most of these operations will be prefixed with @ to
        // suppress ugly errors. By setting the php.ini option for track_errors,
        // the error messages are available in the magic global $php_errormsg.
        // This can then be used for more elegant and graceful error handling.
        ini_set('track_errors', true);
        // work out what XML file should be edited (if one has been specified)
        $this->set_xmlfile();
        // start the action triage: work out what action should follow this one
        $this->set_actions();
        // the fields to be edited are defined in the field ini file, the
        // PubEdit class does the hard work.
        $this->create_editor();
    }

    ################################################
    ///// Start the form for data input, including extra state data hidden
    // within the form. the extra info embedded includes:
    //    next_action      the next step in the normal workflow for the editing process
    //                     (i.e. what happens when "submit" is hit by the user)
    //    xmlfile          the xml file that is being edited at the moment
    function form_start () {
        echo '<form enctype="multipart/form-data" method="post" '
            .'action="'.$_SERVER['SCRIPT_NAME'].'" id="publistform">';
        echo '<input type="hidden" name="action" value="'.$this->next_action.'" />';
        if (isset($this->xmlfile)) {
            echo '<input type="hidden" name="xmlfile" value="'.$this->xmlfile.'" />';
        }
    }

    ################################################
    ///// close the form for data input
    // in principle there could be a lot of extra information, some error
    // handling or even a copyright statement emitted from here too
    function form_stop () {
        echo "</form>";
        $this->link_home();
    }

    ################################################
    ///// Show the user the actions that are available, e.g. "Add new ref", "Edit ref"
    // Each action is hyperlinked with an appropriate URL that will bring the PubAdmin
    // object into that execution path of the action triage.
    function show_actions () {
        // if we don't know what xml file to edit, then we can't do anything other than
        // choose which xml file to edit.
        if (isset($this->xmlfile)) {
            // store the links in an array, join them together at the end
            $links = array();
            $links[] = $this->make_link('Add New', array('action'=>'add'));
            $links[] = $this->make_link('Edit',    array('action'=>'edit'));
            // only give the option to change which xml file to edit if there is more than 1
            if (count($this->files) > 1) {
                $links[] = $this->make_link('Change XML File', array('action'=>'selectxml'));
            }
            // if the user has set an xmllinter to check the validity of the xml file then give that option
                if (isset($this->admin_config['Content']['xml_lint_local']) &&
                $this->admin_config['Content']['xml_lint_local']) {
                $links[] = $this->make_link('Validate XML file (local)', array('action'=>'xmllint'));
            }
            // if the user has set an xmllinter to check the validity of the xml file then give that option
            if (isset($this->admin_config['Content']['xml_lint_w3c']) &&
                    $this->admin_config['Content']['xml_lint_w3c']) {
                $xmlfile = dirname($_SERVER['SCRIPT_NAME'])."/$this->basepath".$this->xmlfile;
                // the w3c validator can check that the XML is well formed even if it can't validate
                // that the right fields are in each field
                $links[] = '<a href="http://validator.w3.org/check?uri='.
                    'http://'.$_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'].$xmlfile
                    .'">Validate XML file (w3c)</a> ';
            }
            // finally, dump the links out.
            echo '<div class="publistactions">[ '
            .join($links, ' | ')
            ." ] </div>\n";
        }
    }

    ################################################
    ///// Perform the "action" that is requested by the user. The "action" is set either by
    // the value of an action="verb" entry in the URL string or an action field in the POST data.
    // See the preamble to the PubAdmin class definition for how the action triage works with
    // the typical workflow of editing or adding references.
    function perform_action () {
        if (! $this->action) {
            $this->log('No action');
            return;
        }

        $this->log('action: '.$this->action);
        $ref = $this->user_data('ref');
        // record the success or otherwise of the action being performed, report on it later
        $status = false;
        // triage the action -- in most cases to the PubEdit object that will perform the
        // actual grunt work
        switch ($this->action) {
        case 'selectxml':
            $status = $this->select_xml_form();
            break;
        case 'add':
            $status = $this->listedit->add_reference_form();
            break;
        case 'added':
            $status = $this->listedit->add_reference();
            break;
        case 'edit':
            // create the base link by which the link to select which reference of edit will be made
            $this->listedit->editlink = $this->make_link('Edit',
                                 array('action' => 'editref'),
                                 array('ref' => '%s'));
            $status = $this->listedit->edit_reference_select();
            break;
        case 'editref':
            $status = $this->select_edit_action($ref);
            break;
        case 'editfield':
            $status = $this->listedit->edit_reference_form($ref);
            break;
        case 'editedfield':
            $status = $this->listedit->edit_reference($ref);
            break;
        case 'editbibtex':
            $status = $this->listedit->edit_file_form($ref, 'bibtex');
            break;
        case 'editbibtexn':
            $status = $this->regenerate_bibtex($ref);
            $status = $status && $this->listedit->edit_file_form($ref, 'bibtex');
            break;
        case 'editedbibtex':
            $status = $this->listedit->edit_file($ref, 'bibtex');
            break;
        case 'editabstract':
            $status = $this->listedit->edit_file_form($ref, 'abstract');
            break;
        case 'editedabstract':
            $status = $this->listedit->edit_file($ref, 'abstract');
            break;
        case 'editfiles':
            $status = $this->listedit->edit_uploads_form($ref);
            break;
        case 'editedfiles':
            $status = $this->listedit->edit_uploads($ref);
            break;
        case 'xmllint':
            $status = $this->xml_lint();
            break;
        default:
            $this->log("Undefined action: '$this->action'");
            break;
        }
        if (! $status) {  //error condition
            echo '<div class="error">'
            .'Action could not be successfully completed.<br /><div class="errormsg">';
            if (isset($this->listedit)) {
                echo preg_replace("/\n/", '<br />', $this->listedit->status_message);
            }
            echo '</div>Sorry things didn\'t work out for you... please have another go.'
            .'</div>';
        } else {
            if (preg_match('/^(edited.+|added)/', $this->action)) {
                $this->select_edit_action($this->listedit->key);
            }
        }
    }

    ################################################

//**********************************************
//          private methods follow
//**********************************************

    ################################################
    ///// Work out what the current 'action' is and the next 'action' should be
    // See preamble to this class for more information about the action triage
    function set_actions () {
        //echo "<pre>"; print_r($_GET); echo "</pre>";
        //echo "<pre>"; print_r($_POST); echo "</pre>";
        //echo "<pre>"; print_r($_FILES); echo "</pre>";

        // work out what action we are being asked to perform this time
        if (isset($_GET['action'])) {
            $this->action = $_GET['action'];
        } elseif (isset($_POST['action'])) {
            $this->action = $_POST['action'];
        } else {
            $this->action = '';
        }

        // if the xml file is not selected, then the first thing that has to be done is
        // decide what xml file is to be edited.
        if (! isset($this->xmlfile)) {
            $this->action = 'selectxml';
        }

        // work out what action should follow this one based on the logic flow of the different
        // operations that one could do.
        switch ($this->action) {
        case 'selectxml':
            $this->next_action = '';
            break;
        case 'add':
        case 'added':
            $this->next_action = 'added';
            break;
        case 'edit':
            $this->next_action = 'editref';
            break;
        case 'editref':
            $this->next_action = 'editref';
            break;
        case 'editfield':
        case 'editedfield':
            $this->next_action = 'editedfield';
            break;
        case 'editbibtex':
        case 'editedbibtex':
            $this->next_action = 'editedbibtex';
            break;
        case 'editbibtexn':
            $this->next_action = 'editedbibtex';
            break;
        case 'editfiles':
        case 'editedfiles':
            $this->next_action = 'editedfiles';
            break;
        case 'editabstract':
        case 'editedabstract':
            $this->next_action = 'editedabstract';
            break;
        default:
            $this->next_action = '';
            break;
        }
        $this->log("this action: ".$this->action);
        $this->log("next action: ".$this->next_action);
    }

    ################################################
    ///// print a form to let the user select which of the XML files to edit
    function select_xml_form() {
        echo '<div class="pubadminquestion">Which publication database do you want to edit?</div>'
            .'<ul class="xmllist">';
        foreach ($this->files as $fn) {
            echo '<li>'.$this->make_link($fn, array('xmlfile' => $fn)).'</li>';
        }
        echo '</ul>';
        return true;
    }

    ################################################
    ///// print a form to let the user select what edit action should be performed
    function select_edit_action($key) {
        echo '<div class="pubadminquestion">What do you want to do to this publication?</div>';
        if ($this->action == 'editref') {
            echo '<div class="editpub"><p>Reference '.$key.':</p>';
            $this->listedit->publist->print_from_key($key, array('dir_prefix' => $this->basepath));
            echo '</div>';
        }

        if ($this->listedit->fieldini['Edit_Methods']['fields'])
            $actions['editfield'] = 'Edit XML fields';
        if ($this->listedit->fieldini['Edit_Methods']['abstract'])
            $actions['editabstract'] = 'Edit abstract file';
        if ($this->listedit->fieldini['Edit_Methods']['bibtex'])
            $actions['editbibtex'] = 'Edit BibTeX file';
        if ($this->listedit->fieldini['Edit_Methods']['bibgen'])
            $actions['editbibtexn'] = 'Regenerate BibTeX file';
        if ($this->listedit->fieldini['Edit_Methods']['uploads'])
            $actions['editfiles'] = 'Upload files';
        $links = array();
        foreach ($actions as $act => $name) {
            $links[] =  $this->make_link($name, array('action' => $act, 'ref' => $key));
        }
        echo '<div class="publistactions">[ '.(join($links, ' | ')).' ]</div>';
        return true;
    }

    ################################################
    ///// find some data from GET or POST
    // $field   string   name of the field to be returned from the user supplied data
    // $default string   (optional) return value if user-supplied data is not present for $field
    function user_data ($field, $default='') {
        if (isset($_GET[$field])) {
            $this->log("Found $field in GET data: '".$_GET[$field]."'");
            return urldecode($_GET[$field]);
        } elseif (isset($_POST[$field])) {
            $this->log("Found $field in POST data: '".$_POST[$field]."'");
            return $_POST[$field];
        } else {
            $this->log("Didn't find $field");
            return $default;
        }
    }

    ################################################
    ///// Make a link with sufficient GET data in it to maintain the state of the system
    // $text           string  user selectable text
    // $get            array   list of field => value to be included in the GET string after encoding
    // $get_unencoded  array   list of field => value to be included in the GET string without encoding
    function make_link ($text, $get='', $get_unencoded='') {
        // if selected, the xml file to use should be maintained from one instance to the next
        if (! isset($get['xmlfile'])) {
            $get['xmlfile'] = $this->xmlfile;
        }
        // we might also be passed data that should be explicitly include, with urlencoding
        if (is_array($get)) {
            foreach ($get as $f => $v) {
            $url[] = $f.'='.urlencode($v);
            }
        }
        // and other data that should be included, but without urlencoding (e.g. for an sprintf)
        if (is_array($get_unencoded)) {
            foreach ($get_unencoded as $f => $v) {
            $url[] = $f.'='.$v;
            }
        }
        // assemble the actual linnk using the script name and the data we have assembled
        return '<a href="'.$_SERVER['SCRIPT_NAME'].'?'.join($url, '&amp;').'">'.$text.'</a>';
    }

    ################################################
    ///// find out which of the XML files should we be editing
    function set_xmlfile () {
        // has a filename been specified?
        $filename = $this->user_data('xmlfile');
        // if an xml file has not yet been specified but there is only one passed in the constructor
        // then that's the one we should use
        if (empty($filename) && count($this->files)==1) {
            $filename = $this->files[0];
        }
        // if the file has been either specified or calculated from the default value then
        // we are able to use this XML file
        if (! empty($filename)) {
            $this->xmlfile = $filename;
        }
    }

    ################################################
    ///// Instantiate the PubEdit object if everything is ready to go
    function create_editor () {
        // Do we have a file to work on?
        if (! empty($this->xmlfile)) {
            $sort = ($this->action == 'edit') ? 'type' : 'unsorted';
            $this->listedit = new PubEdit($this->basepath, $this->xmlfile, $sort,
                          $this->admin_config, $this->publistini);
        }
    }

    ################################################
    ///// generate a new bibtex file from the XML data
    // $ref    string    reference key for which the bibtex data should be regenerated
    function regenerate_bibtex($ref) {
        $this->log("Regenerating BibTeX file for $ref");
        $pub = $this->listedit->get_pub_by_key($ref);
        return $pub->create_bibtex_file($this->listedit->publist->config,
                        array('dir_prefix' => $this->basepath));
    }

    ################################################
    ///// load the default config file and the user specified config file
    // $inifile    string    filename to load (relative to $basepath) for admin classes
    function load_config($inifile) {
        // first, try to read the global .ini file for the admin class
        $globalini = dirname(__FILE__) . DIRECTORY_SEPARATOR . "publistadmin.ini";
        if (isset($globalini) && is_readable($globalini)) {
            $this->admin_config = parse_ini_file($globalini, true);
            $configured = true;
        }

        // then, read the local config file, if possible, and add to the global values
        if (isset ($inifile) && is_readable ($this->basepath.$inifile)) {
            $tmp = parse_ini_file ($this->basepath.$inifile, true);
            foreach ($tmp as $section_name => $section) {
            if (isset ($this->admin_config[$section_name])) {
                $this->admin_config[$section_name] =
                array_merge ($this->admin_config[$section_name], $tmp[$section_name]);
            } else {
                $this->admin_config[$section_name] = $tmp[$section_name];
            }
            }
            $configured = true;
        }
        if (! $configured) {
            die ("Critical error: cannot find any readable configuration file in $globalini or $inifile\n");
        }
    }

    ################################################
    ///// Run the defined XML linter over the current XML file
    // Linter defined and enabled by configuration paramter: [Content][xml_lint_local]
    // in publistfields.ini
    // NOTE: This function requires an external XML linter program such as xmllint
    // from the libxml2 package. If it is not present, then messy error messages will be
    // dumped back at the user.
    //
    function xml_lint() {
        $status = true;
        if (isset($this->admin_config['Content']['xml_lint_local']) &&
            $this->admin_config['Content']['xml_lint_local']) {
            echo "Running XML linter '".$this->admin_config['Content']['xml_lint_local']."' "
            ." on file <code>$this->xmlfile</code> "
            ."(this may take some time)...<br />\n";
            $output = array();
            exec($this->admin_config['Content']['xml_lint_local']." $this->basepath$this->xmlfile 2>&1",
             $output, $lint_status);
            echo "... linter finished.<br /><br />\n";
            if ($lint_status == 0) {
            echo '<p class="results">XML linter returned status OK (status=0).'
                ." This means that <code>$this->xmlfile</code> parses as correct XML.</p>";
            return true;
            } else {
            echo '<div class="results"><p>XML linter returned non-zero'
                .' status (status="'.$lint_status.'"). </p>'
                .' <p> This usually means that there is an error in '
                ." your XML file <code>$this->xmlfile</code>. "
                .' <b>You should not use these Publist Admin utilities to add or edit references'
                .' until your XML file is corrected.</b> You will probably have to do this by hand with'
                .' a text editor; the error messages below might give you a clue as to where to look.'
                .' Note that error messages that refer to the last line of the file normally mean that'
                .' a tag earlier in the file was not closed properly'
                .' (e.g. <code>&lt;key&gt;anon2005&lt;/kez&gt;</code> or '
                .' <code>&lt;key&gt;anon2005&lt;key&gt;</code>)'
                .'</p><p>The XML linter said:<pre class="lintoutput">';
            // must protect < and > in output else it will look very garbled:
            echo preg_replace(array('/</',   '/>/'),
                      array('&lt;', '&gt;'), join($output, "\n"));
            echo '</pre></div>';
            return false;
            }
        }
        return $status;
    }

    ################################################
    ///// Include name of program and link home
    // Enabled by configuration paramter: [Content][show_version] in publistfields.ini
    function link_home() {
            if (isset($this->admin_config['Content']['show_version']) &&
                ! $this->admin_config['Content']['show_version'])
            return;

        print "<div class='publistcredit'>"
            ."Publication administration for "
            .Publist::get_link_home()
            ."</div>\n";
    }

    ################################################
    ///// logger function
    // $message   string   data to be logged within the html output stream
    // $priority  integer  verbosity level of the data (higher numbers more verbose)
    function log ($message, $priority=10) {
            if ($priority <= $this->DEBUG) {
            echo '<div class="log">'.$message.'</div>'."\n";
        }
    }

}

?>
