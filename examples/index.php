<!DOCTYPE html>
<html>
<head>
<title>Example Publications Page</title>
<link rel="stylesheet" type="text/css" href="publist.css">
<style>
  body { background-color: #FCFFD5; }
  h1 { font-size: 2.5em; text-align: center; }
</style>
</head>
<body>


<?php 
require "../publist.php";
$pubs = new Publist(array('pubs.xml'), $_GET['sort'], 'macros.dat');
?>


<h1>Publications</h1>
<?php $pubs->show_sorts(); ?>
<?php $pubs->show_jumps('false'); ?>
<hr>
<ol type=1>
<?php $pubs->print_all(); ?>
</ol><p>

</body>
</html>

