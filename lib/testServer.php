<?php

require dirname(__FILE__) . '/pkLockServer.php';

$options = array('host' => 'localhost', 'port' => 20934, 'allowed' => array('127.0.0.1'));
$server = new pkLockServer($options);
$server->run();
