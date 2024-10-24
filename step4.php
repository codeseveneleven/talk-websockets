<?php

use Predis\Client;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . "/vendor/autoload.php";

class MyChat implements MessageComponentInterface {
	protected SplObjectStorage $clients;
	protected Client $redis;
	protected string $redisconnect;

	public function __construct($redisconnect = 'tcp://127.0.0.1:6379') {
		$this->redisconnect = $redisconnect;
		$this->clients = new SplObjectStorage();
		$this->redis  = new Client($redisconnect);
	}

	public function onOpen(ConnectionInterface $conn) {
		$this->clients->attach($conn);
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$this->connectRedis();
		$payload = json_decode( $msg , true);
		foreach($payload as $k=>$v) {
			$payload[$k] = htmlentities(strip_tags($v),ENT_NOQUOTES);
		}
		$msg = json_encode($payload);

		switch($payload['action']) {
			case 'login':
				foreach ($this->clients as $client) {
					if ($client !== $from) {
						$client->send( $msg );
					} else { // $client is $from
						$this->clients[$from] = ['nickname'=>strip_tags($payload['nickname'])];

						$chatlog = $this->redis->lrange('chatlog',0,50) ?? [];
						$chatlog = array_reverse($chatlog);
						foreach ($chatlog as $chatmsg) {
							$from->send($chatmsg);
						}
					}
				}
				break;
			case 'msg':
				$this->redis->lpush('chatlog', $msg);
				foreach ($this->clients as $client) {
					$client->send( $msg );
				}
				break;
		}
	}

	public function connectRedis()
	{
		if (
			!$this->redis instanceof Client ||
			$this->redis->ping('pong')!=='pong'
		) {
			$this->redis  = new Client($this->redisconnect);
		}
	}

	public function onClose(ConnectionInterface $conn) {
		$info = null;
		if ($this->clients[$conn]) {
			$info = $this->clients[$conn];
		}
		$this->clients->detach($conn);
		if ($info && $info['nickname']) {
			foreach($this->clients as $client) {
				$client->send(json_encode( ['action'=>'logoff','nickname'=>$info['nickname']]));
			}
		}
	}

	public function onError(ConnectionInterface $conn, Exception $e) {
		$conn->close();
	}
}

$app = new Ratchet\App('127.0.0.1', 8080);
$app->route('/chat', new MyChat, array('*'));
$app->route('/echo', new Ratchet\Server\EchoServer, array('*'));
$app->run();
