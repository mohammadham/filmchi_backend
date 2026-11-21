<?php

namespace Tests\Support;

use App\Services\Relay\TelegramDriveClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * The real Telegram Drive client with its HTTP layer mocked, so tests run the
 * production adopt/restore/login code and only the network is fake.
 */
class DriveMock extends TelegramDriveClient
{
    /** @var array<int, array{method:string,path:string,authorization:string,api_key:string}> */
    public array $history = [];

    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function __construct(array $responses, array $config = [])
    {
        parent::__construct($config + [
            'base_url' => 'http://tgdrive.test',
            'api_key' => 'td_test_key',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $stack = HandlerStack::create(new MockHandler($responses));

        $stack->push(Middleware::mapRequest(function ($request) {
            $decoded = json_decode((string) $request->getBody(), true);

            $this->history[] = [
                'method' => $request->getMethod(),
                'path' => (string) $request->getUri(),
                'authorization' => $request->getHeaderLine('Authorization'),
                'api_key' => $request->getHeaderLine('X-API-Key'),
            ];

            if (is_array($decoded)) {
                $this->sent[] = $decoded;
            }

            return $request;
        }));

        $this->http = new HttpClient([
            'handler' => $stack,
            'base_uri' => 'http://tgdrive.test/',
            'http_errors' => false,
        ]);
    }
}
