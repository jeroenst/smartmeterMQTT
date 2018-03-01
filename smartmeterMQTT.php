#!/usr/bin/php
<?php  

date_default_timezone_set ("Europe/Amsterdam");


include(realpath(dirname(__FILE__))."/../PHP-Serial/src/PhpSerial.php");
require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");

$serialdevice = "/dev/ttyUSB1";
$server = "192.168.2.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = uniqid("ducobox_");; // make sure this is unique for connecting to sever - you could use uniqid()
echo ("Smartmeter MQTT publisher started...\n"); 
$mqttTopicPrefix = "home/smartmeter/";
$iniarray = parse_ini_file("smartmeterMQTT.ini",true);
if (($tmp = $iniarray["smartmeter"]["serialdevice"]) != "") $serialdevice = $tmp;  
if (($tmp = $iniarray["smartmeter"]["mqttserver"]) != "") $server = $tmp;
if (($tmp = $iniarray["smartmeter"]["mqttport"]) != "") $tcpport = $tmp;
if (($tmp = $iniarray["smartmeter"]["mqttusername"]) != "") $username = $tmp;
if (($tmp = $iniarray["smartmeter"]["mqttpassword"]) != "") $password = $tmp;


$mqtt = new phpMQTT($server, $port, $client_id);
$mqtt->connect(true, NULL, $username, $password);


echo "Setting Serial Port Device ".$serialdevice."...\n"; 

// Let's start the class
$serial = new phpSerial;
$serialready=0;
$receivedpacket="";
if ( $serial->deviceSet($serialdevice))
{
	echo "Configuring Serial Port...\n";
	// We can change the baud rate, parity, length, stop bits, flow control
	echo "Baudrate... ";
	$serial->confBaudRate(115200);
	echo "Parity... ";
	$serial->confParity("none");
	echo "Bits... ";
	$serial->confCharacterLength(8);
	echo "Stopbits... ";
	$serial->confStopBits(1);
	echo "Flowcontrol... ";
	$serial->confFlowControl("none");
	echo "Done...\n";

	echo "Opening Serial Port...\n";
	// Then we need to open it
	if (!$serial->deviceOpen()) exit (1);
	else echo "Serial Port opened...\n"; 
} else exit(2);



//$Mysql_con = mysql_connect("nas","domotica","b-2020");

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
while(1)
{
	try {
		$readmask = array();
		$writemask = array();
		$errormask = array();
		array_push($readmask, $serial->_dHandle);
		$nroffd = stream_select($readmask, $writemask, $errormask, 1);
	        foreach ($readmask as $i)
	        {
	        	if ($i == $serial->_dHandle)
	        	{
	        		// read from serial port
				$packetcomplete = false;
				$read = $serial->readPort();
				$receivedpacket = $receivedpacket . $read;   
				if ($read) echo "Received from serial port: ".$read; 
				while (strpos($receivedpacket, "\n") !== false)
				{
					$line = strtok($receivedpacket, "\n");
					$receivedpacket = substr($receivedpacket, strlen($line)+1); 
					preg_match("'\((.*)\)'si", $line, $value);
					preg_match("'(.*?)\('si", $line, $label);
					if (isset($label[1]) && isset($value[1]))
					{
						echo ("label=".$label[1]." value=".$value[1]."\n"); 
						switch ($label[1])
						{
							case "1-0:1.7.0":
								publishmqtt("electricity/kw_using", extractvalue($value[1]));
							break;
							case "1-0:2.7.0":
								publishmqtt("electricity/kw_providing", extractvalue($value[1]));
							break;
							case "1-0:1.8.1":
								publishmqtt("electricity/kwh_used1", extractvalue($value[1]));
							break;
							case  "1-0:1.8.2":
								publishmqtt("electricity/kwh_used2", extractvalue($value[1]));
							break;
							case "1-0:2.8.1":
								publishmqtt("electricity/kwh_provided1", extractvalue($value[1]));
							break;
							case "1-0:2.8.2":
								publishmqtt("electricity/kwh_provided2", extractvalue($value[1]));
							break;
							case "0-1:24.2.1":
							        preg_match("'\((.*)\*'si", $value[1], $valuegas);
								publishmqtt("gas/m3", $valuegas[1]);

								preg_match("'(..)(..)(..)(..)(..)(..)W'", $value[1], $gasdatetime);
								$gasdatetime = '20' . $gasdatetime[1] . '-' . $gasdatetime[2] . '-' . $gasdatetime[3] . ' ' . $gasdatetime[4] . ':' . $gasdatetime[5] . ':' . $gasdatetime[6];
								publishmqtt("gas/datetime", $gasdatetime);
							break;
						}
					}
				}
			}
		}
	}
	catch (Exception $e)
	{
		echo "Error thrown, restarting program\n";
	}
}

// If you want to change the configuration, the device must be closed
$serial->deviceClose();
exit(1);

function publishmqtt ($topic, $msg)
{
	global $mqtt;
	global $mqttTopicPrefix;
	echo ($topic.": ".$msg."\n");
	$mqtt->publishwhenchanged($mqttTopicPrefix.$topic,$msg,0,1);
}

function extractvalue($string)
{
	$tmp = ltrim(preg_replace( '/[^\d\.]/', '',  $string ), '0');;
	if ($tmp[0] == ".") $tmp = '0' + $tmp;
	return $tmp; 
}

function match($lines, $needle) 
{
	$ret = false;
	foreach ( $lines as $line ) 
	{
		list($key,$val) = explode(':',$line);
		$ret = $key==$needle ? $val : false;
		if ( $ret ) break;
	}
	return $ret;
}


function replace(&$lines, $needle, $value, $add=true) 
{
	$ret = false;
	foreach ( $lines as &$line) 
	{
		list($key,$val) = explode(':',$line);
		if ($key==$needle)
		{
			$val = $value;
			$line = $key.':'.$val;
			$ret = true;
		}
	}
	if (($ret == false)&&($add == true))
	{
		array_push ($lines,$needle.':'.$value); 
		$ret = true;
	}
	return $ret;
}                     

function removeEmptyLines(&$linksArray) 
{
	foreach ($linksArray as $key => $link)
	{
		if ($linksArray[$key] == '')
		{
			unset($linksArray[$key]);
		}
	}
}                     


?>  
