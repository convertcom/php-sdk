<?php
namespace ConvertSdk\Utils;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\rejection_for;

class HttpClient
{
    /**
     * Sends an HTTP request.
     *
     * Expected $config keys:
     *  - baseURL: (string) the base URL.
     *  - path: (string, optional) the path to append.
     *  - method: (string, optional) HTTP method (default GET).
     *  - headers: (array, optional) headers to send.
     *  - responseType: (string, optional) 'json', 'arraybuffer', or 'text' (default 'json').
     *  - data: (array, optional) request data. For methods that don’t support a request body (GET, HEAD, DELETE, TRACE, OPTIONS),
     *           this data is added as query parameters.
     *
     * @param array $config
     * @return PromiseInterface
     */
    public static function request(array $config): PromiseInterface
    {
        // Determine HTTP method (default GET)
        $method = isset($config['method']) ? strtoupper($config['method']) : 'GET';

        // Ensure the baseURL has no trailing slash and path has no leading slash.
        $baseURL = isset($config['baseURL']) ? rtrim($config['baseURL'], '/') : '';
        $path    = isset($config['path']) ? ltrim($config['path'], '/') : '';
        $url     = $baseURL . ($path ? '/' . $path : '');

        // Determine headers and response type.
        $headers      = $config['headers'] ?? [];
        $responseType = $config['responseType'] ?? 'json';

        // Determine if the HTTP method supports a request body.
        $methodsWithoutBody = ['GET', 'HEAD', 'DELETE', 'TRACE', 'OPTIONS'];
        $supportsRequestBody = !in_array($method, $methodsWithoutBody, true);

        $options = [
            'headers' => $headers,
        ];

        // If method does NOT support a request body, serialize data into query string.
        if (!$supportsRequestBody && !empty($config['data']) && is_array($config['data'])) {
            $queryString = http_build_query($config['data']);
            $url .= '?' . $queryString;
        } elseif ($supportsRequestBody && isset($config['data'])) {
            // Otherwise, for methods that support a body, encode data as JSON.
            $options['json'] = $config['data'];
        }

        // Create a new Guzzle HTTP client.
        $client = new Client();

        // Make the asynchronous request and return a promise.
        try {
            // var_dump($config);
            $promise = $client->requestAsync($method, $url, $options)
                ->then(function ($response) use ($responseType) {
                    $status     = $response->getStatusCode();
                    $statusText = $response->getReasonPhrase();
                    $body       = (string) $response->getBody();
                    $data       = null;

                    switch ($responseType) {
                        case 'json':
                            $data = json_decode($body, true);
                            break;
                        case 'arraybuffer':
                            // Return raw body contents.
                            $data = $response->getBody()->getContents();
                            break;
                        case 'text':
                            $data = $body;
                            break;
                        default:
                            throw new \Exception("Unsupported response type: {$responseType}");
                    }

                    return [
                        'status'     => $status,
                        'statusText' => $statusText,
                        'headers'    => $response->getHeaders(),
                        'data'       => $data,
                    ];
                });
            return $promise;
        } catch (\Exception $e) {
            return rejection_for([
                'message'    => $e->getMessage(),
                'status'     => $e->getCode(),
                'statusText' => $e->getMessage(),
            ]);
        }
    }
}
