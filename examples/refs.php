<!-- Copyright 2003-2014 Eitan Frachtenberg publist@frachtenberg.org -->

<html>
<head>
<title>References Page</title>
</head>
<body bgcolor="#FCFFD5">
<link rel="stylesheet" type="text/css" href="publist.css">


<?php 
require "publist.php";
$pubs = new Publist(array('pubs.xml'), $_GET['sort'], 'macros.dat');
?>
<!-- Change reference style numbering to square brackets: -->
<style>
ol { counter-reset: list; }
ol > li { list-style: none; }
ol > li:before { content: "[" counter(list) "] "; counter-increment: list; }
</style>

<h1>References Page</h1>
This page demonstrates how you can use <a href="http://frachtenberg.org/eitan/publist/">Publist's</a>
reference mode. You can insert in your page simple PHP commands that look very similar to
LaTeX/BibTeX's \cite commands.<br>
For example, adding this command: <tt>cite("frachtenberg02:STORM")</tt> would create a bracketed first reference,
like here: <?php $pubs->cite("frachtenberg02:STORM") ?>. <br>
You can then add more cite commands, even with multiple comma-seperated reference keys, like
<tt>cite("fernandez05:predictable, petrini02:qsnet-micro")</tt>, 
which will appear as <?php $pubs->cite("fernandez05:predictable, petrini02:qsnet-micro") ?>.
If you repeat a previous key like in this example: 
<tt>cite("frachtenber03:dynamic,frachtenberg02:STORM , petrini05:reduce ")</tt>,
publist will  use the right citation number, as shown here: 
<?php $pubs->cite("frachtenberg03:dynamic,frachtenberg02:STORM , petrini05:reduce ") ?>.<p>
Evenutally, when you wish to show the list of references, you simply call another function,
<tt>print_refs()</tt>, which shows below:


<h3>References</h3>
<OL>
<?php $pubs->print_refs(); ?>
</OL><p>

</body>
</html>

