#!/usr/bin/env php
<?php

# vim: softtabstop=2 tabstop=2 shiftwidth=2 expandtab

# Nagios plugin for the Fritz!Box DECT 200 smart-power devices, Comet DECT thermostats (and other "Smart Home" devices by AVM)
#
# (c) 2014-2023 The check_fbox_smarthome Authors.
# See the accompanying AUTHORS file for more in-detail information (and email contacts).
#
# Licensed under the Apache License, Version 2.0
# There is no warranty of any kind, explicit or implied, for anything this software does or does not do.
#
# Updates for this piece of software could be available under the following URL:
#   GIT:   https://github.com/fkrueger/check_fbox_smarthome
#   Home:  https://dev.techno.holics.at/check_fbox_smarthome/
#
# Based on / Inspired by two of the scripts found at: http://www.tdressler.net/ipsymcon/fritz_aha.html

$cfgname = "/etc/nagios/check_fbox_smarthome.cfg";
if (!file_exists($cfgname)) { $cfgname = "./check_fbox_smarthome.cfg"; } # fall back to local config file for testing

$DEBUG = false;
$VERBOSE = false;


## TODO remove your associated .rrd and .xml files in your pnp4nagios install, if you upgraded from 0.0.3 to 0.0.4 !!!
##
## TODO add support for more (or more general) support of the AHA API (until check_nwc_health gets
##      around to adding decent fritz!box support that works for more than one version of the fritz!box).

#### DONT EDIT BELOW HERE (unless you know what you are doing) ####

$minsensortoggletime = 15;      # allow toggling every 15 seconds at the most

$loginblocktime = -1;           # lazy way to get more precise "login failed" output
$config = array();

# program info
$PROG_NAME     = 'check_fbox_smarthome';
$PROG_VERSION  = '0.1.2';
$PROG_EMAIL    = 'fkrueger-dev-checkfboxsmarthome@holics.at';
$PROG_URL      = 'https://dev.techno.holics.at/check_fbox_smarthome/';

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

# import stuff from utils.php (create if necessary, see output below)
global $STATE_OK, $STATE_WARNING, $STATE_CRITICAL, $STATE_UNKNOWN, $STATE_DEPENDENT;


# helpers
function check_for_functions ($funcnames = [])
{
  $func2pkg = array('mb_convert_encoding' => array('php-mbstring', 'php-7.0-mbstring'), 'simplexml_load_string' => array('php-xml', 'php-xml'));
  foreach ($funcnames as $func)
  {
    $functionwasmissing = false;
    if (! function_exists($func))
    {
      $fedorapkgname = $func2pkg[$func][0];
      $debianpkgname = $func2pkg[$func][1];
      print "\n";
      print "Error: Needed PHP function '$func' doesn't exist!\n";
      print "\n";
      print "PHP removed this function from the default PHP installation, so it has to be installed manually.\n";
      print "Try 'yum install $fedorapkgname' (or dnf) on RHEL/CentOS/Fedora, 'apt-get install $debianpkgname' on Debian/Ubuntu/Mint/etc., depending on your OS.\n";
      print "\n";
      $functionwasmissing = true;
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

  print "\nusage: $myself [-d] [-h] [--battery-warn-level n] [-o|--use-temp-offset] [--temp-warn-min n] [--temp-warn-max n] [--csv-fpath filepath.csv] <whattodo> <fboxhosts> <sensornames>\n";
  print "\n";
  print "    <whattodo>                           can be 'read', 'csv-log', 'toggle', 'switchon', 'switchoff'.\n";
  print "    <fboxhosts>                          can be '*' for all (default) or a search pattern on your config's section-names\n";
  print "    <sensornames>                        can be '*' for all (default) or a search pattern ('*' can be used for listing all avail. sensors, too)\n";
  print "\n";
  print "    --battery-warn-level                 check: battery warn level of heater control devices (in %) for warn/crit checking (crit is supplied by f!box device configuration)\n";
  print "    --temp-warn-min / --temp-warn-max    check: set temperature sensor allowed min-max range (causes WARN if actual temp value is outside that range)\n";
  print "\n";
  print "    --perfdata-no(batt|power|temp)units  perfdata: do not use units in perfdata for battery, power or temperature perfdata.\n";
  print "    --perfdata-dataonly                  perfdata: only use sensor data in perfdata (but not the warn/crit level or min/max values)\n";
  print "    --csv-fpath                          write perf-data into a continous csv-style logfile, adding one line + timestamp (epoch time) and the recorded data on every run (why? think: postprocessing. I use it for openoffice calc)\n";
  print "    -o / --use-temp-offset               sensor info: apply temperature offset set in fbox for device to temperature sensor value during temp-warn checking\n";
  print "\n";
  print "    -d                                   show debug information\n";
  print "    -h                                   show this help\n";
  print "\n";
  print "This plugin is used to monitor and control AVM Fritz!DECT 200 and Comet DECT (and probably other \"Smart Home\" devices) with Nagios.\n";
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
;login='neededlogin'
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

The main page for this plugin can be found at:  $PROG_URL
The main email contact is here:                 $PROG_EMAIL

(c) 2014-2022 The check_fbox_smarthome Authors.
See the accompanying AUTHORS file for more in-detail information (and email contacts).

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
  $nothingfound = true;
  $content = "";
  while (($nothingfound) and ($cnt-- > 0))
  {
    $content = chop(@file_get_contents($requrl));
    if (isset($content)) { $nothingfound = false; }
  }

  if (!isset($content))
  {
    dbgprint ("dbg", "load_url", "No answer for query '$requrl'");
    return true;
  }
  dbgprint ("dbg", "load_url", "Answer gotten for query '$requrl': " .((strlen($content) > 100) ? "big content gotten (" .strlen($content). " bytes)" : "\"$content\""));
  return ($content);
} # end func load_url


function block_toggle_switch ($prefix="", $fboxname="", $sensorname="", $mintoggletime = 0)
{
  if ($mintoggletime < 5) { $mintoggletime = 5; }   # minimum HARD limit
  $rc = true;
  $prefix = sanitize_for_fname ($prefix);
  $fboxname = sanitize_for_fname ($fboxname);
  $sensorname = sanitize_for_fname ($sensorname);

  $fname = "/tmp/${prefix}_${fboxname}_${sensorname}.tmp";
  if (! file_exists($fname))
  {
    $fp = @fopen ($fname, "w");
    @fwrite ($fp, time());
    @fclose ($fp);
    $rc = false;
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
      $rc = false;
    } # end if toggling ok
    else
    {
      $rc = true;
    } # end if toggling not yet ok
  } # end if tmpfile existed
  return ($rc);
} # end func block_toggle_switch


function get_sid ($loginurl = "", $fboxlogin = "", $fboxpw = "")
{
  global $DEBUG, $VERBOSE;
  global $loginblocktime;

  $blocktime = -1;
  $challenge = null;
  $sid = null;

  # get challengestring from login-responsepage
  if (($DEBUG == true) and ($VERBOSE == true)) { dbgprint("dbg", "get_sid: request  1 loginurl '$loginurl'"); }
  $http_response = file_get_contents ($loginurl);
  if (($DEBUG == true) and ($VERBOSE == true)) { dbgprint("dbg", "get_sid: response 1 is '''$http_response'''"); }

  if (preg_match("/<Challenge>(\w+)<\/Challenge>/i", $http_response, $res))
    { $challenge = $res[1]; }
  if (preg_match("/<SID>([\da-f]+)<\/SID>/i", $http_response, $res))
    { $sid = $res[1]; }
  if (preg_match("/<BlockTime>(\d+)<\/BlockTime>/i", $http_response, $res))
    { $blocktime = $res[1]; }

  if ((isset($sid)) and (preg_match("/^[0]+$/",$sid)) and (isset($challenge)))
  {
    if ($blocktime > 0)                             # XXX we care about blocktime only if the fbox-returned sid is invalid.
    {
      $loginblocktime = floor($blocktime);
      return (null);
    }

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
    if (($DEBUG == true) and ($VERBOSE == true)) { dbgprint("dbg", "get_sid: request  2 loginurl '$loginurl'"); }
    $http_response = file_get_contents($url);
    if (($DEBUG == true) and ($VERBOSE == true)) { dbgprint("dbg", "get_sid: response 2 is '''$http_response'''"); }
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
  global $DEBUG, $VERBOSE;
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
    if (($DEBUG == true) and ($VERBOSE == true))
      { dbgprint ("dbg", "get_actor_infos", "final actorarr is ''" .print_r ($actorarr, true). "''"); }

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
    foreach ($actorarr['device'] as $actor)
    {
      if (isset($actor['@attributes']) and isset($actor['name']))
      {
        $ident = $actor['@attributes']['identifier'];
        $name = $actor['name'];
        dbgprint ("dbg", "get_actor_infos", "found actor: '$name' (ident:$ident)");
        $actorident2infos[$ident] = $actor;
      }
    }
  } # end if got actors returned

  return ($actorident2infos);
} # end func get_actor_infos



function do_fbox_command ($fboxname="", $fboxhost="", $fboxlogin="", $fboxpw="", $sensornames="", $cmd = "")
{
  global $myself, $perfdata, $use_tempoffset, $minsensortoggletime, $loginblocktime, $config, $STATE_CRITICAL, $STATE_UNKNOWN;
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
    $loginblockedmsg = "";
    if ($loginblocktime > 0)
      { $loginblockedmsg = " (login blocked for another $loginblocktime seconds)"; }
    dbgprint ("dbg", "Fritz-Login", "Login failed$loginblockedmsg");
    print "CRITICAL - Logon to Fbox on host '$fboxhost' (section $fboxname) failed$loginblockedmsg\n";
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

  # get actor data via provided in above
  foreach ($ains as $ain)
  {
    $actorinfo = $actorident2infos[$ain];
    $url = $ahaurl ."?sid=$sid&ain=$ain";

    $name = (isset($actorinfo['name'])) ? $actorinfo['name'] : "";
    $present = (isset($actorinfo['present'])) ? $actorinfo['present'] : "0";
	$errorcode = ((isset($actorinfo['hkr']['errorcode'])) ? $actorinfo['hkr']['errorcode'] : "0"); # Comet DECT error code
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


    # for recording battery power levels (no idea if batterylow can be anything other than 0.. and how you'd set it in the fritz!box) (ie. heater control FRITZ!DECT 301 or Comet DECT)
    $battery         = (isset($actorinfo['battery'])) ? floor($actorinfo['battery']) : null;
    $batterylow      = (isset($actorinfo['batterylow'])) ? floor($actorinfo['batterylow']) : null;
    # ie. 193 => 19.3 deg Celsius
    $temperature     = (isset($actorinfo['temperature']['celsius'])) ? floor($actorinfo['temperature']['celsius']) / 10 : null;
    $tempoffset      = (isset($actorinfo['temperature']['offset'])) ? floor($actorinfo['temperature']['offset']) / 10 : null;
    if ($use_tempoffset == true) { $temperature = $temperature + $tempoffset; }
    # either 0 or 1
    $switchstatus    = (isset($actorinfo['switch']['state'])) ? (floor($actorinfo['switch']['state']) > 0 ? 1 : 0) : null;
    # 207990 / 1000 = 207.990 W
    $currentusage    = (isset($actorinfo['powermeter']['power'])) ? floor($actorinfo['powermeter']['power']) / 1000 : null;
    # 700128 / 1000 = 700.128 kWh
    $totalenergyused = (isset($actorinfo['powermeter']['energy'])) ? floor($actorinfo['powermeter']['energy']) / 1000 : null;

    $outbatt = (isset($battery)) ? $battery : "not-set";
    $outbattlow = (isset($batterylow)) ? $batterylow : "not-set";
    $outtemp = (isset($temperature)) ? $temperature : "not-set";
    $outtempoffset = (isset($tempoffset)) ? $tempoffset : "not-set";
    $outswst = (isset($switchstatus)) ? $switchstatus : "not-set";
    $outcurrusage = (isset($currentusage)) ? $currentusage : "not-set";
    $outtoten = (isset($totalenergyused)) ? $totalenergyused : "not-set";

    dbgprint ("dbg", "do_fbox_command", "Before preparing output, have name '$name': temp '$outtemp' (" .(!$use_tempoffset ? "NOT ":""). "using temp-offset of ${outtempoffset}C) - status '$outswst' - curr '$outcurrusage' - total '$outtoten'");

    # XXX we need at least one value (if heater device) or all values (if ie. a powerswitch)..
    #     hence the making-sure of having a non-null value even for null information from the fbox actors information.
    if ( (!isset($temperature)) and (!isset($switchstatus)) and (!isset($actorinfo['powermeter']['power'])) and (!isset($actorinfo['powermeter']['energy'])) )
    {
      dbgprint ("dbg", "get_data", "Got insufficient information from actor device " .$actorinfo['name']. " in section $fboxname.. Skipping the rest.");
      continue;
    }

    # prepare output

    # store data for later output
    dbgprint ("dbg", "got_data", "Sensor '$name': state=${outswst}, reads $temperature deg Celsius: Power actual: $currentusage W, power total: $totalenergyused kWh");

    if (!isset($perfdata[$fboxname]))
      { $perfdata[$fboxname] = array(); }

    if (!isset($perfdata[$fboxname][$ain]))
    {
      $perfdata[$fboxname][$ain] = [ 'name' => $name, 'present' => $present, 'errorcode' => $errorcode, 'currentusage' => $currentusage, 'totalenergyused' => $totalenergyused, 'switchstatus' => $switchstatus, 'temperature' => $temperature ];
      if (isset($battery))    { $perfdata[$fboxname][$ain]['battery'] = $battery; }
      if (isset($batterylow)) { $perfdata[$fboxname][$ain]['batterylow'] = $batterylow; }
    }
    else
    {
      dbgprint ("dbg", "got_data", "Got duplicate entry for ain '$ain' in section $fboxname.. Skipping data.");
    }

  } # end foreach ain found in actors list

  return ($rc);
} # end func do_fbox_command



function write_csv_entry ($csv_fpath = "", $csvout = "", $csvheader = "")
{
  $fh = -1;
  if (! file_exists ($csv_fpath))
  {
    $fh = @fopen($csv_fpath, "w");
    fputs($fh, "$csvheader\n");
  }
  else
  {
    $fh = @fopen($csv_fpath, "a");
  }

  fputs($fh, "$csvout\n");
  @fclose($fh);
} # end func write_csv_entry



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


$foundconfiguredfboxhost = false;
if ((isset($config)) and (sizeof($config) > 0))
{
  reset ($config);
  foreach ($config as $fboxname => $fboxinfo)
  {
    if ((isset($fboxname)) and ($fboxname != "") and (isset($fboxinfo['host'])) and ($fboxinfo['host'] != "") and (isset($fboxinfo['pw'])) and ($fboxinfo['pw'] != ""))
    {
      $foundconfiguredfboxhost = true;
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
# default okay temperature range:
$temperature_warn_min = -10;
$temperature_warn_max = 40;
$use_tempoffset = false;						# XXX unwise default, but leaves backwards compatibility intact

$csv_fpath = "/tmp/tmp.$PROG_NAME.csv-out.log";       # the default
$csvsepchar = ";";

# battery warn level (crit = from fbox)
$battery_warn_level = 10;     # %

$perfdata_notempunits = false;
$perfdata_nobattunits = false;
$perfdata_nopowerunits = false;
$perfdata_dataonly = false;

$perfdata = array();


### args
$rest_index = 0;
$options = getopt("dhvo", [ "debug", "help", "verbose", "csv-fpath:", "battery-warn-level:", "use-temp-offset", "temp-warn-min:", "temp-warn-max:", "perfdata-notempunits", "perfdata-nobattunits", "perfdata-nopowerunits", "perfdata-dataonly" ], $rest_index);
$rest_index--; # we'll add offset for the positional parameters

if (isset($options["csv-fpath"])) { $csv_fpath = $options["csv-fpath"]; }
if (isset($options["battery-warn-level"])) { $battery_warn_level = $options["battery-warn-level"]; }
if (isset($options["perfdata-nobattunits"])) { $perfdata_nobattunits = true; }
if (isset($options["perfdata-nopowerunits"])) { $perfdata_nopowerunits = true; }
if (isset($options["perfdata-notempunits"])) { $perfdata_notempunits = true; }
if (isset($options["perfdata-dataonly"])) { $perfdata_dataonly = true; }

if (isset($options["temp-warn-min"])) { $temperature_warn_min = $options["temp-warn-min"]; }
if (isset($options["temp-warn-max"])) { $temperature_warn_max = $options["temp-warn-max"]; }
if (isset($options["o"]) or isset($options["use-temp-offset"])) { $use_tempoffset = true; }
if (isset($options["d"]) or isset($options["debug"])) { $DEBUG = true; }
if (isset($options["v"]) or isset($options["verbose"])) { $VERBOSE = true; }

if (isset($ARGV[$rest_index+1]))
{
  $cmd = "read";
  if ($ARGV[$rest_index+1] == "csv-log") { $cmd = "csv-log"; }
  if ($ARGV[$rest_index+1] == "toggle") { $cmd = "toggle"; }
  if ($ARGV[$rest_index+1] == "switchon") { $cmd = "switchon"; }
  if ($ARGV[$rest_index+1] == "switchoff") { $cmd = "switchoff"; }
  $fboxhosts = isset($ARGV[$rest_index+2]) ? $ARGV[$rest_index+2] : "*";
  $sensornames = isset($ARGV[$rest_index+3]) ? trim($ARGV[$rest_index+3]) : "*";

  if ($fboxhosts == "*") { $fboxhosts = ".*"; }
  if ($sensornames == "*") { $sensornames = ".*"; }
}

dbgprint ("dbg", "arg-battery", "battery-warn-level $battery_warn_level%");
dbgprint ("dbg", "arg-perfdata", "perfdata_nobattunits " .($perfdata_nobattunits ? "true":"false"). ", perfdata_nopowerunits " .($perfdata_nopowerunits ? "true":"false"). ", perfdata_notempunits " .($perfdata_notempunits ? "true":"false"). ", perfdata_dataonly " .($perfdata_dataonly ? "true":"false"));
dbgprint ("dbg", "arg-temp", "temp-warn now at min/max: $temperature_warn_min / $temperature_warn_max (use_tempoffset? " .($use_tempoffset ? "true":"false"));
dbgprint ("dbg", "arg-final", "cmd: '$cmd', fboxhosts: '$fboxhosts', sensornames: '$sensornames'");


### print usage, if needed or wanted
if (!isset($ARGV[$rest_index+1]))
  { usage(); }
if (isset($options["h"]) or isset($options["help"]))
  { usage("detailed"); }
elseif (($cmd == "") or ($fboxhosts == "") or ($sensornames == ""))
  { usage(); }

### parse through config sections, looking for the supplied hosts/sensors
reset($config);
foreach ($config as $fboxname => $fboxinfo)
{
  if ((isset($fboxname)) and ($fboxname != ""))
  {
    if (preg_match("/$fboxhosts/", $fboxname))    # found matching host
    {
      if (!isset($fboxinfo['login'])) { $fboxinfo['login'] = ""; }    # XXX normal fbox login has no login, but secure has.
      if ((isset($fboxinfo['host'])) and ($fboxinfo['host'] != "") and (isset($fboxinfo['pw'])) and ($fboxinfo['pw'] != ""))
      {
        dbgprint ("dbg", "do_fbox_command", "cmd '$cmd' " .($fboxinfo['login'] == "" ? "" : "for login '" .$fboxinfo['login']. "' "). "for host '" .$fboxinfo['host']. "' (section $fboxname), sensornames '$sensornames'");
        do_fbox_command ($fboxname, $fboxinfo['host'], $fboxinfo['login'], $fboxinfo['pw'], $sensornames, $cmd);
      } # end if got validlooking data in current config entry
    }
  }
}




### now for the nagios output
$nagiosrc = $STATE_UNKNOWN;
$nagiosoutput = "";
$perfdatastring = "";

$csvoutstring = "";
$csvheaderstring = "";


if (sizeof($perfdata) <= 0)
{
  $nagiosoutput = "UNKNOWN - no sensor data found for host $fboxhosts, sensors $sensornames";
  $nagiosrc = $STATE_UNKNOWN;
} # end if no perfdata gotten

else

{
  $battunit = ($perfdata_nobattunits ? "":"%");
  $powerunitcurrusage = ($perfdata_nopowerunits ? "":"kW");
  $powerunittotenergy = ($perfdata_nopowerunits ? "":"kWh");
  $tempunit = ($perfdata_notempunits ? "":"C");

  $csvheaderstring = "Timestamp$csvsepchar";
  $csvoutstring .= time() ."$csvsepchar";

  # create perfdata and csv-out strings
  reset ($perfdata);
  foreach ($perfdata as $fboxname => $fboxinfo)
  {
    foreach ($fboxinfo as $ain => $data)
    {
      $outname = preg_replace("/[ \t]/", "", $data['name']);

      $csvheaderstring .= "${fboxname}_".$outname."_power_current$csvsepchar";
      $csvheaderstring .= "${fboxname}_".$outname."_power_total$csvsepchar";
      $csvheaderstring .= "${fboxname}_".$outname."_power_status$csvsepchar";
      $csvheaderstring .= "${fboxname}_".$outname."_temperature$csvsepchar";
      $csvheaderstring .= "${fboxname}_".$outname."_battery$csvsepchar";

      # XXX no need to check perfdata_dataonly, since there's already only data being used in the perfdata.
      if (isset($data['currentusage'])) { $perfdatastring .= " ${fboxname}_" .$outname. "_actual=" .($data['currentusage']/1000). "$powerunitcurrusage;;;;"; $csvoutstring .= "" .($data['currentusage']/1000); }
      $csvoutstring .= "$csvsepchar";
      if (isset($data['totalenergyused'])) { $perfdatastring .= " ${fboxname}_" .$outname. "_total=" .$data['totalenergyused']. "$powerunittotenergy;;;;"; $csvoutstring .= "" .($data['totalenergyused']); }
      $csvoutstring .= "$csvsepchar";

      if (isset($data['switchstatus']))
      {
        $outswst = (!isset($data['switchstatus'])) ? "" : $data['switchstatus'];
        $perfdatastring .= " ${fboxname}_" .$outname. "_status=${outswst};;;;";
        $csvoutstring .= "${outswst}";
      }
      $csvoutstring .= "$csvsepchar";

      $tempcurrent = $data['temperature'];
      $tempwarn = $tempcrit = $tempmin = $tempmax = "";
      if (! $perfdata_dataonly)
      {
        # XXX not used atm, since it's incompatible with a temp range
        $tempwarn = "";
        $tempcrit = "";
        $tempmin = $temperature_warn_min;
        $tempmax = $temperature_warn_max;
      }

      if (isset($data['temperature'])) { $perfdatastring .= " ${fboxname}_" .$outname. "_temperature=$tempcurrent$tempunit;$tempwarn;$tempcrit;$tempmin$tempunit;$tempmax$tempunit"; $csvoutstring .= "$tempcurrent"; }
      $csvoutstring .= "$csvsepchar";

      if (isset($data['battery']))
      {
        $battcurrent = $data['battery'];
        $battwarn = $battcrit = $battmin = $battmax = "";
        if (! $perfdata_dataonly)
        {
          $battwarn = $battery_warn_level;
          $battcrit = (!isset($data['batterylow']))  ? "" : $data['batterylow'];
          $battmin = $battcrit;
          $battmax = "100";
        }
        $perfdatastring .= " ${fboxname}_" .$outname. "_battery=$battcurrent$battunit;$battwarn$battunit;$battcrit$battunit;$battmin$battunit;$battmax$battunit";			# cur, warn, crit, min, max
        $csvoutstring .= "$battcurrent";
      }
      $csvoutstring .= "$csvsepchar";
    } # end parsing fboxhost-data
  } # end parsing fboxhosts

  $csvheaderstring = substr($csvheaderstring, 0, -1);
  $csvoutstring = substr($csvoutstring, 0, -1);

  $nagiosrc = $STATE_OK;
  $status = "OK";

  # then the nagios output
  reset ($perfdata);
  foreach ($perfdata as $fboxname => $fboxinfo)
  {
    foreach ($fboxinfo as $ain => $data)
    {
      if (!$data['present']) { $nagiosrc = $STATE_WARNING; $status = "WARN"; }       # warn if any component is not present
      if ($data['errorcode'] != 0) { $nagiosrc = $STATE_WARNING; $status = "WARN"; } # warn if any component has an errorcode set
      $nagiosoutput .= "$fboxname: Sensor '" .$data['name']. "' (" .($data['present'] ? "" : "NOT ") . "present" . ($data['errorcode'] != 0 ? ', errorcode ' . $data['errorcode'] : "") . "):";

      if ( (isset($data['switchstatus'])) and (isset($data['currentusage'])) )
        { $nagiosoutput .= " Powerswitch " . (($data['switchstatus'] > 0) ? "is active and uses " . $data['currentusage'] . "W power" : "is inactive"). ","; }

      if (isset($data['temperature']))
      {
        dbgprint ("dbg", "temp-checks", "Sensor '" .$data['name']. "': temperature=" . $data['temperature'] . " temperature_warn_min=$temperature_warn_min temperature_warn_max=$temperature_warn_max");
        if (($data['temperature'] < $temperature_warn_min) || ($data['temperature'] > $temperature_warn_max))
          { $nagiosrc = $STATE_WARNING; $status = "WARN"; }                          # warn if we exceed warn range
        $nagiosoutput .= " Temperature is at " .$data['temperature'] ." C (min/max " .$temperature_warn_min. "/" .$temperature_warn_max. "),";
      }
      ## XXX see comment below about function bitmask
      # else
      # {
      #   dbgprint ("dbg", "temp-checks", "Sensor '" .$data['name']. "' didn't return a valid temperature value.");
      #   $nagiosrc = $STATE_UNKNOWN; $status = "UNKNOWN";
      # }

      if (isset($data['battery']))
      {
        dbgprint ("dbg", "battery-checks", "Sensor '" .$data['name']. "': current battery level " .$data['battery']. "% (lowlevel " .$data['batterylow']. "%)");

        if ($data['battery'] <= $data['batterylow'])
          { $nagiosrc = $STATE_CRITICAL; $status = "CRITICAL"; }
        elseif ($data['battery'] <= $battery_warn_level)
          { $nagiosrc = $STATE_WARNING; $status = "WARN"; }
        elseif ($data['battery'] <= 0)																					# XXX in case, batterylow can be anything other than 0, we have this.
          { $nagiosrc = $STATE_CRITICAL; $status = "CRITICAL"; }
        ## XXX the following might be needed lateron, once we differentiate between the myriads of Fritz - DECT devices (ie. via functionbitmask):
        #
        #35712 = Power switch: FRITZ!DECT 200
        #-> 1000 1011 1000 0000
        #640 = FRITZ!Powerline 546E, FRITZ!DECT 210
        #-> 0010 1000 0000
        #
        #320 = Heater control: Comet DECT*, FRITZ!DECT 301
        #-> 0001 0100 0000
        #
        #1024 = FRITZ!DECT Repeater 100
        #-> 0100 0000 0000
        #
        #1 = HAN-FUN unit devices
        #-> 1
        #
        #8208 = HAN-FUN (bit 12) ALARM (bit 3)
        #-> 0010 0000 0001 0000
        #
        #237572 = HAN-FUN color bulb
        #-> 0011 1010 0000 0000 0100
        #
        # else
        #   { $nagiosrc = $STATE_UNKNOWN; $status = "UNKNOWN"; }									# and this doesnt make much sense; it's just so unknown values don't return OK value for the checks.
        ##
        $nagiosoutput .= " Battery level " .$data['battery'] ."% (warn=" .$battery_warn_level. "%, crit=" .$data['batterylow']. "%),";
      }
      ## XXX see functionbitmask statement above.
      #else
      #  { $nagiosrc = $STATE_UNKNOWN; $status = "UNKNOWN"; } 									  # no data, unknown state
      ##
      $nagiosoutput = substr($nagiosoutput, 0, -1);

      $nagiosoutput .= "\n";
    } # end parsing fboxhost-data
  } # end parsing fboxhosts
  $nagiosoutput = "$status - Fritzbox SmartHome |$perfdatastring\n$nagiosoutput";
} # end if got perfdata



### and output everything:
#
$nagiosoutput .= "\n";

if ($cmd == "csv-log")
{
  $nagiosoutput = "";
  write_csv_entry($csv_fpath, $csvoutstring, $csvheaderstring);
}

print "$nagiosoutput";    # includes perfdatastring on first line
exit ($nagiosrc);

?>
