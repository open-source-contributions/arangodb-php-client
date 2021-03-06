<?php
/**
 * Sandro Keil (https://sandro-keil.de)
 *
 * @link      http://github.com/sandrokeil/arangodb-php-client for the canonical source repository
 * @copyright Copyright (c) 2018-2019 Sandro Keil
 * @license   http://github.com/sandrokeil/arangodb-php-client/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ArangoDbTest\Type;

use ArangoDb\BatchResult;
use ArangoDb\Exception\InvalidArgumentException;
use ArangoDb\Guard\Guard;
use ArangoDb\Http\Response;
use ArangoDb\Type\Batch;
use ArangoDb\Type\Collection;
use ArangoDb\Type\Document;
use ArangoDbTest\TestCase;
use ArangoDbTest\TestUtil;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;

class BatchResultTest extends TestCase
{
    private const COLLECTION_NAME = 'xyz';

    /**
     * @test
     */
    public function it_throws_exception_if_not_multipart(): void
    {
        $response = new Response(StatusCodeInterface::STATUS_OK, ['Content-type' => 'application/json']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provided $batchResponse');

        BatchResult::fromResponse($response);
    }

    /**
     * @test
     */
    public function it_supports_null_guard(): void
    {
        $guard = new class () implements Guard {
            public $counter = 0;

            public function __invoke(ResponseInterface $response): void
            {
                $this->counter++;
            }

            public function contentId(): ?string
            {
                return null;
            }
        };
        $create = Collection::create(self::COLLECTION_NAME);
        $create->useGuard($guard);

        $types = [
            $create,
            Document::create(self::COLLECTION_NAME, ['a' => 1, 'b' => 2, 'c' => 3]),
            Document::create(self::COLLECTION_NAME, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]),
            Document::create(self::COLLECTION_NAME, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5]),
        ];

        $batch = Batch::fromTypes(...$types);

        $response = $this->client->sendRequest(
            $batch->toRequest()
        );
        $batchResult = BatchResult::fromResponse($response);
        $batchResult->validate(...$batch->guards());
        $this->assertSame(4, $guard->counter);
    }

    /**
     * @test
     */
    public function it_can_be_created_from_batch_response(): void
    {
        $guard = new class () implements Guard {
            public $counter = 0;
            public $name;

            public function __invoke(ResponseInterface $response): void
            {
                $response->getBody()->rewind();
                $data = json_decode($response->getBody()->getContents());
                $this->name = $data->name;
                $this->counter++;
            }

            public function contentId(): ?string
            {
                return 'test';
            }
        };

        $create = Collection::create(self::COLLECTION_NAME);
        $create->useGuard($guard);

        $types = [
            $create,
            Document::create(self::COLLECTION_NAME, ['a' => 1, 'b' => 2, 'c' => 3]),
            Document::create(self::COLLECTION_NAME, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]),
            Document::create(self::COLLECTION_NAME, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5]),
        ];

        $batch = Batch::fromTypes(...$types);

        $response = $this->client->sendRequest(
            $batch->toRequest()
        );

        $this->assertEquals(
            StatusCodeInterface::STATUS_OK,
            $response->getStatusCode()
        );

        $batchResult = BatchResult::fromResponse($response);

        $this->assertCount(4, $batchResult);

        foreach ($batchResult as $response) {
            $data = $response->getBody()->getContents();
            $this->assertContains(
                $response->getStatusCode(),
                [StatusCodeInterface::STATUS_ACCEPTED, StatusCodeInterface::STATUS_OK],
                $data
            );

            $data = json_decode($data, true);
            $this->assertNotNull($data);
            $this->assertInternalType('array', $data);
            $this->assertNotEmpty($data);
        }
        $batchResult->validate(...$batch->guards());
        $this->assertSame(1, $guard->counter);
        $this->assertSame(self::COLLECTION_NAME, $guard->name);
    }

    protected function tearDown()
    {
        TestUtil::deleteCollection($this->client, self::COLLECTION_NAME);
    }
}