<?php

require dirname(__FILE__) . '/../lib/pkLockServer.class.php';

require dirname(__FILE__) . '/../config/settings.php';

$server = new pkLockServer($options);
$server->run();
