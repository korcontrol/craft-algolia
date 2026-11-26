<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Support;

use Algolia\AlgoliaSearch\Http\HttpClientInterface;
use Algolia\AlgoliaSearch\Http\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the Algolia client's HTTP layer (install it with
 * Algolia::setHttpClient() before building the SearchClient). Records every
 * request and answers with queued responses, then `200 {}`.
 */
final class FakeAlgoliaHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, path: string, body: array}> */
    public array $requests = [];

    /** @var array<string, list<ResponseInterface>> keyed by path suffix, e.g. 'deleteByQuery' */
    private array $queued = [];

    public function queue(string $pathSuffix, ResponseInterface $response): void
    {
        $this->queued[$pathSuffix][] = $response;
    }

    public function sendRequest(RequestInterface $request, $timeout, $connectTimeout): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $this->requests[] = [
            'method' => $request->getMethod(),
            'path' => $path,
            'body' => json_decode((string) $request->getBody(), true) ?? [],
        ];

        foreach ($this->queued as $suffix => $responses) {
            if ($responses !== [] && str_ends_with($path, $suffix)) {
                return array_shift($this->queued[$suffix]);
            }
        }

        return new Response(200, ['Content-Type' => 'application/json'], '{}');
    }

    /** @return list<array{method: string, path: string, body: array}> */
    public function requestsTo(string $pathSuffix): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn(array $r) => str_ends_with($r['path'], $pathSuffix)
        ));
    }
}
