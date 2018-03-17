<?php

/*
 *   (c) 2013,2017 by Frederic Krueger / fkrueger-dev-checkfboxsmarthome@holics.at
 *
 *   Licensed under the Apache License, Version 2.0
 *   There is no warranty of any kind, explicit or implied, for anything this software does or does not do.
 *
 *   Updates for this piece of software could be available under the following URL:
 *     GIT: https://github.com/fkrueger-2/check_fbox_smarthome
 *     Home: http://dev.techno.holics.at/check_fbox_smarthome/
 *
 *   Requires: pnp4nagios
 *
 *   On my testrig, this graph template looks good in "nice" mode  most of the time; only really works nicely
 *   on data with linear distances between the datasources' datapoints though. It still beats the default template, IMO.
 *
 */

## options
$coloring = "nice";     # nice or boring, see right below..
$tempgraph = "funky";	# nice or funky


#### DONT EDIT BELOW HERE

## coloring
$nicercolors = array (
 'dark' => array(
  'cc3118', 'cc7016', 'c9b215',  # red orange yellow
  '24bc14', '1598c3', 'b415c7',  # green blue pink
  '4d18e4',                      # purple
  'cc3118', 'cc7016', 'c9b215',  # red orange yellow
  '24bc14', '1598c3', 'b415c7',  # green blue pink
  '4d18e4'                       # purple
 ),
 'light' => array(
  'ea644a', 'ec9d48', 'ecd748',  # red orange yellow
  '54ec48', '48c4ec', 'de48ec',  # green blue pink
  '7648ec',                      # purple
  'ea644a', 'ec9d48', 'ecd748',  # red orange yellow
  '54ec48', '48c4ec', 'de48ec',  # green blue pink
  '7648ec'                       # purple
 )
);

$boringcolors = array(
  'dark' => array(
    '000000', '222222', '444444', '666666', '888888', 'aaaaaa', 'cccccc', 'eeeeee'
  ),
  'light' => array(
    '111111', '333333', '555555', '777777', '999999', 'bbbbbb', 'dddddd', 'ffffff'
  )
);


$temp_min = 0;		# blue coloring for 0 deg. Celsius
$temp_max = 40;		# red coloring for 40 deg. Celsius

$tempcolors = array(
  "FF0EF0","FF0DF0","FF0CF0","FF0BF0","FF0AF0",
  "FF09F0","FF08F0","FF07F0","FF06F0","FF05F0",
  "FF04F0","FF03F0","FF02F0","FF01F0","FF00F0",
  "FF00E0","FF00D0","FF00C0","FF00B0","FF00A0",
  "FF0090","FF0080","FF0070","FF0060","FF0050",
  "FF0040","FF0030","FF0020","FF0010","FF0000",
  "FF0A00","FF1400","FF1E00","FF2800","FF3200",
  "FF3C00","FF4600","FF5000","FF5A00","FF6400",
  "FF6E00","FF7800","FF8200","FF8C00","FF9600",
  "FFA000","FFAA00","FFB400","FFBE00","FFC800",
  "FFD200","FFDC00","FFE600","FFF000","FFFA00",
  "FDFF00","D7FF00","B0FF00","8AFF00","65FF00",
  "3EFF00","17FF00","00FF10","00FF36","00FF5C",
  "00FF83","00FFA8","00FFD0","00FFF4","00E4FF",
  "00D4FF","00c4FF","00B4FF","00A4FF","0094FF",
  "0084FF","0074FF","0064FF","0054FF","0044FF",
  "0032FF","0022FF","0012FF","0002FF","0000FF",
  "0100FF","0200FF","0300FF","0400FF","0500FF"
);



## init
$opt[1] = " --title \"Current energy usage (in W) for " . $this->MACRO['DISP_HOSTNAME'] . ' / ' . $this->MACRO['DISP_SERVICEDESC'] . "\" ";
$opt[1] .= " --slope-mode ";
$def[1] = "";

$opt[2] = " --title \"Total energy usage (in KW/h) for " . $this->MACRO['DISP_HOSTNAME'] . ' / ' . $this->MACRO['DISP_SERVICEDESC'] . "\" ";
$opt[2] .= " --slope-mode ";
$def[2] = "";

$opt[3] = " --title \"Temperature (in deg. Celsius) for " . $this->MACRO['DISP_HOSTNAME'] . ' / ' . $this->MACRO['DISP_SERVICEDESC'] . "\" --lower-limit $temp_min --upper-limit $temp_max ";
$opt[3] .= " --slope-mode ";
$def[3] = "";

## main
$usedcolors = ($coloring == "nice" ? $nicercolors : $boringcolors);

// XXX we need to do this, because pnp4nagios has gone completely braindead and redefines functions over and over again for eg. pnp4nagios host-pages
if (! function_exists ("gettrans") )
{
 function gettrans ($name = "")
 {
  $transtable = array(
    '_status' => array(
      'unit' => '0=off,1=on',
      'ratio' => 'INF',
      'prtformat' => '%1.0lf',
      'prtformatshort' => '%1.0lf'
    ),
    '_actual' => array(
      'unit' => 'kW',
      'ratio' => 1000,
      'prtformat' => '%7.5lf',
      'prtformatshort' => '%6.4lf'
    ),
    '_total' => array(
      'unit' => 'kWh',
      'ratio' => 1,
      'prtformat' => '%7.3lf',
      'prtformatshort' => '%5.2lf'
    ),
    '_temperature' => array(
      'unit' => 'C',
      'ratio' => 1,
      'prtformat' => '%2.1lf',
      'prtformatshort' => '%2.1lf'
    )
  );

  $unit = "";
  $ratio = 1;
  $prtformat = "%9.4lf";
  $prtformatsh = "%7.2lf";

  reset ($transtable);
  foreach ($transtable as $k => $v)
  {
    if (strpos($name, $k) !== false)
    {
      return ($v);               # specific values from transtable
    }
  }
  return (array('unit' => $unit, 'color' => '', 'ratio' => $ratio, 'prtformat' => $prtformat, 'prtformatshort' => $prtformatshort));   # defaults
 } // end func gettrans
} // end if wasnt defined already


$rrdstatus = array(
  '1defs' => array(),
  '2cdefs' => array(),
  '3areas' => array(),
  '4line1s' => array(),
  '5gprints' => array()
);
$rrdtotal = array(
  '1defs' => array(),
  '2cdefs' => array(),
  '3areas' => array(),
  '4line1s' => array(),
  '5gprints' => array()
);
$rrdcurrent = array(
  '1defs' => array(),
  '2cdefs' => array(),
  '3areas' => array(),
  '4line1s' => array(),
  '5gprints' => array()
);
$rrdtemp = array(
  '1defs' => array(),
  '2cdefs' => array(),
  '3areas' => array(),
  '4line1s' => array(),
  '5gprints' => array()
);


// the following is for the temperature graph
$num_tempcolors = sizeof($tempcolors) -1;
$temp_step = ($temp_max - $temp_min) / $num_tempcolors;

$arractual = array();

$linecnt=1;
reset ($this->DS);
foreach ($this->DS as $key => $val)
{
  if (strpos($val['NAME'], "_energy") !== false) { continue; }

  $tmpinfo = gettrans ($val['NAME']);

  $ratio = $tmpinfo['ratio'];
  $unit = $tmpinfo['unit'];
  $prtformat = $tmpinfo['prtformat'];
  $prtformatsh = $tmpinfo['prtformatshort'];

  $dscolor = $usedcolors['dark'][$linecnt-1];

  $fmtlinecnt = sprintf ("%04ld", $linecnt);
  $arractual[ sprintf("%012.6f--%s", $val['ACT'], $val['NAME']) ] = "$fmtlinecnt";

  $dsname = "DP${linecnt}";
  $dsnamescaled = "DP${linecnt}S";

  if (strpos($val['NAME'], "_total") !== false)
  {
    $rrdtotal['1defs']["$fmtlinecnt"]  = sprintf ("DEF:%s=%s:%s:%s ", $dsname, $val['RRDFILE'], $val['DS'], "AVERAGE");
    $rrdtotal['2cdefs']["$fmtlinecnt"] = sprintf ("CDEF:%s=%s,%s,* ", $dsnamescaled, $dsname, $ratio);
  }
  elseif (strpos($val['NAME'], "_status") !== false)
  {
    $rrdstatus['1defs']["$fmtlinecnt"]  = sprintf ("DEF:%s=%s:%s:%s ", $dsname, $val['RRDFILE'], $val['DS'], "AVERAGE");
    $rrdstatus['2cdefs']["$fmtlinecnt"] = sprintf ("CDEF:%s=%s,%s,* ", $dsnamescaled, $dsname, $ratio);
  }
  elseif (strpos($val['NAME'], "_temperature") !== false)
  {
    $rrdtemp['1defs']["$fmtlinecnt"]  = sprintf ("DEF:%s=%s:%s:%s ", $dsname, $val['RRDFILE'], $val['DS'], "AVERAGE");
  
    $rrdtemp['2cdefs']["$fmtlinecnt"] = sprintf ("CDEF:%s=%s,%s,* ", $dsnamescaled, $dsname, $ratio);
    for ($x = 0; $x <= $num_tempcolors; $x++)
    {
      $curtemp = $temp_min + $x * $temp_step;
      $rrdtemp['2cdefs'][sprintf("%s%03d", $fmtlinecnt, $x)] = sprintf ("CDEF:%s%03d=%s,%4.2f,GE,%4.2f,%4.2f,IF ", $dsnamescaled, $x, $dsnamescaled, $curtemp, $curtemp, $temp_min, $dsnamescaled);
    }
  }
  else
  {
    $rrdcurrent['1defs']["$fmtlinecnt"]  = sprintf ("DEF:%s=%s:%s:%s ", $dsname, $val['RRDFILE'], $val['DS'], "AVERAGE");
    $rrdcurrent['2cdefs']["$fmtlinecnt"] = sprintf ("CDEF:%s=%s,%s,* ", $dsnamescaled, $dsname, $ratio);
  }

  if (strpos($val['NAME'], "_total") !== false)
  {
    $rrdtotal['3areas']["$fmtlinecnt"]  = sprintf ("AREA:%s#%s ", $dsnamescaled, $usedcolors['light'][$linecnt-1]);
    $rrdtotal['4line1s']["$fmtlinecnt"] = sprintf ("LINE1:%s#%s:\"%s\" ", $dsnamescaled, $usedcolors['dark'][$linecnt-1], $val['NAME']);
  }
  # the following is adding black background, which is not shown when the switch of the device is turned on (ie. status=1)
  elseif (strpos($val['NAME'], "_status") !== false)
  {
    $rrdstatus['2cdefs']["${fmtlinecnt}bg"] = sprintf ("CDEF:%s=%s,INF,+ ", $dsnamescaled."_bg", $dsname);
    $rrdstatus['3areas']["${fmtlinecnt}bg"] = sprintf ("AREA:%s#%s ", $dsnamescaled."_bg", "00000080");
    $rrdstatus['3areas']["${fmtlinecnt}fg"] = sprintf ("AREA:%s#%s:\"Device is on\" ", $dsnamescaled, "c0c0c080", $val['NAME']);
  }
  elseif (strpos($val['NAME'], "_temperature") !== false)
  {
    for ($x = $num_tempcolors; $x >= 0; $x--)
    {
      $curtemp = $temp_min + $x * $temp_step;
      $cnt = ($tempgraph != "funky") ? ($num_tempcolors - $x) : $x;
      $rrdtemp['3areas'][sprintf("%s%03d", $fmtlinecnt, $cnt)] = sprintf ("AREA:%s%03d#%s ", $dsnamescaled, $x, $tempcolors[$num_tempcolors-$x]);
    }
     
#    $rrdtemp['4line1s']["$fmtlinecnt"] = sprintf ("LINE1:%s#%s:\"%s\" ", $dsnamescaled, $usedcolors['dark'][$linecnt-1], $val['NAME']);
  }
  else
  {
    $rrdcurrent['3areas']["$fmtlinecnt"]  = sprintf ("AREA:%s#%s ", $dsnamescaled, $usedcolors['light'][$linecnt-1]);
    $rrdcurrent['4line1s']["$fmtlinecnt"] = sprintf ("LINE1:%s#%s:\"%s\" ", $dsnamescaled, $usedcolors['dark'][$linecnt-1], $val['NAME']);
  }

  if (strpos($val['NAME'], "_status") !== false)
  {
    # show nothing
  }
  elseif (strpos($val['NAME'], "_total") !== false)
  {
    $rrdtotal['5gprints']["${fmtlinecnt}1"] = sprintf ("GPRINT:%s:LAST:\"%s\: Cur %s %s\\c\" ", $dsname, $val['NAME'], $prtformat, $unit);
  }
  elseif (strpos($val['NAME'], "_temperature") !== false)
  {
    $rrdtemp['5gprints']["${fmtlinecnt}1"] = sprintf ("GPRINT:%s:LAST:\"%s\: Cur %s %s\\c\" ", $dsname, $val['NAME'], $prtformat, $unit);
  }
  else
  {
    $rrdcurrent['5gprints']["${fmtlinecnt}1"] = sprintf ("GPRINT:%s:LAST:\"%s\: Cur %s  Min Avg Max\" ", $dsname, $val['NAME'], $prtformat);
    $rrdcurrent['5gprints']["${fmtlinecnt}2"] = sprintf ("GPRINT:%s:MIN:\"%s\" ", $dsname, $prtformatsh);
    $rrdcurrent['5gprints']["${fmtlinecnt}3"] = sprintf ("GPRINT:%s:AVERAGE:\"%s\" ", $dsname, $prtformatsh);
    $rrdcurrent['5gprints']["${fmtlinecnt}4"] = sprintf ("GPRINT:%s:MAX:\"%s %s\\l\" ", $dsname, $prtformatsh, $unit);
  }

  $linecnt++;
}



## now create the graphs
#
## the current one
foreach ($rrdcurrent as $k => $v)
{
  if ($k == "5gprints") { $def[1] .= "COMMENT:\"\\r\" COMMENT:\"\\r\" "; }

  ksort ($rrdstatus[$k]);
  foreach ($rrdstatus[$k] as $k2 => $v2)
  {
    $def[1] .= $v2;
  }

  ksort ($v);
  foreach ($v as $k2 => $v2)
  {
    $def[1] .= $v2;
  }
}

$def[1] .= "COMMENT:\"\\r\" ";
$def[1] .= "COMMENT:\"Command " . $val['TEMPLATE'] . " (template\: Current energy usage)\\r\" ";


## and the total one
foreach ($rrdtotal as $k => $v)
{
  if ($k == "5gprints") { $def[2] .= "COMMENT:\"\\r\" COMMENT:\"\\r\" "; }

  ksort ($rrdstatus[$k]);
  foreach ($rrdstatus[$k] as $k2 => $v2)
  {
    $def[2] .= $v2;
  }

  ksort ($v);
  foreach ($v as $k2 => $v2)
  {
    $def[2] .= $v2;
  }
}

$def[2] .= "COMMENT:\"\\r\" ";
$def[2] .= "COMMENT:\"Command " . $val['TEMPLATE'] . " (template\: Total energy usage)\\r\" ";


## and the temperature graph
foreach ($rrdtemp as $k => $v)
{
  if ($k == "5gprints") { $def[3] .= "COMMENT:\"\\r\" COMMENT:\"\\r\" "; }

  ksort ($rrdtemp[$k]);
  foreach ($rrdtemp[$k] as $k2 => $v2)
  {
    $def[3] .= $v2;
  }
}

$def[3] .= "COMMENT:\"\\r\" ";
$def[3] .= "COMMENT:\"Command " . $val['TEMPLATE'] . " (template\: Temperature)\\r\" ";

?>
