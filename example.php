#!/usr/bin/php -q

<?php
require_once('PhpOpenSIPs.class.php');


$api = new PhpOpenSIPs();
//$api->setUsername('test');
//$api->setPassword('secret');
//$api->addHeader('Subject: Test 1');
$api->setMethod('MESSAGE');
$api->setBody('hi there');
$api->setFrom('sip:test@example.com');
$api->setUri('sip:user@example.com');

try{
  
  $status = $api->send();
  
  echo "Status: $status\n";
  
} catch (Exception $e) {
  
  echo "Exception: ".$e->getMessage()."\n";
}

?>
