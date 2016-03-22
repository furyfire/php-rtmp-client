<?php

include "vendor\autoload.php";



require __DIR__ . '/debug.php';

$client = new \RTMP\Client();

$client->setLogger(new Psr\Log\NullLogger);

$client->connect("s3b78u0kbtx79q.cloudfront.net", "cfx");

//$result = $client->_play("st");
//var_dump($result);
while (1) {
    $client->listen();
}



    /*
} catch (RtmpRemoteException $exc) {
    echo "Server sent error:";
    echo $exc->getMessage();
    echo PHP_EOL;
}
catch (Exception $exc) {
    echo $exc->getTraceAsString();
    echo $exc->getMessage();
}*/
