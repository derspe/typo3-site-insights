<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Fixtures;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Guzzle with queued responses and a history of the sent requests.
 */
final class FakeHttp
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    public array $history = [];
    private MockHandler $handler;

    /**
     * @param list<Response|\Throwable|callable> $responses
     */
    public function __construct(private readonly TestCase $test, array $responses = [])
    {
        $this->handler = new MockHandler($responses);
    }

    public function queue(Response|\Throwable|callable ...$responses): void
    {
        foreach ($responses as $response) {
            $this->handler->append($response);
        }
    }

    public static function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function factory(): GuzzleClientFactory
    {
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $method = new \ReflectionMethod(TestCase::class, 'createMock');
        $factory = $method->invoke($this->test, GuzzleClientFactory::class);
        $factory->method('getClient')->willReturn($client);
        return $factory;
    }

    /**
     * @return list<string>
     */
    public function requestedUrls(): array
    {
        return array_map(static fn(array $entry): string => (string)$entry['request']->getUri(), $this->history);
    }

    /**
     * @return array<string, mixed> form fields of the request
     */
    public function formFields(int $index): array
    {
        parse_str((string)$this->history[$index]['request']->getBody(), $fields);
        return $fields;
    }
}
