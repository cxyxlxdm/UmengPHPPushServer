<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

// timezone
date_default_timezone_set('PRC');
// Report all PHP errors
error_reporting(E_ALL ^ E_NOTICE);
// load Redis
require '../redis/Predis.php';
//Predis\Autoloader::register();
// connect to Redis server
$redis = new Predis_Client(array('host'=>'127.0.0.1','port'=>6379));
// Redis queue key
define ("QUEUE_KEY",'list.umeng.messagequeue');

/*
 * Pop from queue.
 * Currently using redis
 */
function pushQueue ($redis) {
	$redis->rpush(QUEUE_KEY,"你的device token:message text");
}

pushQueue($redis);

