<?php
/**
 * Sandro Keil (https://sandro-keil.de)
 *
 * @link      http://github.com/sandrokeil/arangodb-php-client for the canonical source repository
 * @copyright Copyright (c) 2018 Sandro Keil
 * @license   http://github.com/sandrokeil/arangodb-php-client/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ArangoDbTest;

use ArangoDb\Client;
use ArangoDBClient\ConnectionOptions;
use Psr\Http\Client\ClientInterface;

final class TestUtil
{
    public static function getClient($useVelocypack = false): ClientInterface
    {
        $type = 'application/' . ($useVelocypack ? 'x-velocypack' : 'json');
        $params = self::getConnectionParams();

        return new Client(
            $params,
            [
                'Content-Type' => [$type],
                'Accept' => [$type],
            ]
        );
    }

    public static function getDatabaseName(): string
    {
        if (!self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return getenv('arangodb_dbname');
    }

    public static function getConnectionParams(): array
    {
        if (!self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return self::getSpecifiedConnectionParams();
    }

    public static function deleteCollection(ClientInterface $connection, string $collection): void
    {
        try {
            $connection->delete(Urls::URL_COLLECTION . '/' . $collection, []);
        } catch (RequestFailedException $e) {
            // needed if test deletes collection
        }
    }

    private static function hasRequiredConnectionParams(): bool
    {
        $env = getenv();

        return isset(
            $env['arangodb_host'],
            $env['arangodb_dbname']
        );
    }

    private static function getSpecifiedConnectionParams(): array
    {
        return [
            ConnectionOptions::OPTION_ENDPOINT => getenv('arangodb_host'),
            ConnectionOptions::OPTION_DATABASE => getenv('arangodb_dbname'),
        ];
    }
}