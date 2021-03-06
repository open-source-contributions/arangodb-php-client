<?php
/**
 * Sandro Keil (https://sandro-keil.de)
 *
 * @link      http://github.com/sandrokeil/arangodb-php-client for the canonical source repository
 * @copyright Copyright (c) 2018-2019 Sandro Keil
 * @license   http://github.com/sandrokeil/arangodb-php-client/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ArangoDb\Type;

use ArangoDb\Http\VpackStream;
use ArangoDb\Url;
use Fig\Http\Message\RequestMethodInterface;
use ArangoDb\Http\Request;
use Psr\Http\Message\RequestInterface;

final class Cursor implements CursorType
{
    /**
     * @var array
     */
    private $options;

    /**
     * @var string
     */
    private $uri;

    /**
     * @var string
     */
    private $method;

    private function __construct(
        string $uri,
        string $method,
        array $options = []
    ) {
        $this->uri = $uri;
        $this->method = $method;
        $this->options = $options;
    }

    public static function create(
        string $query,
        array $bindVars = [],
        int $batchSize = null,
        bool $count = false,
        bool $cache = null,
        array $options = []
    ): CursorType {
        $params = [
            'query' => $query,
            'bindVars' => $bindVars,
            'batchSize' => $batchSize,
            'count' => $count,
            'cache' => $cache,
        ];

        if ($params['batchSize'] === null) {
            unset($params['batchSize']);
        }
        if ($params['cache'] === null) {
            unset($params['cache']);
        }
        if (empty($params['bindVars'])) {
            unset($params['bindVars']);
        }
        if (! empty($params)) {
            $params['options'] = $options;
        }
        return new self(
            '',
            RequestMethodInterface::METHOD_POST,
            $params
        );
    }

    public static function delete(string $cursorId): CursorType
    {
        return new self(
            '/' . $cursorId,
            RequestMethodInterface::METHOD_DELETE
        );
    }

    public static function nextBatch(string $cursorId): CursorType
    {
        return new self(
            '/' . $cursorId,
            RequestMethodInterface::METHOD_PUT
        );
    }

    public function toRequest(): RequestInterface
    {
        if (empty($this->options)) {
            return new Request(
                $this->method,
                Url::CURSOR . $this->uri
            );
        }

        return new Request(
            $this->method,
            Url::CURSOR . $this->uri,
            [],
            new VpackStream($this->options)
        );
    }
}
