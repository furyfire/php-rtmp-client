<?php

$autoload = require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/debug.php';

$client = new RtmpClient();
$client->connect("localhost", "myApp");

$result = $client->call("myMethod");

var_dump($result);
