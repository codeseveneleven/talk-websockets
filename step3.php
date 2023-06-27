<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . "/vendor/autoload.php";

class MyChat implements MessageComponentInterface {
	protected $clients;

	public function __construct() {
		$this->clients = new SplObjectStorage;
	}

	public function onOpen(ConnectionInterface $conn) {
		$this->clients->attach($conn);
	}

	public function onMessage(ConnectionInterface $from, $msg) {

		$payload = json_decode( $msg , true);
		foreach($payload as $k=>$v) {
			$payload[$k] = htmlspecialchars(strip_tags($v));
		}
		$msg = json_encode($payload);

		switch($payload['action']) {
			case 'login':
				foreach ($this->clients as $client) {
					if ($client !== $from) {
						$client->send( $msg );
					} else {
						$this->clients->setInfo(['nickname'=>strip_tags($payload['nickname'])]);
					}
				}
				break;
			case 'msg':
				foreach ($this->clients as $client) {
					$client->send( $msg );
				}
				break;
		}
	}

	public function onClose(ConnectionInterface $conn) {
		$info = null;
		foreach($this->clients as $client) {
			if ($client === $conn) {
				$info = $this->clients->getInfo();
			}
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
