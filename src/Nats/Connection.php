<?php
namespace Nats;

use RandomLib\Factory;
use RandomLib\Generator;

/**
 * Connection Class.
 *
 * Handles the connection to a NATS server or cluster of servers.
 *
 * @package Nats
 */
class Connection
{

    /**
     * Connection timeout, used by reconnect
     *
     * @var float $timeout
     */
    private $timeout = 1;


    /**
     * Number of PINGs.
     *
     * @var integer number of pings.
     */
    private $pings = 0;


    /**
     * Return the number of pings.
     *
     * @return integer Number of pings
     */
    public function pingsCount()
    {
        return $this->pings;
    }

    /**
     * Number of messages published.
     *
     * @var integer number of messages
     */
    private $pubs = 0;


    /**
     * Return the number of messages published.
     *
     * @return integer number of messages published
     */
    public function pubsCount()
    {
        return $this->pubs;
    }

    /**
     * Number of reconnects to the server.
     *
     * @var integer Number of reconnects
     */
    private $reconnects = 0;


    /**
     * Return the number of reconnects to the server.
     *
     * @return integer number of reconnects
     */
    public function reconnectsCount()
    {
        return $this->reconnects;
    }

    /**
     * List of available subscriptions.
     *
     * @var array list of subscriptions
     */
    private $subscriptions = [];


    /**
     * Return the number of subscriptions available.
     *
     * @return integer number of subscription
     */
    public function subscriptionsCount()
    {
        return count($this->subscriptions);
    }


    /**
     * Return subscriptions list.
     *
     * @return array list of subscription ids
     */
    public function getSubscriptions()
    {
        return array_keys($this->subscriptions);
    }

    /**
     * Connection options object.
     *
     * @var ConnectionOptions|null
     */
    private $options = null;

    /**
     * Socket implementation
     *
     * @var Socket
     */
    private $socket;


    /**
     * Get the socket
     *
     * @return Socket
     */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * Generator object.
     *
     * @var Generator|Php71RandomGenerator
     */
    private $randomGenerator;


    /**
     * Indicates whether $response is an error response.
     *
     * @param string $response The Nats Server response.
     *
     * @return boolean
     */
    private function isErrorResponse($response)
    {
        return substr($response, 0, 4) === '-ERR';
    }

    /**
     * Server information.
     *
     * @var mixed
     */
    private $serverInfo;


    /**
     * Process information returned by the server after connection.
     *
     * @param string $connectionResponse INFO message.
     *
     * @return void
     */
    private function processServerInfo($connectionResponse)
    {
        $this->serverInfo = new ServerInfo($connectionResponse);
    }

    /**
     * Returns current connected server ID.
     *
     * @return string Server ID.
     */
    public function connectedServerID()
    {
        return $this->serverInfo->getServerID();
    }

    /**
     * Constructor.
     *
     * @param ConnectionOptions $options Connection options object.
     */
    public function __construct(ConnectionOptions $options = null, Socket $socket = null)
    {
        $this->pings         = 0;
        $this->pubs          = 0;
        $this->subscriptions = [];
        $this->options       = $options;
        $this->socket        = $socket;
        if (version_compare(phpversion(), '7.0', '>') === true) {
            $this->randomGenerator = new Php71RandomGenerator();
        } else {
            $randomFactory         = new Factory();
            $this->randomGenerator = $randomFactory->getLowStrengthGenerator();
        }

        if ($options === null) {
            $this->options = new ConnectionOptions();
        }

        if ($socket === null) {
            $this->socket = new Socket();
        }
    }

    /**
     * Handles PING command.
     *
     * @return void
     */
    private function handlePING()
    {
        $this->socket->send('PONG');
    }

    /**
     * Handles MSG command.
     *
     * @param string $line Message command from Nats.
     *
     * @throws             Exception If subscription not found.
     * @return             void
     * @codeCoverageIgnore
     */
    private function handleMSG($line)
    {
        $parts   = explode(' ', $line);
        $subject = null;
        $length  = trim($parts[3]);
        $sid     = $parts[2];

        if (count($parts) === 5) {
            $length  = trim($parts[4]);
            $subject = $parts[3];
        } elseif (count($parts) === 4) {
            $length  = trim($parts[3]);
            $subject = $parts[1];
        }

        $payload = $this->socket->receive($length);
        $msg     = new Message($subject, $payload, $sid, $this);

        if (isset($this->subscriptions[$sid]) === false) {
            throw Exception::forSubscriptionNotFound($sid);
        }

        $func = $this->subscriptions[$sid];
        if (is_callable($func) === true) {
            $func($msg);
        } else {
            throw Exception::forSubscriptionCallbackInvalid($sid);
        }
    }

    /**
     * Connect to the server
     *
     * @param float $timeout Connect timeout
     *
     * @throws \Exception Exception raised if connection fails.
     * @return void
     */
    public function connect($timeout = null)
    {
        $this->timeout = $timeout;
        $this->socket->connect(
            $this->options->getAddress(),
            $timeout,
            $this->options->getStreamContext()
        );

        $msg = 'CONNECT '.$this->options;
        $this->socket->send($msg);

        $this->ping();
        $pingResponse = $this->socket->receive();
        if ($this->isErrorResponse($pingResponse) === true) {
            throw Exception::forFailedPing($pingResponse);
        }
    }

    /**
     * Sends PING message.
     *
     * @return void
     */
    public function ping()
    {
        $msg = 'PING';
        $this->socket->send($msg);
        $this->pings += 1;
    }

    /**
     * Request does a request and executes a callback with the response.
     *
     * @param string   $subject  Message topic.
     * @param string   $payload  Message data.
     * @param \Closure $callback Closure to be executed as callback.
     *
     * @return void
     */
    public function request($subject, $payload, \Closure $callback)
    {
        $inbox = uniqid('_INBOX.');
        $sid   = $this->subscribe(
            $inbox,
            $callback
        );
        $this->unsubscribe($sid, 1);
        $this->publish($subject, $payload, $inbox);
        $this->wait(1);
    }

    /**
     * Subscribes to an specific event given a subject.
     *
     * @param string   $subject  Message topic.
     * @param \Closure $callback Closure to be executed as callback.
     *
     * @return string
     */
    public function subscribe($subject, \Closure $callback)
    {
        $sid = $this->randomGenerator->generateString(16);
        $msg = 'SUB '.$subject.' '.$sid;
        $this->socket->send($msg);
        $this->subscriptions[$sid] = $callback;
        return $sid;
    }

    /**
     * Subscribes to an specific event given a subject and a queue.
     *
     * @param string   $subject  Message topic.
     * @param string   $queue    Queue name.
     * @param \Closure $callback Closure to be executed as callback.
     *
     * @return string
     */
    public function queueSubscribe($subject, $queue, \Closure $callback)
    {
        $sid = $this->randomGenerator->generateString(16);
        $msg = 'SUB '.$subject.' '.$queue.' '.$sid;
        $this->socket->send($msg);
        $this->subscriptions[$sid] = $callback;
        return $sid;
    }

    /**
     * Unsubscribe from a event given a subject.
     *
     * @param string  $sid      Subscription ID.
     * @param integer $quantity Quantity of messages.
     *
     * @return void
     */
    public function unsubscribe($sid, $quantity = null)
    {
        $msg = 'UNSUB '.$sid;
        if ($quantity !== null) {
            $msg = $msg.' '.$quantity;
        }

        $this->socket->send($msg);
        if ($quantity === null) {
            unset($this->subscriptions[$sid]);
        }
    }

    /**
     * Publish publishes the data argument to the given subject.
     *
     * @param string $subject Message topic.
     * @param string $payload Message data.
     * @param string $inbox   Message inbox.
     *
     * @throws Exception If subscription not found.
     * @return void
     */
    public function publish($subject, $payload = null, $inbox = null)
    {
        $msg = 'PUB '.$subject;
        if ($inbox !== null) {
            $msg = $msg.' '.$inbox;
        }

        $msg = $msg.' '.strlen($payload);
        $this->socket->send($msg."\r\n".$payload);
        $this->pubs += 1;
    }

    /**
     * Waits for messages.
     *
     * @param integer $quantity Number of messages to wait for.
     *
     * @return Connection $connection Connection object
     */
    public function wait($quantity = 0)
    {
        $count = 0;
        while ($this->socket->isActive()) {
            $line = $this->socket->receive(0);

            if ($line === null) {
                return $this;
            }

            if (strpos($line, 'PING') === 0) {
                $this->handlePING();
            }

            if (strpos($line, 'MSG') === 0) {
                $count++;
                $this->handleMSG($line);
                if (($quantity !== 0) && ($count >= $quantity)) {
                    return $this;
                }
            }
        }

        return $this;
    }

    /**
     * Waits for messages with time limit
     *
     * @param integer $quantity Number of messages to wait for.
     * @param float $timeout max time to wait data
     *
     * @return Connection $connection Connection object
     */
    public function waitWithTimeout($quantity = 0, $timeout = 0.0)
    {
        if ($timeout <= 0) {
            return $this->wait($quantity);
        }

        $count     = 0;
        $startTime = microtime(true);

        while ($this->socket->isActive()) {
            $runTime = microtime(true) - $startTime;
            $elapsed = $timeout - $runTime;

            if ($elapsed <= 0) {
                return $this;
            }

            $line = $this->socket->receive(0, $elapsed);

            if ($line === null) {
                return $this;
            }

            if (strpos($line, 'PING') === 0) {
                $this->handlePING();
            }

            if (strpos($line, 'MSG') === 0) {
                $count++;
                $this->handleMSG($line);
                if (($quantity !== 0) && ($count >= $quantity)) {
                    return $this;
                }
            }
        }

        return $this;
    }

    /**
     * Reconnects to the server.
     *
     * @return void
     */
    public function reconnect()
    {
        $this->reconnects += 1;
        $this->close();
        $this->connect($this->timeout);
    }

    /**
     * Close will close the connection to the server.
     *
     * @return void
     */
    public function close()
    {
        if (!$this->socket->isConnected()) {
            return;
        }

        $this->socket->close();
    }

    /**
     * Checks if the client is connected to a server.
     *
     * @return boolean
     */
    public function isConnected()
    {
        return $this->socket->isConnected();
    }
}
