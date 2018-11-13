<?php
namespace Nats\tests\Unit;

use Nats;
use Nats\Connection;
use Nats\ConnectionOptions;
use Nats\SocketNonBlocking;

/**
 * Class ConnectionTest.
 */
class NonBlockingTest extends ConnectionTest
{

    /**
     * SetUp test suite.
     *
     * @return void
     */
    public function setUp()
    {
        $options = new ConnectionOptions();
        $socket = new SocketNonBlocking();
        $this->c = new Connection($options, $socket);
        $this->c->connect();
    }
}
