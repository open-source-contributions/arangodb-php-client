<?php
/**
 * Sandro Keil (https://sandro-keil.de)
 *
 * @link      http://github.com/sandrokeil/arangodb-php-client for the canonical source repository
 * @copyright Copyright (c) 2018 Sandro Keil
 * @license   http://github.com/sandrokeil/arangodb-php-client/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ArangoDb;

use ArangoDBClient\ClientException;
use ArangoDBClient\ConnectionOptions;
use ArangoDBClient\Endpoint;
use ArangoDBClient\FailoverException;
use ArangoDBClient\HttpHelper;
use ArangoDBClient\HttpResponse;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Client implements \Psr\Http\Client\ClientInterface
{
    /**
     * Connection handle, used in case of keep-alive
     *
     * @var resource
     */
    private $handle;

    /**
     * Flag if keep-alive connections are used
     *
     * @var bool
     */
    private $useKeepAlive;

    /**
     * @var ConnectionOptions
     */
    private $options;

    /**
     * Pre-assembled base URL for the current database
     * This is pre-calculated when connection options are set/changed, to avoid
     * calculation of the same base URL in each request done via the
     * connection
     *
     * @var string
     */
    private $baseUrl = '';

    /**
     * Pre-assembled HTTP headers string for connection
     * This is pre-calculated when connection options are set/changed, to avoid
     * calculation of the same HTTP header values in each request done via the
     * connection
     *
     * @var string
     */
    private $httpHeader = '';

    /**
     * @var string
     */
    private $database = '';

    /**
     * @var array
     */
    private $defaultHeaders;

    /**
     * @param array|ConnectionOptions $options
     * @param array $defaultHeaders PSR-7 headers
     * @throws ClientException
     */
    public function __construct($options, array $defaultHeaders = [])
    {
        $this->options = $options instanceof ConnectionOptions ? $options : new ConnectionOptions($options);
        $this->useKeepAlive = ($this->options[ConnectionOptions::OPTION_CONNECTION] === 'Keep-Alive');
        $this->defaultHeaders = $defaultHeaders;
        $this->updateHttpHeader();
    }

    /**
     * Delegate method fur Guzzle handler
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws \ArangoDBClient\ServerException
     */
    public function __invoke(RequestInterface $request): ResponseInterface
    {
        return $this->sendRequest($request);
    }

    // TODO use PSR Exceptions ?

    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws \ArangoDBClient\ServerException
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = $request->getBody();
        $method = $request->getMethod();

        $customHeaders = array_merge($this->defaultHeaders, $request->getHeaders());
        unset($customHeaders['Connection'], $customHeaders['Content-Length']);

        if (! isset($customHeaders['Content-Type'])) {
            $customHeaders['Content-Type'] = ['application/json'];
        }

        $useVpack = false;

        $customHeader = '';
        foreach ($customHeaders as $headerKey => $headerValues) {
            foreach ($headerValues as $headerValue) {
                if ($headerKey === 'Content-Type' && $headerValue === 'application/x-velocypack') {
                    $useVpack = true;
                }
                $customHeader .= $headerKey . ': ' . $headerValue . HttpHelper::EOL;
            }
        }

        if ($useVpack === true && $body instanceof VpackStream) {
            $body = $body->vpack()->toBinary();
        } else {
            $body = $body->getContents();
        }

        $customHeader .= 'Content-Length: ' . strlen($body) . HttpHelper::EOL;

        $url = $this->baseUrl . $request->getUri()->getPath();

        try {
            $handle = $this->getHandle();

            $result = HttpHelper::transfer(
                $handle,
                $method . ' ' . $this->baseUrl . $request->getUri()->getPath() . ' ' . HttpHelper::PROTOCOL .
                $this->httpHeader .   // note: this one starts with an EOL
                $customHeader . HttpHelper::EOL .
                $body,
                $request->getMethod()
            );

            $status = stream_get_meta_data($handle);
            if ($status['timed_out']) {
                // can't connect to server because of timeout.
                // now check if we have additional servers to connect to
                if ($this->options->haveMultipleEndpoints()) {
                    // connect to next server in list
                    $currentLeader = $this->options->getCurrentEndpoint();
                    $newLeader = $this->options->nextEndpoint();

                    if ($newLeader && ($newLeader !== $currentLeader)) {
                        // close existing connection
                        $this->closeHandle();
                        $this->updateHttpHeader();

                        $exception = new FailoverException(
                            "Got a timeout while waiting for the server's response",
                            408
                        );
                        $exception->setLeader($newLeader);
                        throw $exception;
                    }
                }

                throw new ClientException("Got a timeout while waiting for the server's response", 408);
            }

            if (! $this->useKeepAlive) {
                // must close the connection
                fclose($handle);
            }

            $response = new HttpResponse($result, $url, $method, false);
        } catch (Throwable $e) {
            throw new \ArangoDBClient\ServerException($e->getCode(), $e->getMessage(), $e);
        }

        return new Response(
            $response->getHttpCode(),
            $response->getHeaders(),
            $response->getBody()
        );
    }

    /**
     * Recalculate the static HTTP header string used for all HTTP requests in this connection
     */
    private function updateHttpHeader(): void
    {
        $this->httpHeader = HttpHelper::EOL;

        $endpoint = $this->options->getCurrentEndpoint();
        if (Endpoint::getType($endpoint) !== Endpoint::TYPE_UNIX) {
            $this->httpHeader .= 'Host: ' . Endpoint::getHost($endpoint) . HttpHelper::EOL;
        }

        if (isset(
            $this->options[ConnectionOptions::OPTION_AUTH_TYPE],
            $this->options[ConnectionOptions::OPTION_AUTH_USER]
        )) {
            // add authorization header
            $authorizationValue = base64_encode(
                $this->options[ConnectionOptions::OPTION_AUTH_USER] . ':' .
                $this->options[ConnectionOptions::OPTION_AUTH_PASSWD]
            );

            $this->httpHeader .= sprintf(
                'Authorization: %s %s%s',
                $this->options[ConnectionOptions::OPTION_AUTH_TYPE],
                $authorizationValue,
                HttpHelper::EOL
            );
        }

        if (isset($this->options[ConnectionOptions::OPTION_CONNECTION])) {
            // add connection header
            $this->httpHeader .= 'Connection: '
                . $this->options[ConnectionOptions::OPTION_CONNECTION]
                . HttpHelper::EOL;
        }

        if ($this->database === '') {
            $this->baseUrl = '/_db/_system';
        } else {
            $this->baseUrl = '/_db/' . urlencode($this->database);
        }
    }

    /**
     * Get a connection handle
     *
     * If keep-alive connections are used, the handle will be stored and re-used
     *
     * @throws ClientException
     * @return resource - connection handle
     * @throws \ArangoDBClient\ConnectException
     */
    private function getHandle()
    {
        if ($this->useKeepAlive && $this->handle && is_resource($this->handle)) {
            // keep-alive and handle was created already
            $handle = $this->handle;

            // check if connection is still valid
            if (! feof($handle)) {
                // connection still valid
                return $handle;
            }

            // close handle
            $this->closeHandle();

            if (! $this->options[ConnectionOptions::OPTION_RECONNECT]) {
                // if reconnect option not set, this is the end
                throw new ClientException('Server has closed the connection already.');
            }
        }

        // no keep-alive or no handle available yet or a reconnect
        $handle = HttpHelper::createConnection($this->options);

        if ($this->useKeepAlive && is_resource($handle)) {
            $this->handle = $handle;
        }

        return $handle;
    }

    /**
     * Close an existing connection handle
     */
    private function closeHandle(): void
    {
        if ($this->handle && is_resource($this->handle)) {
            @fclose($this->handle);
        }
        $this->handle = null;
    }
}