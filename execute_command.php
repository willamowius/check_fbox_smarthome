<html>
<body>
<?php

# this is an action_url drop-in replacement for the nagios webif's action_url feature.
# 
# it should be called only locally and should always come directly from the nagios webif (ie. http_referrer)


# (c) 2014,2017 by Frederic Krueger / fkrueger-dev-checkfboxsmarthome@holics.at
#
# Licensed under the Apache License, Version 2.0.
# There is no warranty of any kind, explicit or implied, for anything this software does or does not do.
#
# Updates for this piece of software could be available under the following URL:
#   GIT:   https://github.com/fkrueger-2/check_fbox_smarthome
#   Home:  http://dev.techno.holics.at/check_fbox_smarthome/

# ip the nrpe server we are calling is running on
$ip_nrpe = "127.0.0.1";

# "authentication", ie. client must have at least httpreferer and (remote address or http via)
$re_remoteaddr = '127.0.0.1';		# use (ip|hostname|whatever) for more than one address
$re_httpreferer = '\/(extinfo|status)\.cgi';
$re_httpvia = 'yourwebserverhostname\.local:3128 \(squid';


# our way to find check_nrpe
$bin_nrpe = "/usr/lib/nagios/plugins/check_nrpe";
if (!file_exists ($bin_nrpe)) { $bin_nrpe = "/usr/lib64/nagios/plugins/check_nrpe"; }
if (!file_exists ($bin_nrpe)) { $bin_nrpe = "/srv/nagios/libexec/check_nrpe"; }

if (!file_exists($bin_nrpe))
{
  print "<h1>Error!</h1>\n";
  print "<h2>Couldn't find check_nrpe binary.<br/>\n";
  print "Please provide path info in " .basename(__FILE__). "</h2>\n";
}

else

{

  setlocale(LC_CTYPE, "en_US.UTF-8");

  $webargs = array();
  
  # use the next command to find out reasonably good safeguards for making sure only the webserver can talk to nrpe securely:
  #print_r ($_SERVER);
  
  $httpref = (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : "";
  $httpremadr = (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : "";
  $httpvia = (isset($_SERVER['HTTP_VIA'])) ? $_SERVER['HTTP_VIA'] : "";
  
  if (
      (preg_match("/$re_httpreferer/", $httpref)) and ( (preg_match("/$re_remoteaddr/", $httpremadr)) or (preg_match("/$re_httpvia/", $httpvia)) )
     )
  {
    // get data from $_GET
    if (isset($_GET['cmd'])) { $webargs['cmd'] = $_GET['cmd']; }
    for ($i=1; $i<= 9; $i++)
    {
      if (isset($_GET["arg$i"])) { $webargs["arg$i"] = $_GET["arg$i"]; }
    }
    
    // now sanitize input data
    reset ($webargs);
    while (list($k,$v) = each($webargs))
    {
      # print "webargs $k: orig '" .$webargs[$k]. "'<br/>\n";
      $webargs[$k] = preg_replace("/[^\.0-9a-zA-Z\-\+_\*]*/", "", $webargs[$k]);
      # print "webargs $k: sani '" .$webargs[$k]. "'<br/>\n";
    }
  
    $str="";
    for ($i=1; $i <= 9; $i++)
    {
      $str .= ((isset($webargs["arg$i"])) and ($webargs["arg$i"] != "")) ? $webargs["arg$i"] ." " : "";
    }
    print "Calling nrpe like this:<br/>\n";
    print "<code>$bin_nrpe -H $ip_nrpe -c " .$webargs['cmd']. " -a $str</code><br/>\n";
    print "<br/>\n";
    print "Output is as follows:<br/>\n";
    print "<pre>";
    system ("$bin_nrpe -H $ip_nrpe -c " .$webargs['cmd']. " -a $str");
    print "</pre><br/>\n";
  } // end if looks like it s coming from the nagios webif
  
  else // it is from some scumbag trying to turn off my power ;-)
  
  {
  ?>
    <h1>&lt;Engineer&gt; Nope.</h1>
    <center><iframe title="YouTube video player" width="425" height="269" src="http://www.youtube.com/embed/gvdf5n-zI14" frameborder="0" allowfullscreen></iframe></center>
  <?php
  } # end if "auth" failed
  
} # end if couldn't find check_nrpe

?>
</body>
</html>
