#!/usr/bin/env php
<?php

# vim: softtabstop=2 tabstop=2 shiftwidth=2 expandtab

# Nagios plugin for the Fritz!Box DECT 200 smart-power devices (and other "Smart Home" devices by AVM)
#
# (c) 2014-2018 by Frederic Krueger / fkrueger-dev-checkfboxsmarthome@holics.at
#
# Licensed under the Apache License, Version 2.0
# There is no warranty of any kind, explicit or implied, for anything this software does or does not do.
#
# Updates for this piece of software could be available under the following URL:
#   GIT:   https://github.com/fkrueger-2/check_fbox_smarthome
#   Home:  http://dev.techno.holics.at/check_fbox_smarthome/
#
# Based on / Inspired by two of the scripts found at: http://www.tdressler.net/ipsymcon/fritz_aha.html

$cfgname = "/etc/nagios/check_fbox_smarthome.cfg";

$DEBUG = (0==1);


## TODO remove your associated .rrd and .xml files in your pnp4nagios install, if you upgraded from 0.0.3 to 0.0.4 !!!
##
## TODO add support for more (or more general) support of the AHA API (until check_nwc_health gets
##      around to adding decent fritz!box support that works for more than one version of the
##      fritz!box ;-))

#### DONT EDIT BELOW HERE (unless you know what you are doing) ####

$minsensortoggletime = 15;      # allow toggling every 15 seconds at the most

$config = array();

# program info
$PROG_NAME     = 'check_fbox_smarthome';
$PROG_VERSION  = '0.0.8';
$PROG_EMAIL    = 'fkrueger-dev-checkfboxsmarthome@holics.at';
$PROG_URL      = 'http://dev.techno.holics.at/check_fbox_smarthome/';



if (file_exists("./utils.php")) { require_once("./utils.php"); }
elseif (file_exists("../utils.php")) { require_once("../utils.php"); }
elseif (file_exists("/usr/lib/nagios/plugins/utils.php")) { require_once("/usr/lib/nagios/plugins/utils.php"); }
elseif (file_exists("/usr/lib64/nagios/plugins/utils.php")) { require_once("/usr/lib64/nagios/plugins/utils.php"); }
elseif (file_exists("/srv/nagios/libexec/utils.php")) { require_once("/srv/nagios/libexec/utils.php"); }


# import stuff from utils.php (create if necessary, see output below)
global $STATE_OK, $STATE_WARNING, $STATE_CRITICAL, $STATE_UNKNOWN, $STATE_DEPENDENT;

if ((!isset($STATE_OK)) or ($STATE_OK != 0))
{
  print "\n";
  print "Error: utils.php not found!\n";
  print "\n";
  print "Please create a file named utils.php into your nagios/icinga plugins directory\n";
  print "and paste the following content into it. Then try running this script again.\n";
  print "\n";
  print "######## utils.php ########\n";
  print '<?php
define ("STATE_OK", 0);
define ("STATE_WARNING", 1);
define ("STATE_CRITICAL", 2);
define ("STATE_UNKNOWN", 3);
define ("STATE_DEPENDENT", 4);

$STATE_OK = STATE_OK;
$STATE_WARNING = STATE_WARNING;
$STATE_CRITICAL = STATE_CRITICAL;
$STATE_UNKNOWN = STATE_UNKNOWN;
$STATE_DEPENDENT = STATE_DEPENDENT;
?>
';
  print "######## /utils.php ########\n";
  print "\n";
  exit(-1);
}





# helpers
function check_for_functions ($funcnames = [])
{
  $func2pkg = array('mb_convert_encoding' => array('php-mbstring', 'php-7.0-mbstring'), 'simplexml_load_string' => array('php-xml', 'php-xml'));
  foreach ($funcnames as $func)
  {
    $functionwasmissing = (0==1);
    if (! function_exists($func))
    {
      $fedorapkgname = $func2pkg[$func][0];
      $debianpkgname = $func2pkg[$func][1];
      print "\n";
      print "Error: Needed PHP function '$func' doesn't exist!\n";
      print "\n";
      print "PHP removed this function from the default PHP installation, so it has to be installed manually.\n";
      print "Try 'yum install $fedorapkgname' or 'apt-get install $debianpkgname', depending on your OS.\n";
      print "\n";
      $functionwasmissing = (0==0);
    }
  }
  if ($functionwasmissing)
  {
    exit(-1);
  }
} # end func error_funcmissing


function usage ($detailed = "")
{
  global $PROG_NAME, $PROG_VERSION, $PROG_EMAIL, $PROG_URL, $cfgname, $STATE_UNKNOWN, $myself;

  print "\nusage: $myself <whattodo> <fboxhosts> <sensornames>\n";
  print "\n";
  print "    <whattodo>     can be 'read', 'toggle', 'switchon', 'switchoff'.\n";
  print "    <fboxhosts>    can be '*' for all or a search pattern on your config's section-names\n";
  print "    <sensornames>  can be '*' for all or a search pattern ('*' can be used for listing all avail. sensors, too)\n";
  print "\n";
  print "This plugin is used to monitor and control AVM Fritz!DECT 200 (and maybe other \"Smart Home\" devices) with Nagios.\n";
  print "\n";

  if ($detailed == "detailed")
  {
    print "### PLUGIN CONFIGURATION FILE\n";
    print "\n";
    print "You need to set up the configuration in file $cfgname .\n";
    print "\n";
    print "##### EXAMPLE CONFIG #####\n";
    print ";; a comment for your fbox with name 'fboxname'
;[fboxname]
;host='ip.or.host.name'
;pw='passwordtouse'
;; minimum time between toggling a switch is 15 seconds (lowest time allowed: 5 seconds)
;mintoggletime='15'
;
;; another fbox named 'fboxname2' (so multiple fboxen are supported)
;[fboxname2]
;host='fboxname2.hostname'
;pw='anotherpasswordtouse'\n";
    print "#####/EXAMPLE CONFIG #####\n\n";

    print "
### NAGIOS COMMANDS DEFINITION
define command{
  command_name  check_fbox_smarthome
  command_line  /usr/bin/php \$USER1\$/contrib/check_fbox_smarthome.php \$ARG1\$ \$ARG2\$ \$ARG3\$
}

### NAGIOS SERVICECHECK DEFINITION
define service{
  use                             local-service
  host_name                       yourhost
  service_description             Smart Home
  check_command                   check_fbox_smarthome!'read'!'*'!'*'

# if you wanna use the following, you can use the accompanying execute_command.php (make sure it's reasonably secure for your environment):
#  notes_url                       /execute_command.php?arg1=toggle&cmd=check_fbox_smarthome&arg2=.*&arg3=.*
# 
# also, you need to set up an nrpe-server providing something like this:
#command[check_fbox_smarttemp] = /usr/bin/php /usr/lib/nagios/plugins/contrib/check_fbox_smarthome.php \$ARG1\$ \$ARG2\$ \$ARG3\$
}

";

    print '### PNP4NAGIOS check_commands configuration and template can be found in the package.
';
  } # end if is detailed usage info

  print "$PROG_NAME v$PROG_VERSION is licensed under the Apache License, Version 2.0 .
There is no warranty of any kind, explicit or implied, for anything this software does or does not do.

The main page for this plugin can be found at: $PROG_URL

(c) 2014-2017 by Frederic Krueger / $PROG_EMAIL
  
";
  exit ($STATE_UNKNOWN);
} # end func usage


function dbgprint ($mode="", $title="", $message="")
{
  global $DEBUG;
  $s = "";

  if ($mode != "") { $s = $s . "($mode) "; }
  if ($title != "") { $s = $s . "\"$title\": "; }
  if ($message != "") { $s = $s . "$message"; }

  if ($DEBUG) { print "$s\n"; }
} # end func dbgprint


function sanitize_for_fname ($str = "")
{
  $str = preg_replace("/ /", "_", $str);
  $str = preg_replace("/[^a-zA-Z\-\+0-9]/", "", $str);
  return ($str);
} # end func sanitize_for_fname


function load_url ($requrl="", $cnt=5)
{
  $nothingfound = (0==0);
  $content = "";
  while (($nothingfound) and ($cnt-- > 0))
  {
    $content = chop(@file_get_contents($requrl));
    if (isset($content)) { $nothingfound = (0==1); }
  }

  if (!isset($content))
  {
    dbgprint ("dbg", "load_url", "No answer for query '$requrl'");
    return (0==0);
  }
  dbgprint ("dbg", "load_url", "Answer gotten for query '$requrl': " .((strlen($content) > 100) ? "big content gotten (" .strlen($content). " bytes)" : "\"$content\""));
  return ($content);
} # end func load_url


function block_toggle_switch ($prefix="", $fboxname="", $sensorname="", $mintoggletime = 0)
{
  if ($mintoggletime < 5) { $mintoggletime = 5; }   # minimum HARD limit
  $rc = (0==0);
  $prefix = sanitize_for_fname ($prefix);
  $fboxname = sanitize_for_fname ($fboxname);
  $sensorname = sanitize_for_fname ($sensorname);

  $fname = "/tmp/${prefix}_${fboxname}_${sensorname}.tmp";
  if (! file_exists($fname))
  {
    $fp = @fopen ($fname, "w");
    @fwrite ($fp, time());
    @fclose($fp);
    $rc = (0==1);
  } # end if no tmpfile exists yet
  else
  {
    $fp = @fopen ($fname, "r");
    $timestamp = @fgets ($fp, 99);
    @fclose ($fp);
    dbgprint ("dbg", "block_toggle_switch", "prefix '$prefix', fboxname '$fboxname', sensorname '$sensorname' => fname '$fname': got timestamp '$timestamp' (ts-time=" .($timestamp-time()). ")");
    if (time() - $timestamp > $mintoggletime)   # toggling is allowed!
    {
      $fp = @fopen ($fname, "w");
      @fwrite ($fp, time());
      @fclose ($fp);
      $rc = (0==1);
    } # end if toggling ok
    else
    {
      $rc = (0==0);
    } # end if toggling not yet ok
  } # end if tmpfile existed
  return ($rc);
} # end func block_toggle_switch


function get_sid ($loginurl = "", $fboxlogin = "", $fboxpw = "")
{

  # get challengestring from login-responsepage
  $http_response = file_get_contents ($loginurl);

  if (preg_match("/<Challenge>(\w+)<\/Challenge>/i", $http_response, $res))
    { $challenge = $res[1]; }
  if (preg_match("/<SID>([\da-f]+)<\/SID>/i", $http_response, $res))
    { $sid = $res[1]; }

  if ((isset($sid)) and (preg_match("/^[0]+$/",$sid)) and (isset($challenge)))
  {
    # sid is null, got challenge
    $sid = "";
    # build password response
    $pass = $challenge ."-". $fboxpw;
    # UTF-16LE encoding as required
    $pass = mb_convert_encoding ($pass, "UTF-16LE");
    # md5hash on top
    $md5 = md5($pass);
    # final answer string
    $challenge_response = $challenge ."-". $md5;
    # send to box
    $url = $loginurl. "?response=" .$challenge_response. ($fboxlogin == "" ? "" : "&username=$fboxlogin");
    $http_response = file_get_contents($url);
    # check answer
    if (preg_match("/<SID>([\da-f]+)<\/SID>/i", $http_response, $res))
    {
      # got answer with sid
      $sid = $res[1];
      if (!preg_match("/^[0]+$/",$sid))  # .. that even looks valid!
      {
        return ($sid);
      }
    }
  }

  return (null);
} # end func get_sid



## taken straight from http://us2.php.net/manual/en/function.simplexml-load-string.php#91564
function unserialize_xml($input, $callback = null, $recurse = false)
/* bool/array unserialize_xml ( string $input [ , callback $callback ] )
* Unserializes an XML string, returning a multi-dimensional associative array, optionally runs a callback on all non-array data
* Returns false on all failure
* Notes:
    * Root XML tags are stripped
    * Due to its recursive nature, unserialize_xml() will also support SimpleXMLElement objects and arrays as input
    * Uses simplexml_load_string() for XML parsing, see SimpleXML documentation for more info
*/
{
    // Get input, loading an xml string with simplexml if its the top level of recursion
    $data = ((!$recurse) && is_string($input))? simplexml_load_string($input): $input;
    // Convert SimpleXMLElements to array
    if ($data instanceof SimpleXMLElement) $data = (array) $data;
    // Recurse into arrays
    if (is_array($data)) foreach ($data as &$item) $item = unserialize_xml($item, $callback, true);
    // Run callback and return
    return (!is_array($data) && is_callable($callback))? call_user_func($callback, $data): $data;
}


function get_actor_infos ($url)
{
  $actorident2infos = array();

  # get all infos the device can give us in one fell swoop:
  $actorxml = load_url ($url. "&switchcmd=getdevicelistinfos");

  dbgprint ("dbg", "get_actor_infos", "url: $url");
  dbgprint ("dbg", "get_actor_infos", "actorxml: $actorxml");

## THIS:
#<devicelist version="1">
#  <device identifier="08761 0123456" id="16" functionbitmask="896" fwversion="03.36" manufacturer="AVM" productname="FRITZ!DECT 200">
#    <present>1</present>
#    <name>Bathroom</name>
#    <switch><state>1</state><mode>auto</mode><lock>0</lock></switch>
#    <powermeter><power>1302120</power><energy>413811</energy></powermeter>
#    <temperature><celsius>231</celsius><offset>-14</offset></temperature>
#  </device>
#  <device identifier="08761 0123457" id="17" functionbitmask="896" fwversion="03.36" manufacturer="AVM" productname="FRITZ!DECT 200">
#    <present>1</present>
#    <name>Livingroom</name>
#    <switch><state>1</state><mode>manuell</mode><lock>0</lock></switch>
#    <powermeter><power>0</power><energy>186759</energy></powermeter>
#    <temperature><celsius>292</celsius><offset>0</offset></temperature>
#  </device>
#  <device identifier="08761 0123458" id="18" functionbitmask="896" fwversion="03.36" manufacturer="AVM" productname="FRITZ!DECT 200">
#    <present>1</present>
#    <name>LivingroomAll</name>
#    <switch><state>1</state><mode>manuell</mode><lock>1</lock></switch>
#    <powermeter><power>204490</power><energy>700239</energy></powermeter>
#    <temperature><celsius>236</celsius><offset>36</offset></temperature>
#  </device>
#</devicelist>

    $actorarr = unserialize_xml ($actorxml);
#    print "actorarr is ''" .print_r ($actorarr, true). "''\n\n";

## IS BEING TURNED (by php) INTO THIS:
#Array
#(
#    [0] => Array
#        (
#            [@attributes] => Array
#                (
#                    [identifier] => 08761 0123456
#                    [id] => 16
#                    [functionbitmask] => 896
#                    [fwversion] => 03.36
#                    [manufacturer] => AVM
#                    [productname] => FRITZ!DECT 200
#                )
#
#            [present] => 1
#            [name] => Bathroom
#            [switch] => Array
#                (
#                    [state] => 1
#                    [mode] => auto
#                    [lock] => 0
#                )
#
#            [powermeter] => Array
#                (
#                    [power] => 1245400
#                    [energy] => 414930
#                )
#
#            [temperature] => Array
#                (
#                    [celsius] => 267
#                    [offset] => -14
#                )
#
#        )
#
#    [1] => Array
#        (
#            [@attributes] => Array
#                (
#                    [identifier] => 08761 0123457
#                    [id] => 17
#                    [functionbitmask] => 896
#                    [fwversion] => 03.36
#                    [manufacturer] => AVM
#                    [productname] => FRITZ!DECT 200
#                )
#
#            [present] => 1
#            [name] => Livingroom
## ...

  if ((isset($actorarr)) and (isset($actorarr['device'])) and (sizeof ($actorarr['device']) > 0))
  {
    for ($i=0; $i <= sizeof($actorarr); $i++)
    {
      if ((isset($actorarr['device'][$i]['@attributes'])) and (isset($actorarr['device'][$i]['name'])))
      {
        $ident = $actorarr['device'][$i]['@attributes']['identifier'];
        $name = $actorarr['device'][$i]['name'];
        if ((isset($ident)) and (isset($name)) and ($ident != "") and ($name != ""))
        {
          dbgprint ("dbg", "get_actor_infos", "found actor $i: '$name' (ident:$ident)");
          $actorident2infos[$ident] = $actorarr['device'][$i];
        }
      }
    }
  } # end if got actors returned

  return ($actorident2infos);
} # end func get_actor_infos



function do_fbox_command ($fboxname="", $fboxhost="", $fboxlogin="", $fboxpw="", $sensornames="", $cmd = "")
{
  global $myself, $perfdata, $minsensortoggletime, $config, $STATE_CRITICAL, $STATE_UNKNOWN;
  $rc = true;

  # api urls
  $loginurl  = "http://" .$fboxhost. "/login_sid.lua";
  $logouturl = "http://" .$fboxhost. "/home/home.lua?logout=1";
  $ahaurl    = "http://" .$fboxhost. "/webservices/homeautoswitch.lua";

  # login
  $sid = get_sid ($loginurl, $fboxlogin, $fboxpw);

  # set up logout url here
  $logouturl = $logouturl . "&sid=$sid";

  if (!isset($sid))
  {
    dbgprint ("dbg", "Fritz-Login", "Login failed");
    print "CRITICAL - Logon to Fbox on host '$fboxhost' (section $fboxname) failed\n";
    exit ($STATE_CRITICAL);
  }

  # load all actor infos first
  $actorident2infos = get_actor_infos ("$ahaurl?sid=$sid");
  
  if (sizeof($actorident2infos) < 0)
  {
    dbgprint ("dbg", "Fritz-GetActors", "No actors found for section $fboxname");
    load_url ($logouturl);
    print "UNKNOWN - No actors found for section $fboxname'\n";
    exit ($STATE_UNKNOWN);
  }

  $ains = array_keys ($actorident2infos);
  usort ($ains, "strcmp");

  # get actor data via provided ain above
  foreach ($ains as $ain)
  {
    $actorinfo = $actorident2infos[$ain];
    $url = $ahaurl ."?sid=$sid&ain=$ain";

    $name = ((isset($actorinfo['name'])) ? $actorinfo['name'] : "");
    if ((!isset($name)) or ($name == "")) { continue; }
    if (!preg_match ("/$sensornames/", $name)) { continue; }

    if (($cmd == "toggle") or ($cmd == "switchon") or ($cmd == "switchoff"))
    {
      $localmintoggletime = $config[$fboxname]['mintoggletime'];
      if ((!isset($localmintoggletime)) or ($localmintoggletime == "")) { $localmintoggletime = $minsensortoggletime; }
      if (! block_toggle_switch ($myself, $fboxname, $name, $localmintoggletime))
      {
        $webcmd = "";
        if ($cmd == "toggle")
          { $webcmd = ($actorinfo['switch']['state'] > 0) ? "setswitchoff" : "setswitchon"; }
        elseif ($cmd == "switchoff")
          { $webcmd = "setswitchoff"; }
        elseif ($cmd == "switchon")
          { $webcmd = "setswitchon"; }

        if ($webcmd != "")
        {
          dbgprint ("dbg", "toggle", "$fboxname:$ain> doing $webcmd");
          load_url ($url ."&switchcmd=$webcmd");
        }
      } # end if toggleswitch is not blocked any more
      else
      {
        print "UNKNOWN - Changing power state of $fboxname:$name not yet allowed.\n";
      }

      # wait for 500ms to allow the box to update (250ms is not enough for the slowness that is fritz!box -.-)
      usleep (500000);

      # then update the data, since we did some toggling
      $actorident2infos = get_actor_infos ($url);
      $actorinfo = $actorident2infos[$ain];
    } # end if cmd == toggle


    # ie. 193 => 19.3 deg Celsius
    $temperature     = floor($actorinfo['temperature']['celsius']) / 10;
    # either 0 or 1
    $switchstatus    = (floor($actorinfo['switch']['state']) > 0 ? 1 : 0);
    # 207990 / 1000 = 207.990 W
    $currentusage    = floor($actorinfo['powermeter']['power']) / 1000;
    # 700128 / 1000 = 700.128 kWh
    $totalenergyused = floor($actorinfo['powermeter']['energy']) / 1000;

    dbgprint ("dbg", "do_fbox_command", "Before preparing output, have name '$name': temp '$temperature' - status '$switchstatus' - curr '$currentusage' - total '$totalenergyused'");

    if ( (!isset($name)) or (!isset($switchstatus)) or (!isset($currentusage)) or (!isset($totalenergyused)) )
    {
      dbgprint ("dbg", "get_data", "Got no reply for some of the fields in section $fboxname.. Skipping the rest.");
      continue;
    }

    # prepare output
    if ( (empty($temperature)) and ($temperature !== "0") ) # "0" equals "" and !isset.. yeah right, i know why i prefer perl
    {
      dbgprint ("dbg", "get_data", "No usable data gotten for some of the fields in section $fboxname.");
      continue;
    }

    # store data for later output
    dbgprint ("dbg", "got_data", "$name: " .(($switchstatus == "1") ? "on" : "off"). " is at $temperature deg Celsius: Power actual: $currentusage W, power total: $totalenergyused kWh");

    if (!isset($perfdata[$fboxname]))
      { $perfdata[$fboxname] = array(); }

    if (!isset($perfdata[$fboxname][$ain]))
    {
      $perfdata[$fboxname][$ain] = [ 'name' => $name, 'currentusage' => $currentusage, 'totalenergyused' => $totalenergyused, 'switchstatus' => $switchstatus, 'temperature' => $temperature ];
    }
    else
    {
      dbgprint ("dbg", "got_data", "Got duplicate entry for ain '$ain' in section $fboxname.. Skipping data.");
    }

  } # end foreach ain found in actors list

  load_url ($logouturl);

  return ($rc);
} # end func do_fbox_command





#
### MAIN
#

check_for_functions (array("mb_convert_encoding", "simplexml_load_string"));


# load configuration, if exists
if (file_exists($cfgname))
{
  $statinfo = stat ($cfgname);
  if (sizeof($statinfo) > 8)
  {
    $fmode = sprintf("0%o", 0777 & $statinfo['mode']);
    $omode = substr($fmode, -1, 1);
    if (($omode & 4) == 4)
    {
      print "CRITICAL - Configuration file with unsafe permissions ($cfgname has perms $fmode)\n";
      print "\n";
      print "!!! Others can read your stored passwords in the configuration file $cfgname.\n";
      print "    Run `chmod o-rwx $cfgname` to fix this.\n";
      print "\n";
      print "Refusing to run until that one is fixed.\n";
      print "\n";
      exit ($STATE_CRITICAL);
    } # end if abominable others-readable is found on the config file
  } # end if stat worked

  # load config if we are still here.
  $config = parse_ini_file ($cfgname, true);
} # end if cfgname exists


$foundconfiguredfboxhost = (0==1);
if ((isset($config)) and (sizeof($config) > 0))
{
  reset ($config);
  while (list ($fboxname, $fboxinfo) = each ($config))
  {
    if ((isset($fboxname)) and ($fboxname != "") and (isset($fboxinfo['host'])) and ($fboxinfo['host'] != "") and (isset($fboxinfo['pw'])) and ($fboxinfo['pw'] != ""))
    {
      $foundconfiguredfboxhost = (0==0);
    }
  }
}


# if configfile doesn't exist, or the hostname supplied can't be found in the config:
if (! $foundconfiguredfboxhost)
{
  usage("detailed");
  exit ($STATE_UNKNOWN);
}



### init
$ARGV = $_SERVER['argv'];
$myself = $ARGV[0];

# our vars
$cmd = "";
$fboxhosts = "";
$sensornames = "";

$perfdata = array();


### args
if ((isset($ARGV[1])) and (isset($ARGV[2])) and (isset($ARGV[3])))
{
  $cmd = "read";
  if ($ARGV[1] == "toggle") { $cmd = "toggle"; }
  if ($ARGV[1] == "switchon") { $cmd = "switchon"; }
  if ($ARGV[1] == "switchoff") { $cmd = "switchoff"; }
  $fboxhosts = ($ARGV[2] != "") ? $ARGV[2] : "*";
  $sensornames = ($ARGV[3] != "") ? trim($ARGV[3]) : "*";

  if ($fboxhosts == "*") { $fboxhosts = ".*"; }
  if ($sensornames == "*") { $sensornames = ".*"; }
}

dbgprint ("dbg", "args", "cmd: '$cmd', fboxhosts: '$fboxhosts', sensornames: '$sensornames'");


### print usage, if needed or wanted
if (!isset($ARGV[1]))
  { usage(); }
if (($ARGV[1] == "-h") or ($ARGV[1] == "--help"))
  { usage("detailed"); }
elseif (($cmd == "") or ($fboxhosts == "") or ($sensornames == ""))
  { usage(); }

### parse through config sections, looking for the supplied hosts/sensors
reset($config);
while (list($fboxname, $info) = each($config))
{
  if ((isset($fboxname)) and ($fboxname != ""))
  {
    if (preg_match("/$fboxhosts/", $fboxname))    # found matching host
    {
      if (!isset($info['login'])) { $info['login'] = ""; }    # XXX normal fbox login has no login, but secure has.
      if ((isset($info['host'])) and ($info['host'] != "") and (isset($info['pw'])) and ($info['pw'] != ""))
      {
        dbgprint ("dbg", "do_fbox_command", "cmd '$cmd' " .($info['login'] == "" ? "" : "for login '" .$info['login']. "' "). "for host '" .$info['host']. "' (section $fboxname), sensornames '$sensornames'");
        do_fbox_command ($fboxname, $info['host'], $info['login'], $info['pw'], $sensornames, $cmd);
      } # end if got validlooking data in current config entry
    }
  }
}




### now for the nagios output
$nagiosrc = $STATE_UNKNOWN;
$nagiosoutput = "";
$perfdatastring = "";

if (sizeof($perfdata) <= 0)
{
  $nagiosoutput = "UNKNOWN - no sensor data found for host $fboxhosts, sensors $sensornames";
  $nagiosrc = $STATE_UNKNOWN;
} # end if no perfdata gotten

else

{
  # create perfdata string
  reset ($perfdata);
  while (list($fboxname, $info) = each ($perfdata))
  {
    while (list($ain, $data) = each ($info))
    {
      $perfdatastring = $perfdatastring . " ${fboxname}_" .$data['name']. "_actual=" .($data['currentusage']/1000). "kW;;;; ${fboxname}_" .$data['name']. "_total=" .$data['totalenergyused']. "kWh;;;; ${fboxname}_" .$data['name']. "_status=" .$data['switchstatus']. ";;;; ${fboxname}_" .$data['name']. "_temperature=" .($data['temperature']). "C;;;; ";
    } # end parsing fboxhost-data
  } # end parsing fboxhosts


  # TODO add crit/warn values later; will need a full overhaul of how to check which value against which values
  $nagiosrc = $STATE_OK;

  # then the nagios output
  reset ($perfdata);
  while (list($fboxname, $info) = each ($perfdata))
  {
    while (list($ain, $data) = each ($info))
    {
      if ($nagiosoutput == "")  # first line
      {
        $nagiosoutput = $nagiosoutput . "OK - $fboxname:" .$data['name']. "> Sensor " .(($data['switchstatus'] > 0) ? "is active and uses " .$data['currentusage']. "W power" : "is inactive at the moment.") ." |$perfdatastring\n";
      }
      else                      # other lines
      {
        $nagiosoutput = $nagiosoutput . "OK - $fboxname:" .$data['name']. "> Sensor " .(($data['switchstatus'] > 0) ? "is active and uses " .$data['currentusage']. "W power" : "is inactive at the moment.") ."\n";
      }
    } # end parsing fboxhost-data
  } # end parsing fboxhosts
} # end if got perfdata


### and output everything:
print "$nagiosoutput\n";    # includes perfdatastring on first line
exit ($nagiosrc);





?>
