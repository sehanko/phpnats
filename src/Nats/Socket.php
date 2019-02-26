<?php
namespace Nats;

/**
 * Socket
 *
 * Basic socket abstraction
 *
 * @package Nats
 */
class Socket
{

    /**
     * Show DEBUG info?
     *
     * @var boolean $debug If debug is enabled.
     */
    protected $debug = false;


    /**
     * Enable or disable debug mode.
     *
     * @param boolean $debug If debug is enabled.
     *
     * @return void
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * Chunk size in bytes to use when reading an stream of data.
     *
     * @var integer size of chunk.
     */
    protected $chunkSize = 1500;

    /**
     * Stream File Pointer.
     *
     * @var mixed Socket file pointer
     */
    protected $socket;


    /**
     * Sets the chunck size in bytes to be processed when reading.
     *
     * @param integer $chunkSize Set byte chunk len to read when reading from wire.
     *
     * @return void
     */
    public function setChunkSize($chunkSize)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * Set Stream Timeout.
     *
     * @param float $seconds Before timeout on stream.
     *
     * @return boolean
     */
    public function setTimeout($seconds)
    {
        if ($this->isConnected() === true) {
            if (is_numeric($seconds) === true) {
                try {
                    list($seconds, $microseconds) = $this->secondsToSecondsAndMicroSeconds($seconds);
                    return stream_set_timeout($this->socket, $seconds, $microseconds);
                } catch (\Exception $e) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Returns an stream socket for this connection.
     *
     * @return resource
     */
    public function getRawSocket()
    {
        return $this->socket;
    }

    /**
     * Checks if the client is connected to a server.
     *
     * @return boolean
     */
    public function isConnected()
    {
        return isset($this->socket);
    }

    /**
     * Sends data thought the stream.
     *
     * @param string $payload Message data.
     *
     * @throws \Exception Raises if fails sending data.
     * @return void
     */
    public function send($payload)
    {
        $msg = $payload."\r\n";
        $len = strlen($msg);
        while (true) {
            $written = @fwrite($this->socket, $msg);
            if ($written === false) {
                throw new \Exception('Error sending data');
            }

            if ($written === 0) {
                throw new \Exception('Broken pipe or closed connection');
            }

            $len = ($len - $written);
            if ($len > 0) {
                $msg = substr($msg, (0 - $len));
            } else {
                break;
            }
        }

        if ($this->debug === true) {
            printf(">>>> %s\n", $msg);
        }
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
        if ($len > 0) {
            $chunkSize     = $this->chunkSize;
            $line          = null;
            $receivedBytes = 0;
            while ($receivedBytes < $len) {
                $bytesLeft = ($len - $receivedBytes);
                if ($bytesLeft < $this->chunkSize) {
                    $chunkSize = $bytesLeft;
                }

                $readChunk      = fread($this->socket, $chunkSize);
                $receivedBytes += strlen($readChunk);
                $line          .= $readChunk;
            }
        } else {
            $line = fgets($this->socket);
        }

        if ($this->debug === true) {
            printf("<<<< %s\n", $line);
        }

        return $line;
    }

    /**
     * Connect to server.
     *
     * @param string   $address       Service address, format tcp://host:port
     * @param float    $timeout       Number of seconds until the connect() system call should timeout.
     * @param resource $streamContext A custom stream_context
     *
     * @throws \Exception Exception raised if connection fails.
     * @return void
     */
    public function connect($address, $timeout = null, $streamContext = null)
    {
        if ($timeout === null) {
            $timeout = intval(ini_get('default_socket_timeout'));
        }

        $errno  = null;
        $errstr = null;

        set_error_handler(
            function () {
                return true;
            }
        );

        if (empty($context)) {
            $context = stream_context_create();
        }

        $this->socket = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        restore_error_handler();

        if ($this->socket === false) {
            throw Exception::forStreamSocketClientError($errstr, $errno);
        }

        $this->setTimeout($timeout);
    }

    /**
     * Close will close the connection to the server.
     *
     * @return void
     */
    public function close()
    {
        if ($this->socket === null) {
            return;
        }

        fclose($this->socket);
        $this->socket = null;
    }

    public function isActive()
    {
        if (is_resource($this->socket) === true && feof($this->socket) === false) {
            $info = stream_get_meta_data($this->socket);
            return empty($info['timed_out']) === true;
        }

        return false;
    }

    protected function secondsToSecondsAndMicroSeconds($seconds)
    {
        $timeout      = number_format($seconds, 6);
        $seconds      = floor($timeout);
        $microseconds = (($timeout - $seconds) * 1000000);

        return [$seconds, $microseconds];
    }
}
