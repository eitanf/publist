<?php
/*
 *  Publication object, handles an individual publication
 *  Copyright 2003--2014 by Eitan Frachtenberg (publist@frachtenberg.org)
 *  This program is distributed under the terms of the GNU General Public License
 */

require_once 'pubconfig.php';

// Publication class contains all pertinent information of an individual publication
// Most of the data is contained as text in the hash array $data.
// Additionally, a publication has a unique key, and a type, of class PubType.

class Publication {
    var $key       = '';       // identifier, such as ipdps03
    var $type      = '';       // of class PubType
    var $data      = array();  // Associative array with all publication-specific data fields, such as:
    // A few of the fields that might be found in $data:
    // author     // Array of names, e.g. "Frachtenberg, Eitan and Feitelson, Dror G."
    // editor     // Array of names, same format as authors
    // title      // Title of publication
    // booktitle  // Publication-place name including URL's
    // address    // Geographical place of conference (if applicable)
    // month      // 1-12
    // volume     // Volume (of journal)
    // number     // Number (of journal)
    // pages      // Pages of proceedings/journal
    // year       // Four digit number
    // area       // Field of publication
    // team       // boolean: is a team publication
    // subarea    // Lower-level classification or project name
    // note       // Optional text with HTML directives
    // annote     // Optional additional description of publications

    // Methods:

    ################################################
    ///// Constructor from field-array and configuration data

    function Publication (&$aa, &$config) {
        foreach ($aa as $korig=>$v) {
            $k = strtolower($korig);
            $s = str_replace ("[[", '<', $v);
            $s = str_replace ("]]", '>', $s);
            switch ($k) {
            case "key":
                $this->data[$k] = $s;
                $this->key = $s; break;
            case "type":
                $this->data[$k] = $s;
                $this->type = $config->get_type ($s); break;
            case "authors":
            case "author":
                $this->data["author"] = explode (" and ", $s); break;
            case "editors":
            case "editor":
                $this->data["editor"] = explode (" and ", $s); break;
            case "team":
                $this->data["team"] =
                    (strtolower($s) != 'false');   //any value other than 'false' is 'true'
                break;
            case "month":
                $this->data["month"] = 0 + $s; break;   // force to be a number
            case "notes":
            case "note":
                $this->data["note"] = preg_replace('@<br>@', '<br />', $s);   //xhtml compliance for <br/>
                break;
            default:
                $this->data[$k] = $s;
            }
        }
    }

    ################################################
    ///// get: get a specific data field or $default if undefined
    function get ($field, $default=NULL) {
        if (isset ($this->data[$field])) {
            if ($this->data[$field] == "")
                return $default;
            else
                return $this->data[$field];
        }
        if ($field == "author" || $field == "editor")
            return array ($default);
        else return $default;
    }

    ################################################
    ///// print_pub: Display publication:
    // Receives a configuration class with formatting info
    // Pretty-print fields
    // Call type to format data fields
    // Add links to available files
    //
    // $config    PubConfig object    configuration data used to format the reference
    // $overrides array (optional)    manually override some formatting (for use by PubAdmin)
    function print_pub (&$config, $overrides=array()) {
        $format =& $config->get_formatting($overrides);

        echo $format['itemstart']."\n";
        if (isset ($format['linkid'])) {
            echo '<a id="ref'.$format['linkid'].'"></a>';
        }
        echo $this->type->format_pub ($this->data, $config, $overrides);
        $this->print_links ($config, $overrides);
        echo $format['itemstop']."\n";
    }

    ################################################
    ///// Strip a string of embedded HTML code:
    //    Used to write plaintext to bibtex entries

    function strip ($str) {
        $search = array ('@<script[^>]*?>.*?</script>@si', // Strip out javascript
                 '@<[\/\!]*?[^<>]*?>@si',          // Strip out HTML tags
                 '@([\r\n])[\s]+@',                // Strip outwhite space
                 '@&(quot|#34);@i',                // Replace HTML entities
                 '@&(amp|#38);@i',
                 '@&(lt|#60);@i',
                 '@&(gt|#62);@i',
                 '@&(nbsp|#160);@i',
                 '@&(iexcl|#161);@i',
                 '@&(cent|#162);@i',
                 '@&(pound|#163);@i',
                 '@&(copy|#169);@i',
                 '@&#(\d+);@e',
                 '@<a=.*>(.*)</a>@i');                    // evaluate as php

        $replace = array ('',
                  '',
                  '\1',
                  '"',
                  '&',
                  '<',
                  '>',
                  ' ',
                  chr(161),
                  chr(162),
                  chr(163),
                  chr(169),
                  'chr(\1)',
                  '\\1');

        $ret = preg_replace ($search, $replace, $str);
        return $ret;
    }

    ################################################
    ///// format_file_type: return the (recognized) file type in parenthesis,
    // along with the filesize. Receives configuration data and a file name to analyze.
    function format_file_type (&$config, $filename) {
        $ret = "";

        $ext = substr (strrchr ($filename, '.'), 1);
        $ftype = $config->get_file_type ($ext);
        if ($ftype)
            $ret .= $ftype;
        $stat = stat ($filename);
        if ($stat["size"] > 10240) {
            if ($ret) $ret .= " ";
            $ret .= ($stat["size"] >> 10) . "KB";
        }

        if ($ret)
            return "&nbsp;(" . $ret . ")";
        else
            return "";
    }

    ################################################
    ///// format_link: Add hyperlink to a given directory/file, if it's available and readable
    // Receives the directory to look for the file in, as well as a name to call the link.
    // returns a string of the link if a file was found, empty string otherwise.
    //
    // Also see publist.ini for the special syntax ("xml:") that allows XML data to be
    // directly included on the links bar.
    //
    // $fileclass string              fileclass name for config object lookup
    // $linkname  string              used as the hypertext for the link
    // $config    PubConfig object    configuration data used to format the reference
    // $overrides array (optional)    manually override some formatting (for use by PubAdmin)
    function format_link ($fileclass, $linkname, &$config, $overrides=array()) {
        $format = $config->get_formatting($overrides);
        $url = '';
        $filesource = true;
        // Look for files if this field is not filled from the XML file
        if (! $config->is_file_xml_link($fileclass)) {
            $dirprefix = isset($format['dir_prefix']) ? $format['dir_prefix'] : '';
            $dir = $config->get_file_dir($fileclass);
            if (substr($dir, -1) != DIRECTORY_SEPARATOR) $dir .= DIRECTORY_SEPARATOR;
            $files = glob ($dirprefix . $dir . $this->key . "*");
            // Only generate a link if file already exists
            // or we're looking for bibtex files and it can be created successfully
            if ( (isset ($files[0]) && is_readable ($files[0]))
             || ($fileclass == 'bibtex' && $files[0] = $this->create_bibtex_file($config, $overrides))
            ) {
                $helper = $config->get_file_helper($fileclass, $files[0]);
                if ($helper) {
                    $url = sprintf($helper, rawurlencode($this->key));
                } else {
                    // rawurlencode converts / to %2F but apache2 does not match that back to /
                    // when processing the request (apache 1.3 does, however).
                    $url = str_replace('%2F', '/', rawurlencode($files[0]));
                }
            }
        } else {   // source the data from the XML file
            #print $config->get_file_xml_link($fileclass)."<br />";
            $url = $this->get($config->get_file_xml_link($fileclass), '');
            $fmt = $config->get_file_xml_link_format($fileclass);
            // Encode bad characters within the URL but only if it is an absolute url that is specified
            if (preg_match('@\w{3,6}:@', $fmt)) $url = str_replace('%2F', '/', rawurlencode($url));
            if ($url !== '') $url = sprintf($fmt, $url);
            $filesource = false;
        }
            // And obtain the text to use for the link
            if (! $config->is_file_xml_text($fileclass)) {
            $text = $linkname;
        } else {
            $text = $this->get($config->get_file_xml_text($fileclass), $fileclass);
        }
        // only create the link if something was found to link to
        if ($url !== '') {
            if ($fileclass == 'abstract') {
                return "<details class='abstract'><summary>$linkname</summary>"
                . file_get_contents($files[0]) . "</details>";
            } else {
                return "<a class='download' data-filename='$url' href='$url'>$text</a>"
                . ($filesource ? $this->format_file_type ($config, $files[0]) : '');
            }
        } else {
            return "";
        }
    }

    ################################################
    ///// print_links: Add hyperlinks to abstract, bibtex, paper, etc
    // if they are available and readable.
    // Uses configuration data to determine which files are to be linked to:
    //    [Meta_Files]::order  defines what file classes to use (and in what order)
    //    [Files_*]            define text for links and file locations etc
    //
    // $config    PubConfig object    configuration data used to format the reference
    // $overrides array (optional)    manually override some formatting (for use by PubAdmin)
    function print_links (&$config, $overrides=array()) {
        $format =& $config->get_formatting($overrides);

        // First store all links' HTML code in $links:
        $links = array();
        if (isset($format['extralink']))
            $links[] = sprintf($format['extralink'], rawurlencode($this->key));

        // Iterate through all the file classes available and produce links to the files
        foreach ($config->get_files() as $fileclass) {
            // the link text that will be used
            $name = $config->get_file_name($fileclass);
            // check for special names for some documents (names that vary with the publn type)
            switch ($name) {
            case '__document__':
                $name = $this->type->get_document(); break;
            case '__slides__':
                $name = $this->type->get_slides();   break;
            }
            // now assemble all the available information into a link if the file actually exists
            // (if bibtex files don't exist, format_link will try to create them)
            if ($lnk = $this->format_link ($fileclass, $name, $config, $overrides)) {
                $links[] = $lnk;
            }
        }

        // Now, output links:
        if (count ($links)) {
            echo $format['linkstart']
            .join($links, $format['linkseparator'])
            .$format['linkstop']."\n";
        }
    }

    ################################################
    ///// print_file: print an associated file (e.g. abstract) for this ref, if available
    // in a pretty way.
    // $fileclass string              the class of file to find (abstract, bibtex etc from Files_* in ini)
    // $config    PubConfig object    configuration data used to format the reference
    // $overrides array (optional)    manually override some formatting (for use by PubAdmin)
    function print_file ($fileclass, &$config, $overrides=array()) {
        $format =& $config->get_formatting($overrides);
        $dirprefix = isset($format['dir_prefix']) ? $format['dir_prefix'] : '';
        $dir = $config->get_file_dir($fileclass);
        if (substr($dir, -1) != DIRECTORY_SEPARATOR) $dir .= DIRECTORY_SEPARATOR;
            $files = glob ($dirprefix . $dir . $this->key . "*");
        if (isset ($files[0]) && is_readable ($files[0])) {
                $filecontents = join(file($files[0]));
            if (preg_match('@\.txt$@', $files[0])) {
                $filecontents = '<pre class=abstract>'.$filecontents.'</pre>';
            }
            if (preg_match('@\.html*$@', $files[0])) {
                $filecontents = '<div class=abstract>'.$filecontents.'</div>';
            }
            print $filecontents;
        } else {
            print "Sorry, I couldn't find the file you were looking for.\n";
        }
    }

    ################################################
    ///// create_bibtex_entry: Create a BibTeX entry for a given publication key
    // Returns string with BibTeX entry
    function create_bibtex_entry() {
        $ret = "";  // Return value
        $months = array('1' => "JAN", "FEB", "MAR", "APR", "MAY", "JUN",
        "JUL", "AUG", "SEP", "OCT", "NOV", "DEC");

        //  Type string for publication:
        $ret .= $this->type->get_bibtex();
        if ($ret == "") {       // No bibtex type for this
            return $ret;
        }
        $ret .= '{'.$this->key . ",\n";

        // Author list:
        $first = true;
        $ret .= "\tauthor =\t{";
        foreach ($this->get ("author") as $author) {
            if ($first) {
                $first = false;
            } else {
                $ret .= " and ";
            }
            $ret .= $this->strip ($author);
        }
        $ret .= "},\n";

        // Title:
        $ret .= "\ttitle = \t\"{" . $this->strip ($this->get ("title")) . "}\",\n";

        // Booktitle:
        $btitle = $this->strip ($this->get ("booktitle"));
        if ($btitle != "") {
            if ($this->type->get_name() == "journal" || $this->type->get_name() == "periodical") {
                $ret .= "\tjournal =\t{" . $btitle . "},\n";
            } else {
                $ret .= "\tbooktitle =\t{" . $btitle . "},\n";
            }
        }

        // volume, number, pages, address: (all conditional on existence)
        if ($this->get ("volume") != "") {
            $ret .= "\tvolume =\t{" . $this->get ("volume") . "},\n";
        }
        if ($this->get ("number") != "") {
            $ret .= "\tnumber =\t{" . $this->get ("number") . "},\n";
        }
        if ($this->get ("pages") != "") {
            $ret .= "\tpages =\t{" . $this->get ("pages") . "},\n";
        }
        if ($this->get ("address") != "") {
            $ret .= "\taddress =\t{" . $this->strip ($this->get ("address")) . "},\n";
        }

        // Month and year:
        if ($this->get ("month") != "") {
            $ret .= "\tmonth =\t{$months[$this->get ("month")]},\n";
        }
        $ret .= "\tyear =\t{" . $this->get ("year") . "},\n";

        // Notes:
        if ($this->get ("note") != "") {
            $ret .= "\tnote =\t{" . $this->strip ($this->get ("note")) . "},\n";
        }
        if ($this->get ("annote") != "") {
            $ret .= "\tannote =\t{" . $this->strip ($this->get ("annote")) . "},\n";
        }

        $ret .= "}\n";
        return $ret;
    }

    ################################################
    ///// create_bibtex_file: Create a BibTeX file for a given publication key
    // Returns true iff successful
    // $config    PubConfig object    configuration data used to format the reference
    // $overrides array (optional)    manually override some formatting (for use by PubAdmin)
    function create_bibtex_file(&$config, $overrides) {
        $entry = $this->create_bibtex_entry();
        if ($entry == "")
            return false;

        $format = $config->get_formatting($overrides);
        $basepath = isset($format['dir_prefix']) ? $format['dir_prefix'] : '';
        $filename = $basepath . $config->get_file_dir('bibtex') . DIRECTORY_SEPARATOR
        . $this->key . '.bib';
        $f = @fopen ($filename, "w");
        if (!$f || !fwrite ($f, $entry))
            return false;
        fclose ($f);
        @chmod ($filename, 0644);  // note: this will fail if user created files not publist

        return $filename;
    }


    ################################################
    ///// get_header: Return heading title of publication, based on sort field
    function get_header ($sort) {
        switch ($sort) {
        case "type":    return $this->type->get_header();
        case "date":    return $this->get ("year");
        case "author":  $a = $this->get ("author"); return $a[0];
        case "editor":  $e = $this->get ("editor"); return $e[0];
        default:    return $this->get ($sort);
        }
    }

////////// BOOLEAN QUERY FUNCTIONS ///////////////////

    ################################################
    ///// is_match: return true IFF a given value is a case-insensitive substring of a field
    function is_match ($value, $field) {
        return (!strcasecmp ($this->get ($field), $value));
    }

    ################################################
    ///// is_team: return true IFF publication is marked for team page
    function is_team () {
        return (($this->get ("team") != "")? $this->get ("team") : false);
    }

    ################################################
    ///// is_author: return true IFF an author (or regexp pattern) matches the author list
    function is_author ($pattern) {
        return preg_grep ("@$pattern@i", $this->get ("author"));
    }

////////// SORTING/COMPARISON FUNCTIONS ///////////////////
// All functions return:
// Negative if $this < $other
// Positive if $other > $this
// Zero if publications are equal

    ################################################
    //// compare_generic: Compare (numerically or alphabetically)
    // to another publication by any given field
    function compare_generic ($other, $field) {
        $x = $this->get ($field, 0);
        $y = $other->get ($field, 0);

        if (is_numeric ($x) && is_numeric ($y))
            return $x - $y;
        else
            return strcasecmp ($x, $y);
    }

    ################################################
    //// compare_date: return -1 iff publication $this is older than $other
    // Treats future publications as the youngest (month is 13)
    function compare_date ($other) {
        $ret = 0;

        $my_day = $this->get ("day", 32);
        $my_month = $this->get ("month", 13);
        $my_year = $this->get ("year");
        $other_day = $other->get ("day", 32);
        $other_month = $other->get ("month", 13);
        $other_year = $other->get ("year");

        if ($my_year < $other_year)
            $ret = -1;
        elseif ($my_year > $other_year)
            $ret = 1;
        elseif ($my_month != $other_month)
            $ret = $my_month - $other_month;
        else
            $ret = $my_day - $other_day;

        return $ret;
    }

    ################################################
    //// compare_authors: Alphanumeric comparison of first authors' last name
    function compare_authors ($other) {
        $x = $this->get ("author");
        $y = $other->get ("author");
        if (!$x)
            return ($y)? -1 : 0;
        if (!$y)
            return 1;
        return (strcasecmp ($x[0], $y[0]));
    }

    ################################################
    //// compare_area: Alphanumeric comparison of publication area
    function compare_area ($other) {
        return (strcasecmp ($this->get ("area"), $other->get ("area")));
    }

    ################################################
    //// compare_type: compare the priority of publication's types
    function compare_type ($other) {
        // Ordering function for publication type. Lower is higher-priority
        return ($this->type->get_priority() - $other->type->get_priority());
    }

}

?>
