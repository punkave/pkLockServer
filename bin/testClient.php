<?php

require dirname(__FILE__) . '/../lib/pkLockClient.php';

$options = array('host' => 'localhost', 'port' => 20934);
$client = new pkLockClient($options);
$response = $client->lock('test'); // , array('timeLimit' => 1) if you can't wait the default 30 seconds
if ($response)
{
  echo("Got lock\n");
}
else
{
  echo("Did not get lock\n");
  exit(0);
}

sleep(10);

echo("Offering to unlock\n");
$client->unlock('test');

sleep(10);
