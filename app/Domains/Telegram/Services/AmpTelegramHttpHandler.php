<?php

namespace App\Domains\Telegram\Services;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

use function Amp\ByteStream\buffer;
use function Amp\ByteStream\pipe;

/**
 * Guzzle handler that yields to the worker's Amp loop during network waits.
 * Laravel's HTTP fake/recording middleware still wraps this transport.
 */
class AmpTelegramHttpHandler
{
    private readonly HttpClient $client;

    public function __construct()
    {
        $this->client = (new HttpClientBuilder)->retry(0)->followRedirects(0)->build();
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $method = $request->getMethod();
        if ($method === '') {
            throw new \InvalidArgumentException('An HTTP method is required.');
        }
        $body = $request->getBody();
        $chunks = static function () use ($body): \Generator {
            while (! $body->eof()) {
                yield $body->read(65536);
            }
        };
        $outgoing = new Request((string) $request->getUri(), $method, StreamedContent::fromStream(
            new ReadableIterableStream($chunks()), $body->getSize(), $request->getHeaderLine('Content-Type') ?: null,
        ));
        $outgoing->setHeaders($request->getHeaders());
        $outgoing->setTcpConnectTimeout(max(1, (float) ($options['connect_timeout'] ?? 5)));
        $outgoing->setTlsHandshakeTimeout(max(1, (float) ($options['connect_timeout'] ?? 5)));
        $outgoing->setTransferTimeout(max(1, (float) ($options['timeout'] ?? 60)));
        $outgoing->setBodySizeLimit(isset($options['sink']) ? 20 * 1024 * 1024 : 1024 * 1024);

        try {
            $response = $this->client->request($outgoing);
            $sink = $options['sink'] ?? null;
            if (is_string($sink)) {
                $resource = fopen($sink, 'wb');
                if ($resource === false) {
                    throw new \RuntimeException('The private download could not be opened.');
                }
                $destination = new WritableResourceStream($resource);
                try {
                    pipe($response->getBody(), $destination);
                } finally {
                    $destination->close();
                }
                $content = fopen($sink, 'rb');
                if ($content === false) {
                    throw new \RuntimeException('The private download could not be read.');
                }
            } else {
                $content = buffer($response->getBody(), limit: 1024 * 1024);
            }

            return new FulfilledPromise(new Response($response->getStatus(), $response->getHeaders(), $content));
        } catch (HttpException $error) {
            return new RejectedPromise(new ConnectException('Telegram network request failed.', $request));
        }
    }
}
