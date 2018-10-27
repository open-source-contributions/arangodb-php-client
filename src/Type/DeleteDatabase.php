<?php
/**
 * Sandro Keil (https://sandro-keil.de)
 *
 * @link      http://github.com/sandrokeil/arangodb-php-client for the canonical source repository
 * @copyright Copyright (c) 2018 Sandro Keil
 * @license   http://github.com/sandrokeil/arangodb-php-client/blob/master/LICENSE.md New BSD License
 */
declare(strict_types=1);

namespace ArangoDb\Type;

use ArangoDb\Exception\LogicException;
use ArangoDBClient\HttpHelper;
use ArangoDBClient\Urls;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DeleteDatabase implements Type
{
    use ToHttpTrait;

    /**
     * @var string
     */
    private $databaseName;

    /**
     * @var array
     */
    private $options;

    /**
     * Inspects response
     *
     * @var callable
     */
    private $inspector;

    private function __construct(
        string $databaseName,
        array $options = [],
        callable $inspector = null
    ) {
        $this->databaseName = $databaseName;
        $this->options = $options;
        $this->inspector = $inspector ?: function (ResponseInterface $response, string $rId = null) {
            return null;
        };
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Database/DatabaseManagement.html#drop-database
     *
     * @param string $databaseName
     * @param array $options
     * @return DeleteDatabase
     */
    public static function with(string $databaseName, array $options = []): DeleteDatabase
    {
        return new self($databaseName, $options);
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Database/DatabaseManagement.html#drop-database
     *
     * @param string $databaseName
     * @param callable $inspector Inspects result, signature is (ResponseInterface $response, string $rId = null)
     * @param array $options
     * @return DeleteDatabase
     */
    public static function withInspector(
        string $databaseName,
        callable $inspector,
        array $options = []
    ): DeleteDatabase {
        return new self($databaseName, $options, $inspector);
    }

    public function checkResponse(ResponseInterface $response, string $rId = null): ?int
    {
        return ($this->inspector)($response, $rId);
    }

    public function collectionName(): string
    {
        throw new LogicException('Not possible at the moment, see ArangoDB docs');
    }

    public function toRequest(): RequestInterface
    {
        return $this->buildAppendBatch(
            HttpHelper::METHOD_DELETE,
            Urls::URL_DATABASE . '/' . $this->databaseName,
            $this->options
        );
    }

    public function toJs(): string
    {
        throw new LogicException('Not possible at the moment, see ArangoDB docs');
    }
}
