<?php
namespace Nats;

/**
 * Non Blocking Socket
 *
 * Basic socket abstraction
 *
 * @package Nats
 */
class SocketNonBlocking extends Socket
{
    /**
     * Number of seconds to block on receive calls
     *
     * @param $timeout float
     */
    protected $readTimeout = 0.1;

    /**
     * Connect to server.
     *
     * @param string   $address       Service address, format tcp://host:port
     * @param float    $connectTimeout Number of seconds to timeout during connect
     * @param resource $streamContext A custom stream_context
     *
     * @throws \Exception Exception raised if connection fails.
     * @return void
     */
    public function connect($address, $connectTimeout = null, $streamContext = null)
    {
        parent::connect($address, $connectTimeout, $streamContext);
        stream_set_blocking($this->socket, false);
    }

    /**
     * Set how long receive will block
     *
     * @param float $timeout seconds
     */
    public function setReadTimeout($timeout)
    {
        $this->readTimeout = $timeout;
    }

    /**
     * Receives a message thought the stream.
     *
     * @param integer $len Number of bytes to receive.
     *
     * @return string
     */
    public function receive($len = 0)
    {
        $read = [$this->socket];
        $write = $except = [];


        [$timeoutSec, $timeoutUsec] = $this->secondsToSecondsAndMicroSeconds($this->readTimeout);
        $start = microtime(true);
        $running = 0;
        $numChangedStreams = 0;

        $buffer = null;
        $receivedBytes = 0;
        $loops =0;
        $needBytes = $len;
        $needMore = false;
        do {
            if (false === ($numChangedStreams = stream_select($read, $write, $except, $timeoutSec, $timeoutUsec))) {
                throw new \Exception("Stream select failed");
            } elseif ($numChangedStreams > 0) {
                // if we go data pushing start along, so timeout is between data
                //$start = microtime(true);
                $needMore = true;
                if ($len > 0) {
                    $chunk = fread($this->socket, $needBytes);
                    $buffer .= $chunk;
                    $chunkSize = strlen($chunk);
                    $needBytes -= $chunkSize;

                    if ($needBytes <= 0) {
                        $needMore = false;
                    }
                } else {
                    $buffer .= fgets($this->socket);
                    // fgets will stop at a newline, but if the socket contains less then the full line
                    // it will stop at that point
                    if (substr($buffer, -1) === "\n") {
                        $needMore = false;
                    }
                }
            }
            $running = microtime(true)-$start;
            $loops++;
        } while ($needMore && $running < $this->readTimeout);

        if ($this->debug) {
            if ($running >= $this->readTimeout) {
                echo "xxxx Timeout reading len($len)\n";
            }
            echo "xxxx loops: $loops\n";
            echo "xxxx bytes: ".strlen($buffer)."\n";
            printf("<<<< %s\n", substr($buffer, 0, 100));
        }

        return $buffer;
    }
}
