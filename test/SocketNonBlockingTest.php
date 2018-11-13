<?php
namespace Nats\tests\Unit;

use Nats\SocketNonBlocking;
use Symfony\Component\Process\PhpProcess;

/**
 * Class SocketNonBlockingTest.
 */
class SocketNonBlockingTest extends \PHPUnit\Framework\TestCase
{
    public function testSlowWriter()
    {
        $php = <<<'EOF'
<?php
$socket = stream_socket_server("tcp://127.0.0.1:8001", $errno, $errstr);
if (!$socket) {
    echo "$errstr ($errno)<br />\n";
} else {
    echo "mock server starting\n";
    while ($conn = stream_socket_accept($socket)) {
        for($i = 1; $i < 10; $i++) {
            fwrite($conn, "$i ");
            usleep(10000);
        }
        fwrite($conn, "\n");
        fclose($conn);
    }
    fclose($socket);
}
EOF;
        $mockServer = new PhpProcess($php);
        $mockServer->start();
        usleep(50000);

        $socket = new SocketNonBlocking();
        $socket->connect("tcp://localhost:8001");
        $line = $socket->receive();
        $this->assertEquals("1 2 3 4 5 6 7 8 9 \n", $line);
        $mockServer->stop();
    }

    public function testLargePayload()
    {
        $php = <<<'EOF'
<?php
$socket = stream_socket_server("tcp://127.0.0.1:8002", $errno, $errstr);
if (!$socket) {
    echo "$errstr ($errno)<br />\n";
} else {
    echo "mock server starting\n";
    while ($conn = stream_socket_accept($socket)) {
        for($i = 1; $i < 1000; $i++) {
            fwrite($conn, str_repeat("a", $i));
        }
        fwrite($conn, "\n");
        fclose($conn);
    }
    fclose($socket);
}
EOF;
        $mockServer = new PhpProcess($php);
        $mockServer->start();
        usleep(50000);

        $socket = new SocketNonBlocking();
        $socket->connect("tcp://localhost:8002");
        $line = $socket->receive();
        $this->assertEquals(499501, strlen($line));
        $socket->close();

        $socket = new SocketNonBlocking();
        $socket->connect("tcp://localhost:8002");
        $line = $socket->receive(100);
        $this->assertEquals(100, strlen($line));

        $line = $socket->receive(100000);
        $this->assertEquals(100000, strlen($line));

        // no will still hit a timeout if we ask for more lines then there are to read
        $start = microtime(true);
        $line = $socket->receive(500000);
        $this->assertEquals(399401, strlen($line));
        $time = microtime(true)-$start;
        $this->assertGreaterThan(0.1, $time);

        $mockServer->stop();
    }
}
