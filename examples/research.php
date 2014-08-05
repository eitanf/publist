<!-- Copyright 2014- Eitan Frachtenberg. See http://frachtenberg.org/eitan/publist-->
<html>
<head>
   <title>Research Interests</title>
</head>
<body bgcolor="#FCFFD5">
<link rel="stylesheet" type="text/css" href="publist.css">


<?php 
require "publist.php";
$pubs = new Publist(array('pubs.xml'), "date", 'macros.dat');
?>

<h1>Research Interests</h1>

<p><br>
Here are some of the areas and projects I was involved with:
<br><p>



<h2>Parallel Job Scheduling</h2><p>
<a href="http://www.cs.huji.ac.il/~feit">Dr. Dror Feitelson</a> from&nbsp;
<a href="http://www.cs.huji.ac.il/">the CS dept. at Hebrew University</a>
brought me into the field of supercomputing, with his vast knowledge and
meticulous research methods. In our work for my MSc thesis, we
developed <a href="pubs/papers/frachtenberg03:FCS.pdf">a new method of coscheduling</a> 
for supercomputers to deal with
load imbalances. This work has been extended to a PhD.
In this work, we've shown ways to enhace large-scale systems by improving
application performance, improve fault-tolerance, reduce system load, and improve resource utilization.
<br>Related publications:<br>
<ul><font size=-1>
<?php $pubs->print_select ("area", "Parallel Job Scheduling"); ?>
</font></ul>

<h2>Scalable system software and STORM</h2><p>
One of the main research questions our team is trying to address is how to develop scalable, high-performance system software. As part of this effort,
we developed and advanced resource management tool, called STORM (Scalable TOol for Resource 
Management). This environment was measured to have unprecedented performance in typical resource-management tasks such as
job launching in large clusters. STORM is also an excellent platform for studying, implementing and
evaluating various job scheduling algorithms, and many of these are already incorporated in STORM. 
<br>STORM and system software related publications:<br>
<ul><font size=-1>
<?php $pubs->print_select ("area", "Resource Management"); ?>
<?php $pubs->print_select ("area", "System Software"); ?>
</font></ul>

<h2>QsNet</h2><p>
Our research team has focused extensively on studying and tinkering with the 
<a href="http://www.quadrics.com/">Quadrics</a> QsNet interconnect. This is a very 
high-bandwidth/low-latency interconnect that is used in several of the fastest supercomputers worldwide. 
<br>QsNet-related publications:<br>
<ul><font size=-1>
<?php $pubs->print_select ("subarea", "QsNet"); ?>
</font></ul>

<h2>Network Protocols</h2><p>
We have investigated and proposed several network protocols for advanced networks that offer multiple 
rails, that is, a redundancy of networks (interfaces and switches). Multiple rails allow for increased network performance, but hard to exploit efficiently with current bus technology. The papers in this list try to address this with by static or dynamic allocation of rails to messages:<br>
<ul><font size=-1>
<?php $pubs->print_select ("subarea", "Protocols"); ?>
</font></ul>


<hr WIDTH="100%">


</body>
</html>
