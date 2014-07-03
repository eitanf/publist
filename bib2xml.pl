#!/usr/bin/env perl
# Convert BibTeX files to Publist's XML format (http://publist.sf.net/)
#
# This script tries to parse BibTeX file(s) (provided as command line 
# parameters) and produce one file in XML format (on standard output) 
# that conforms to Publist's XML input format.
# This script will complain about anything it doesn't understand, but will 
# continue to output unparsed lines in HTML comment format, to be edited 
# in-place
# Output is left at the same order as the input.
# Note - if your BibTeX depends on additional files (e.g., macros), make sure
# to include them first in your input order. This script will try to deal
# with @Strings and will also try to convert months to the proper numeric value.
# 
# Limitations:
# 
# - This script cannot tell the difference between conferences and
# workshops or journal and periodical (Publist makes this distinction). If you
# want to make that distinction yourself, go over the output file and change
# the type as appropriate.
# - You may want to edit notes, area, and subarea manually.
# BibTeX Doesn't know posters and talks - these again have to be added manually.
# - There's an attempt to translate URLs correctly, but it's not too general.
# - A bug in Text::BibTex might result in a "parse_ok" error message. In that
# case, some entries, the closing "</pubDB>" may not appear in the output
# - There seems to be a bug in Text::BibTex that causes it to fail on the 
#   second entry of a .bib file. If this script complains that it cannot
#   convert the second bibtex entry, try inserting a dummy entry in your .bib
#   file, right after the first one.
#
# Requires package Text::BibTeX
#    (http://search.cpan.org/~gward/Text-BibTeX-0.34/BibTeX.pm)
#
# Copyright 2005--2014 by Eitan Frachtenberg (publist@frachtenber.org) -- BETA status --
# This program may be freely distributed under the terms of the GNU Public License
###############################################################################

use Text::BibTeX qw(:macrosubs);

## Globals:
%Months = ( 'jan' =>  1, 'jan.' =>  1, 'january' =>   1, 
            'feb' =>  2, 'feb.' =>  2, 'february' =>  2,
            'mar' =>  3, 'mar.' =>  3, 'march' =>     3,
            'apr' =>  4, 'apr.' =>  4, 'april' =>     4,
            'may' =>  5, 'may.' =>  5, 'may' =>       5,
            'jun' =>  6, 'jun.' =>  6, 'june' =>      6,
            'jul' =>  7, 'jul.' =>  7, 'july' =>      7,
            'aug' =>  8, 'aug.' =>  8, 'august' =>    8,
            'sep' =>  9, 'sep.' =>  9, 'september' => 9,
            'oct' => 10, 'oct.' => 10, 'october' =>  10,
            'nov' => 11, 'nov.' => 11, 'november' => 11,
            'dec' => 12, 'dec.' => 12, 'december' => 12
          );
%Types = ( 'inproceedings' => 'conference',
           'article'       => 'journal',
           'inbook'        => 'chapter',
           'book'          => 'book',
           'techreport'    => 'report',
           'phdthesis'     => 'thesis',
           'mastersthesis' => 'thesis'
         );
    
$tab = 0;
$spacing = 2;    # Indentation level of XML

#################################################################
############################# Routines ##########################

#################################################################
# clean: remove quotes, braces, and runs of spaces from a string
# Tries to handle some special HTML characters.
# Also tries to handle URLs nicely, and to remove "Proceedings of.."

sub clean {
  @ret = ();
  foreach (@_) {
    s/\\\&/\&amp;/g;              # Ampersands
    s/\\w*{(.*?)}/$1/g;           # BibTeX braces and formatting
    s/[ ]+/ /g;                   # Runs of spaces
    s/Proceedings of the //gi;    # Proceedings
    s/Proceedings of //gi;
    s/Proc\. //gi;
    s/\\\"(.)/\&amp;$1uml\;/g;    # Translate umlaut
    s/\\\'(.)/\&amp;$1acute\;/g;  # Translate acute
    s/\\\`(.)/\&amp;$1grave\;/g;  # Translate grave
    s/\</\&lt;/g;                 # <
    s/\>/\&gt;/g;                 # >
    s/(?<!\/)\~/ /g;              # ~, but not after /

    s/\\url\{(.*?)\}/[[a href="$1"]]$1\[[\/a]]/g;  # URLs
    if (/href=/) {
      if (!/http:\/\// && !/ftp:\/\//) {
        s/href=\"/href="http:\/\//g;
      }
    }

    s/[\{\}]//g;          # Just braces
    push @ret, $_;
  }
  return join (' ', @ret);
}


#################################################################
# open_entry: start a publication, tab to the right

sub open_entry {
  my $entry = shift;
  print ' 'x$tab . "<publication>\n";
  $tab += $spacing;
  print ' 'x$tab . "<key>" . $entry->key . "</key>\n";
}

#################################################################
# close_entry: end a publication, tab to the left


sub close_entry {
  print ' 'x$tab . "<area></area>\n";
  print ' 'x$tab . "<subarea></subarea>\n";
  print ' 'x$tab . "<invited>false</invited>\n";
  print ' 'x$tab . "<islocal>true</islocal>\n";
  $tab -= $spacing;
  print ' 'x$tab . "</publication>\n\n";
}

#################################################################
# get_names: format and list of names to an output string
# Receives entry and field name

sub get_names {
  my $entry = shift;
  my @names = $entry->names(shift);
  my $first = 1;
  my $ret = "";
  
  foreach $name (@names) {
    if ($first) { $first = 0; } else { $ret .= " and "; }
    my $v = &clean ($name->part('von'));
    $ret .= $v . " " if ($v ne "");
    $ret .= &clean ($name->part('last'));
    my $j = &clean ($name->part('jr'));
    $ret .= (" " . $j) if ($j ne "");
    my $f = &clean ($name->part('first'));
    $ret .= (", " . $f) if ($f ne "");
  }
  return $ret;
}

#################################################################
# print_fields: outputs all of bibtex's fields in XML format,
# except it translates key, journal, note, and type. 
# Has special cases for thesis, author, and editor

sub print_fields {
  my $entry = shift;
  my @fields = $entry->fieldlist();
  my $ftype, $fval;

  foreach $field (@fields) {
    $ftype = $field;
    $fval = &clean($entry->get($field));

    if ($field eq 'key') {
      $ftype = 'bibtex-key';
    } 
    elsif ($field eq 'type') {
      $ftype = 'bibtex-type';
    } 
    elsif ($field eq 'journal') {
      $ftype = 'booktitle';
    } 
    elsif ($field eq 'author') {
      $fval = &get_names ($entry, 'author');
    } 
    elsif ($field eq 'editor') {
      $fval = &get_names ($entry, 'editor');
    } 

    print ' 'x$tab . "<$ftype>$fval</$ftype>\n";
  }

# Handle theses:
  if ($entry->type eq "phdthesis") {
    print ' 'x$tab . "<booktitle>";
    print "Ph.D. dissertation, " . $entry->get('school');
    print "</booktitle>\n";
  }
  elsif ($entry->type eq "mastersthesis") {
    print ' 'x$tab . "<booktitle>";
    print "Master's thesis, " . $entry->get('school');
    print "</booktitle>\n";
  }
}

#################################################################
# print_type: print the type of the entry in XML format
# Assumes type has already been validated to be one of Publist's
# supported types.

sub print_type {
  my $entry = shift;

  print ' 'x$tab . "<type>";
  print $Types{lc $entry->type};
  print "</type>\n";
}

#################################################################
# output_entry: print out all entry details

sub output_entry {
  my $entry = shift;

  open_entry ($entry);
  print_type ($entry); 
  print_fields ($entry);
  close_entry ($entry);
}

#################################################################
# output_unknown: dump the bibtext entry to the output as comment
# It will also clean the entry and convert '--' to '-'

sub output_unknown {
  my $entry = shift;

  print "<!-- I cannot parse the following BibTeX entry:\n";
  my $str = &clean ($entry->print_s());
  $str =~ s/--/-/g;
  print ($str);
  print "-->\n\n";
}

#################################################################

sub toxml {
  my $entry = shift;

  if ($entry->type eq "string") {
    return;   # Ignore strings
  } elsif (($entry->type eq "inproceedings")
        || ($entry->type eq "article") 
        || ($entry->type eq "inbook") 
        || ($entry->type eq "book") 
        || ($entry->type eq "techreport") 
        || ($entry->type eq "phdthesis") 
        || ($entry->type eq "mastersthesis")) {
    output_entry ($entry);
  } else {
    output_unknown ($entry);
  }
}

########################################## MAIN ###############################

&add_macro_text ($macro, $value)
  while (($macro, $value) = each %Months);

print "<pubDB>\n\n";
&Text::BibTeX::bibloop (sub {&toxml(shift);}, \@ARGV);
print "\n\n</pubDB>\n";
