<?php
/*
 *  PubType: Object for Publication types
 *  Copyright 2005--2014 by Eitan Frachtenberg (publist@frachtenberg.org)
 *  This program is distributed under the terms of the GNU General Public License
 */

// PubType class defines the properties of a specific publication type (e.g.,
// journal publication, conference, etc.). It also defines, in conjunction with
// PubConfig, how each publication should be formatted, based on its type.

// if publications don't have a year (e.g. "in press" or "submitted" then assume a year in the future:
define('PUBLIST_FUTURE_YEAR',    3000);
// if publications don't have a month then assume the end of the year
define('PUBLIST_FUTURE_MONTH',     12);

class PubType {
    var $name = "";     // Short name of type (as appears in XML field)
    var $header = "";   // Heading (presented string) of type
    var $priority = 10; // Priority in type sorting: lower comes first
    var $glob_with = "";    // Name of other type to glob this one with
    var $document = ""; // What to call the link to document
    var $slides = "";   // What to call the link to slides
    var $format = "";   // Formatting string
    var $bibtex = "";   // How to call this type in BibTeX output

//////// PubType methods:

    ################################################
    ///// Constructor for PubType
    function PubType ($name, $head, $pri=10, $glob='', $doc='', $pres='', $form='', $bibtex = "") {
        $this->name      = $name;
        $this->header    = $head;
        $this->priority  = $pri;
        $this->glob_with = $glob;
        $this->bibtex    = $bibtex;
        $this->document  = ($doc)?  $doc  : "Paper";
        $this->slides    = ($pres)? $pres : "Presentation";
        $this->format    = ($form)? $form : $this->default_format();
    }

    ################################################
    ///// Return a default format string for type
    // Note: format array cannot have more than one publication attribute
    // %attrib% per array element, although an attribute may be repeated.
    function default_format () {
        $elems = array ('%new% ',
            '<span class=\'author\'>%author%</span>. ',
            '<span class=\'title\'>%title%</span>. ',
            'In ',
            '<span class=\'editor\'>%editor%</span> (eds.): ',
            '<span class=\'booktitle\'>%booktitle%</span>',
            '<span class=\'volume\'> %volume%</span>',
            '(</span class=\'number\'>%number%</span>)',
            ': <span class=\'pages\'>%pages%</span>',
            ', <span class=\'address\'>%address%</span>',
            ', <span class=\'date\'>%date%</span>.',
            '<span class=\note\'> %note%</span>.');

        return implode ($elems, '|');
    }

    ################################################
    ///// get_name: Return name of type
    function get_name () {
        return $this->name;
    }

    ################################################
    ///// get_document: Return slides string of type
    function get_slides () {
        return $this->slides;
    }

    ################################################
    ///// get_document: Return document string of type
    function get_document () {
        return $this->document;
    }

    ################################################
    ///// get_bibtex: Return bibtex type name of type
    function get_bibtex () {
        return $this->bibtex;
    }

    ################################################
    ///// get_priority: Return priority of type
    function get_priority () {
        return $this->priority;
    }

    ################################################
    ///// get_header: Return heading title of type.
    // If this type is globbed with another, it is assumed that the header
    // here points to (or copies) the other type's header.
    function get_header () {
        return $this->header;
    }

    ################################################
    ///// Format an author/editor list
    // Receives author names field from Pubalication class, formatting data

    function format_names ($authors, &$config, $presenting='') {
        $ret = "";  // Return value
        $authors_num = count ($authors);
        $counter = 0;
        $sep = $config->get ("Content", "author_separator", ", ");
        $lsep = $config->get ("Content", "last_author_separator", " and ");
        $format = $config->get ("Content", "author_format", "%FN% |%MI%. | %LN%");
        $presentfmt = $config->get ("Formatting", "presenting_author", "%s");

        if (is_numeric($presenting)) {
            $presenting = $authors[$presenting-1];
        }
        foreach ($authors as $a) {
            // Break to first and last names and remove dots from initials:
            $names = preg_split ("/[, ]/", $a, -1, PREG_SPLIT_NO_EMPTY);
            $names = preg_replace ("@\.@", "", $names);
            $last = array_shift ($names);
            $first = array_shift ($names);
            $namstr = "";

            // Now deal with format string for each author:
            $elems = explode ('|', $format);
            foreach ($elems as $elem) {
                if (!preg_match ('/\%(\w+)\%/', $elem, $matches)) {
                    // No attribute string: use plaintext
                    $namstr .= $elem;
                } else {
                    $attrib = strtolower ($matches[1]);
                    if ($attrib == "fn" && $first) {
                    $namstr .= preg_replace ("/%$attrib%/i", $first, $elem);
                    } elseif ($attrib == "fi" && $first) {
                    $namstr .= preg_replace ("/%$attrib%/i", substr ($first, 0, 1), $elem);
                    } elseif ($attrib == "ln" && $last) {
                    $namstr .= preg_replace ("/%$attrib%/i", $last, $elem);
                    } elseif ($attrib == "li" && $last) {
                    $namstr .= preg_replace ("/%$attrib%/i", substr ($last, 0, 1), $elem);
                    } elseif ($attrib == "mn" || $attrib == "mi") {
                        foreach ($names as $n) {
                            if ($attrib == "mi")
                                $n = preg_replace ("/(\w)\w*/", "$1", $n);
                            $namstr .= preg_replace ("/%$attrib%/i", $n, $elem);
                        }
                    } else {
                        die ("Invalid author format string element $elem or missing name part in $a\n");
                    }
                }
            }

            // Done with author, deal with seperator:
            $counter++;
            if ($counter > 1 && $counter <= $authors_num)
                $ret .= ($counter < $authors_num)? $sep : $lsep;
            if ($a == $presenting && ! empty($presenting))
                $namstr = sprintf($presentfmt, $namstr);
            $ret .= $namstr;
        }
        return $ret;
    }

    ################################################
    ///// format_new: Decide whether a publication is new
    // (i.e., within the last $new_months months),
    // and if so, prepend a small image to the publication listing (based on $config)
    // Receives publication's $data associative array and configration class

    function format_new (&$data, &$config) {
        $now = localtime (time(), 1);
        $abs_now = $now["tm_year"] * 12 + $now["tm_mon"];
        $month = (isset ($data["month"]) && $data["month"])?
            $data["month"] : PUBLIST_FUTURE_MONTH;  // no-month is in the future
        $pub_year = (isset ($data["year"]) && is_numeric($data["year"]))
            ? $data["year"] : PUBLIST_FUTURE_YEAR;
        $abs_me = (($pub_year) - 1900) * 12 + $month - 1;

        $new_months = $config->get ("Content", "new_months", 3);
        return ($abs_me >= $abs_now - $new_months)?
            $config->get ("Content", "new_cmd")
            : "";
    }

    ################################################
    ///// format_date: format a string date (or 'To Appear' if in future)
    // Receives publication's $data associative array and configration class

    function format_date (&$data, &$config) {
        $ret = "";
        $dname = $mname = $yname = '';
        $now = localtime (time(), 1);
        $abs_now = $now["tm_year"] * 12 + $now["tm_mon"];

        if (isset ($data["date"]) && $data["date"]) {
            $dname = int($data["day"]);
        }
        if (isset ($data["month"]) && $data["month"]) {
            $month = $data["month"];
            $mname = $config->get_month_name ($month);
        } else {
            $month = PUBLIST_FUTURE_MONTH+1;    // Make sure it's in future
        }
        if (isset ($data["year"]) && $data["year"] != "") {
            $year = $data["year"];
            $yname = " $year";
        } else {
            $year = PUBLIST_FUTURE_YEAR;
        }

        $abs_me = ($year - 1900) * 12 + $month - 1;
        if ($abs_me > $abs_now) {   // Make sure we're in future
            $ret .= $config->get ("Content", "future_date");
        }
        return $ret . "$dname" . "$mname" . "$yname";
    }

    ################################################
    ///// Format a publication based on format string.
    // Receives publication fields in $data, configuration data in $config
    // Returns an HTML string with the actual pub attributes
    // Tries to match each presentation element with a publication attribute:
    // - If there's no attribute, the element is copied as is (plaintext)
    // - If the attribute is 'author' or 'editor', special formatting is used.
    // - If the attribute is 'new', A "new.gif" command is formatted (see format_new())
    // - If there is one or more field attribute, they're treated as conditions for
    //   presenting the presentation element (all conditions must be satisified):
    //   > for a %field%, the field must be defined in the pub to show;
    //   > for a %-field%, the field must be defined, but it will not be shown (silent)
    //   > for a %!field%, the field must be undefined for the element to be shown.
    // So for example the element "%!a%(%b%)%-c%" will show "(b)", but only if the
    // a field is undefined and the b, c fields are defined in $pub.

    function format_pub (&$data, &$config, $overrides=array()) {
        $elems = explode ('|', $this->format);
        $formstr = "";      // Return string
        $matches = array(); // Temp storage

        // Loop over presentation elements
        foreach ($elems as $elem) {
            $show_element = true;           // Should we present this element?
            $elemstr = $elem;                   // The output string for this element

            while (preg_match ('/%([\!\-]?)(\D\w*)%/', $elemstr, $matches)) {
                $mod = $matches[1];     // Modifiers on field (! or -)
                $fld = strtolower($matches[2]);     // The field in pub we want to address/replace with
                $known = (isset ($data[$fld]) && ($data[$fld] || $data[$fld] === 0));  // Is this field defined?
                $attrib = "";       // What we replace this field with

                            // First determine what we replace with, and is the condition satisfied:
                switch ($fld) {
                    // "new" and "date" are always satisified:
                    case "new": $attrib = $this->format_new ($data, $config);  break;
                    case "date": $attrib = $this->format_date ($data, $config);  break;
                    case "author":
                        if (isset($data['presenting'])) {
                            $presenting = $data['presenting'];
                        }  // no 'break;' as the rest is shared with "editor"
                    case "editor":
                        $presenting = isset($presenting) ? $presenting : '';
                        $attrib = $known? $this->format_names ($data[$fld], $config, $presenting) : "";
                        $show_element &= ($mod != "!")? $known : !$known;
                        break;
                    case 'pages':
                        $attrib = $known? preg_replace ('/-{1,2}/', '&#8211;', $data[$fld]) : ""; // Nicer en-dash
                        $show_element &= ($mod != "!")? $known : !$known;
                        break;
                    default:        // All normal cases: find field in publication:
                        $attrib = $known? $data[$fld] : "";
                        $show_element &= ($mod != "!")? $known : !$known;
                        break;
                }       // End of attrib/show_element switch

                if (!$show_element) {       // This will silence the element entirely.
                    $elemstr = "";
                    break;
                } else {            // We want to show this element:
                    if ($mod == "-" || $mod == "!") // silent fields
                    $attrib = "";
                    $elemstr = preg_replace ('/%[\!\-]?\D\w+%/i', $attrib, $elemstr, 1); // Get field data
                }
            }   // regexp loop (replaceable fields in an element)
            $formstr .= $elemstr;
        }       // Elements loop
        return $formstr;
    }
}
