#!/usr/bin/php
<?php

require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");


$server = "127.0.0.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = "smartmetermysqlMQTT"; // make sure this is unique for connecting to sever - you could use uniqid()
$topicprefix = 'home/smartmeter/';

$settings = array(
"mysqlserver" => "localhost",
"mysqlusername" => "casaan",
"mysqlpassword" => "gWdtGxQDnq6NhSeG",
"mysqldatabase" => "casaan");


$mqttdata = array();

$mqtt = new phpMQTT($server, $port, $client_id);

$lastgasdatetime = "";

if(!$mqtt->connect(true, NULL, $username, $password)) {
	exit(1);
}

echo "Connected to mqtt server...\n";
$topics = array();
$topics['home/smartmeter/electricity/kwh_provided1'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/electricity/kwh_provided2'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/electricity/kwh_used1'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/electricity/kwh_used2'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/electricity/kw_using'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/electricity/kw_providing'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/gas/m3'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/gas/datetime'] = array("qos" => 0, "function" => "newvalue");
$topics['home/smartmeter/status'] = array("qos" => 0, "function" => "newvalue");
$mqtt->subscribe($topics, 0);

while($mqtt->proc()){
}


$mqtt->close();

function newvalue($topic, $msg){
	echo "$topic = $msg\n";
	static $mqttdata;
	global $mqtt;
	global $topicprefix;
	global $settings;
	static $lastgasdatetime;
	
	
	$mqttdata[$topic] = $msg;
	
	// If a counter has changed recalculate values
	if (($topic == 'home/smartmeter/status') && ($msg == 'ready') &&
	    isset($mqttdata['home/smartmeter/electricity/kwh_provided1']) && 
	    isset($mqttdata['home/smartmeter/electricity/kwh_provided2']) &&
	    isset($mqttdata['home/smartmeter/electricity/kwh_used1']) &&
	    isset($mqttdata['home/smartmeter/electricity/kwh_used2']) &&
	    isset($mqttdata['home/smartmeter/electricity/kw_using']) &&
	    isset($mqttdata['home/smartmeter/electricity/kw_providing']))
	{
		echo ("Calculating new kwh values...\n"); 
		
	        $mysqli = mysqli_connect($settings["mysqlserver"],$settings["mysqlusername"],$settings["mysqlpassword"],$settings["mysqldatabase"]);

	        if (($mysqli) && (!$mysqli->connect_errno))
	        {
                        // Write values to database
       	                if (!$mysqli->query("INSERT INTO `electricitymeter` (kw_using, kw_providing, kwh_used1, kwh_used2, kwh_provided1, kwh_provided2) VALUES (".
                                             $mqttdata['home/smartmeter/electricity/kw_using'].",".
                                             $mqttdata['home/smartmeter/electricity/kw_providing'].",".
                                             $mqttdata['home/smartmeter/electricity/kwh_used1'].",".
                                             $mqttdata['home/smartmeter/electricity/kwh_used2'].",".
                                             $mqttdata['home/smartmeter/electricity/kwh_provided1'].",".
                                             $mqttdata['home/smartmeter/electricity/kwh_provided2'].");"))
               	        {
                       	        echo "error writing electricity values to database ".$mysqli->error."\n";
                        }
                        
                        $newarray = array();
                        
                        // Calculate values for today
                        if ($result = $mysqli->query("SELECT * FROM `electricitymeter` WHERE timestamp >= CURDATE() ORDER BY timestamp ASC LIMIT 1"))
                        {
                                $row = $result->fetch_object();
                                $newdata["today"]["kwh_used1"] = round($mqttdata['home/smartmeter/electricity/kwh_used1'] - $row->kwh_used1,3);
                                $newdata["today"]["kwh_used2"]  = round($mqttdata['home/smartmeter/electricity/kwh_used2'] - $row->kwh_used2,3);
                                $newdata["today"]["kwh_provided1"] = round($mqttdata['home/smartmeter/electricity/kwh_provided1'] - $row->kwh_provided1,3);
                                $newdata["today"]["kwh_provided2"] = round($mqttdata['home/smartmeter/electricity/kwh_provided2'] - $row->kwh_provided2,3);
                                $newdata["today"]["kwh_used"] = round($newdata["today"]["kwh_used1"] + $newdata["today"]["kwh_used2"],3);
                                $newdata["today"]["kwh_provided"] = round($newdata["today"]["kwh_provided1"] + $newdata["today"]["kwh_provided2"],3);
                                $newdata["today"]["kwh_total"] = round($newdata["today"]["kwh_used"] - $newdata["today"]["kwh_provided"],3);
                                
				$mqtt->publishwhenchanged ($topicprefix."electricity/kwh_used_today", $newdata["today"]["kwh_used"],0,1);
				$mqtt->publishwhenchanged ($topicprefix."electricity/kwh_provide_today", $newdata["today"]["kwh_provided"],0,1);
				$mqtt->publishwhenchanged ($topicprefix."electricity/kwh_today", $newdata["today"]["kwh_total"],0,1);
                        }
                        else
                        {
                                echo "error reading electricity values from database ".$mysqli->error."\n";
                        }
                        
                        
                        // Calculate values from yesterday
                        if ($result = $mysqli->query("SELECT * FROM `electricitymeter` WHERE timestamp >= CURDATE() - INTERVAL 1 DAY ORDER BY timestamp ASC LIMIT 1"))
                        {
                                $row1 = $result->fetch_object();
                                if ($result = $mysqli->query("SELECT * FROM `electricitymeter` WHERE timestamp >= CURDATE() ORDER BY timestamp ASC LIMIT 1"))
                                {
                                        $row2 = $result->fetch_object();
                                        $newdata["yesterday"]["kwh_used1"] = round($row2->kwh_used1 - $row1->kwh_used1,3);
                                        $newdata["yesterday"]["kwh_used2"]  = round($row2->kwh_used2 - $row1->kwh_used2,3);
                                        $newdata["yesterday"]["kwh_provided1"] = round($row2->kwh_provided1 - $row1->kwh_provided1,3);
                                        $newdata["yesterday"]["kwh_provided2"] = round($row2->kwh_provided2 - $row1->kwh_provided2,3);
                                        $newdata["yesterday"]["kwh_used"] = round($newdata["yesterday"]["kwh_used1"] + $newdata["yesterday"]["kwh_used2"],3);
                                        $newdata["yesterday"]["kwh_provided"] = round($newdata["yesterday"]["kwh_provided1"] + $newdata["yesterday"]["kwh_provided2"], 3);
                                        $newdata["yesterday"]["kwh_total"] = round($newdata["yesterday"]["kwh_used"] - $newdata["yesterday"]["kwh_provided"], 3);
                                        
                                        $mqtt->publishwhenchanged ($topicprefix."electricity/kwh_used_yesterday", $newdata["today"]["kwh_used"],0,1);
        	                        $mqtt->publishwhenchanged ($topicprefix."electricity/kwh_provided_yesterday", $newdata["today"]["kwh_provided"],0,1);
	                                $mqtt->publishwhenchanged ($topicprefix."electricity/kwh_yesterday", $newdata["today"]["kwh_total"],0,1);
                                }
                        }
                        else
                        {
                                echo "error reading electricity values from database ".$mysqli->error."\n";
                        }

		}
		else
		{
			echo "Connection to myqsl failed!\n";
		}
		

		
	}
	
        if (($topic == 'home/smartmeter/status') && ($msg == 'ready') &&
            isset($mqttdata['home/smartmeter/gas/m3']) &&
            isset($mqttdata['home/smartmeter/gas/datetime']) &&
            ($lastgasdatetime != $mqttdata['home/smartmeter/gas/datetime']))
        {
                echo "Calculating new gas values...\n";
	        $mysqli = mysqli_connect($settings["mysqlserver"],$settings["mysqlusername"],$settings["mysqlpassword"],$settings["mysqldatabase"]);

	        $lastgasdatetime = $mqttdata['home/smartmeter/gas/datetime'];
	        if (($mysqli) && (!$mysqli->connect_errno))
	        {
	                // Write values to database
                        $sql = "INSERT INTO `gasmeter` (timestamp, m3) VALUES ('".$mqttdata['home/smartmeter/gas/datetime']."','" 
                               .$mqttdata['home/smartmeter/gas/m3']."');";
                        echo $sql;
                        if (!$mysqli->query($sql))
                        {
                                echo "error writing gas values to database ".$mysqli->error."\n";
                        }

                        $newarray = array();


                        // Calculate gas hour
                        if ($result = $mysqli->query("SELECT * FROM `gasmeter` WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY timestamp ASC LIMIT 1"))
                        {
                                $row = $result->fetch_object();
                                //var_dump ($row);
                                if ($row)
                                {
                                        $newdata["m3h"] = round($mqttdata['home/smartmeter/gas/m3'] - $row->m3,3);
                                }
                                else
                                {
                                        $newdata["m3h"] = "";
                                }
                        }
                        else
                        {
                                echo "no gas values from database ".$mysqli->error."\n";
                                $newdata["m3h"] = "";
                        }
                        $mqtt->publishwhenchanged ($topicprefix."gas/m3h", $newdata["m3h"],0,1);

                        // Calculate gas today
                        if ($result = $mysqli->query("SELECT * FROM `gasmeter` WHERE timestamp >= CURDATE() ORDER BY timestamp ASC LIMIT 1"))
                        {
                                $row = $result->fetch_object();
                                //var_dump ($row);
                                $newdata["today"]["m3"] = round($mqttdata['home/smartmeter/gas/m3'] - $row->m3, 3);
                        }
                        else
                        {
                                echo "error reading gas values from database ".$mysqli->error."\n";
                                $newdata["today"]["m3"] = "";
                        }
                        $mqtt->publishwhenchanged ($topicprefix."gas/m3_today", $newdata["today"]["m3"],0,1);
	        }
        }

}
