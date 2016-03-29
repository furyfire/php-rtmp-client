<?php
include "vendor\autoload.php";
require __DIR__ . '/debug.php';

$client = new \RTMP\Client();

$client->setLogger(new Psr\Log\NullLogger);

$client->connect("s3b78u0kbtx79q.cloudfront.net", "cfx");

while (1) {
    $client->listen();
}
