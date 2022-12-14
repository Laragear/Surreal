<?php

namespace Laragear\Surreal\Tcp;

use Amp\ByteStream\StreamException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\ClosedException;
use Amp\Websocket\WebsocketMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use JetBrains\PhpStorm\ArrayShape;
use JsonException;
use Laragear\Surreal\Contracts\SurrealClient;
use Laragear\Surreal\Exceptions\FailedResponseError;
use Laragear\Surreal\Exceptions\NotConnectedException;
use Laragear\Surreal\JsonRpc\ClientMessage;
use RuntimeException;
use Throwable;
use function Amp\Websocket\Client\websocketConnector;
use function base64_encode;
use function json_decode;
use function retry;
use const JSON_THROW_ON_ERROR;

class WebsocketClient implements SurrealClient
{
    /**
     * The configuration to use for connecting.
     *
     * @var array{username:string,password:string,ns:string,db:string,driver:string,host:string,port:string,database:string}
     */
    #[ArrayShape([
        "username" => "string", "password" => "string", "ns" => "string", "db" => "string", "driver" => "string",
        "host" => "string", "port" => "string", "database" => "string",
    ])]
    protected array $config = [];

    /**
     * The persistent connection being used, if any.
     *
     * @var \Amp\Websocket\Client\WebsocketConnection|null
     */
    protected ?WebsocketConnection $connection = null;

    /**
     * Sets the configuration for the WebSocket Client.
     *
     * @param  array{username:string,password:string,ns:string,db:string,driver:string,host:string,port:string,database:string}  $config
     * @return $this
     */
    public function configure(
        #[ArrayShape([
            "username" => "string", "password" => "string", "ns" => "string", "db" => "string", "driver" => "string",
            "host" => "string", "port" => "string", "database" => "string",
        ])]
        array $config
    ): static {
        $this->config = $config;

        return $this;
    }

    /**
     * Starts the client.
     *
     * @return void
     * @throws \Laragear\Surreal\Exceptions\NotConnectedException
     */
    public function start(): void
    {
        if (!$this->connection) {
            $this->connect();
        }
    }

    /**
     * Connect to the WebSocket JSON-RPC endpoint.
     *
     * @return void
     * @throws \Laragear\Surreal\Exceptions\NotConnectedException
     */
    protected function connect(): void
    {
        $handshake = new WebsocketHandshake(static::buildUrl($this->config), [
            'Authorization' => 'Basic '.base64_encode($this->config['username'].':'.$this->config['password']),
            'NS' => $this->config['ns'],
            'DB' => $this->config['db'],
        ]);

        try {
            $this->connection = retry(3, static function () use ($handshake): WebsocketConnection {
                return websocketConnector()->connect($handshake);
            });
        } catch (Throwable $e) {
            throw new NotConnectedException('Failed to connect to SurrealDB.', $e->getCode(), $e);
        }
    }

    /**
     * Stops the client.
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->connection && !$this->connection->isClosed()) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * Check if the Client has started.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return !$this->connection->isClosed();
    }

    /**
     * Sends a statement to SurrealDB, returns a Collection or a Lazy Collection if async.
     *
     * We will let async and Lazy Collection as placeholders once we figure async queries and promises.
     *
     * @param  \Laragear\Surreal\JsonRpc\ClientMessage  $statement
     * @param  bool  $async
     * @return \Illuminate\Support\Collection|\Illuminate\Support\LazyCollection
     * @throws \Laragear\Surreal\Exceptions\FailedResponseError|\Laragear\Surreal\Exceptions\NotConnectedException
     */
    public function send(ClientMessage $statement, bool $async = false): Collection|LazyCollection
    {
        try {
            $this->connection->send($statement);
        } catch (ClosedException $e) {
            throw new NotConnectedException('The SurrealDB connection is closed.', $e->getCode(), $e);
        }

        // Tentatively, an async request would be required in two scenarios:
        //   - A never ending stream of results, like live queries
        //   - A giant message from the WebSocket connection.
        //
        // For the first case, we can just return a Lazy Collection that will never end
        // until the connection is closed or the loop is broken from the dev side. For
        // the giant messages we will need to use JSON Machine to stream efficiently.
        //
        // Until then, we will just return the results as-they-are.
        return $this->getResults($this->connection->receive());
    }

    /**
     * Returns the message from the WebSocket connection as a raw array.
     *
     * @param  \Amp\Websocket\WebsocketMessage  $message
     * @return \Illuminate\Support\Collection
     * @throws \Laragear\Surreal\Exceptions\FailedResponseError|\Laragear\Surreal\Exceptions\NotConnectedException
     */
    protected function getResults(WebsocketMessage $message): Collection
    {
        if (!$message->isReadable()) {
            throw new FailedResponseError('The SurrealDB message is not readable.');
        }

        if (!$message->isText()) {
            throw new FailedResponseError('The SurrealDB message is not text.');
        }

        try {
            $serverMessage = json_decode($message->buffer(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|StreamException) {
            throw new FailedResponseError('The SurrealDB message is invalid.');
        } catch (ClosedException $e) {
            throw new NotConnectedException('The SurrealDB connection is closed.', $e->getCode(), $e);
        }

        // If the response from the server is an error, throw a failed response.
        if (isset($serverMessage['error'])) {
            throw new FailedResponseError(
                Arr::get($serverMessage, 'error.message'), Arr::get($serverMessage, 'error.code')
            );
        }

        return new Collection(Arr::get($serverMessage, 'result', []));
    }

    /**
     * Handle the object being destroyed.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Check if the Client instance is capturing statements for transactions.
     *
     * @return bool
     */
    public function isUnderTransaction(): bool
    {
        throw new RuntimeException('Transactions are not supported (yet).');
    }

    /**
     * Starts a new transaction and captures the next statements.
     *
     * @return void
     */
    public function beginTransaction(): void
    {
        throw new RuntimeException('Transactions are not supported (yet).');
    }

    /**
     * Commits the undergoing transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        throw new RuntimeException('Transactions are not supported (yet).');
    }

    /**
     * Cancels the undergoing transaction and does nothing.
     *
     * @return void
     */
    public function cancel(): void
    {
        throw new RuntimeException('Transactions are not supported (yet).');
    }

    /**
     * Build the URL we received from Laravel Database Manager.
     *
     * @param  array  $config
     * @return string
     */
    protected static function buildUrl(array $config): string
    {
        return $config['driver'].'://'.$config['host']
            .(isset($config['port']) ? ':'.$config['port'] : '').'/'
            .($config['database'] ?? 'rpc');
    }

    /**
     * Creates a new instance.
     *
     * @return static
     */
    public static function make(): static
    {
        return new static();
    }
}
