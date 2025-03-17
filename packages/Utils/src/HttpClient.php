<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Utils;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP Client using GuzzleHttp
 */
class HttpClient
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    /**
     * Send an HTTP request
     *
     * @param array $config Request configuration
     * @return PromiseInterface<HttpResponse>
     */
    public function request(array $config): PromiseInterface
    {
        $method = strtoupper($config['method'] ?? HttpMethod::GET);
        $path = $config['path'] ?? '';
        if (!empty($path) && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $baseURL = rtrim($config['baseURL'], '/');
        $responseType = $config['responseType'] ?? HttpResponseType::JSON;

        $url = $baseURL . $path;
        $options = [
            'headers' => $config['headers'] ?? [],
        ];

        if ($this->supportsRequestBody($method)) {
            if (isset($config['data'])) {
                $options['json'] = $config['data'];
            }
        } else {
            $query = $this->serialize($config['data'] ?? []);
            if ($query) {
                $url .= '?' . $query;
            }
        }

        return $this->client->requestAsync($method, $url, $options)
            ->then(
                function (ResponseInterface $response) use ($responseType) {
                    $status = $response->getStatusCode();
                    $statusText = $response->getReasonPhrase();
                    $headers = $response->getHeaders();
                    $body = $response->getBody()->getContents();

                    $data = null;
                    switch ($responseType) {
                        case HttpResponseType::JSON:
                            $data = json_decode($body, true);
                            break;
                        case HttpResponseType::ARRAYBUFFER:
                            $data = $body; // In PHP, arraybuffer is represented as a string
                            break;
                        case HttpResponseType::TEXT:
                            $data = $body;
                            break;
                        default:
                            throw new \InvalidArgumentException('Unsupported response type');
                    }

                    return [
                        'data' => $data,
                        'status' => $status,
                        'statusText' => $statusText,
                        'headers' => $headers,
                    ];
                },
                function (RequestException $e) {
                    $response = $e->hasResponse() ? $e->getResponse() : null;
                    $status = $response ? $response->getStatusCode() : null;
                    $statusText = $response ? $response->getReasonPhrase() : null;
                    throw new \Exception($e->getMessage(), $status ?? 0, $e);
                }
            );
    }

    /**
     * Check if the HTTP method supports a request body
     *
     * @param string $method HTTP method
     * @return bool
     */
    private function supportsRequestBody(string $method): bool
    {
        return !in_array(strtoupper($method), [
            HttpMethod::GET,
            HttpMethod::HEAD,
            HttpMethod::DELETE,
            'TRACE',
            HttpMethod::OPTIONS
        ]);
    }

    /**
     * Serialize parameters into a query string
     *
     * @param array $params Parameters to serialize
     * @return string
     */
    private function serialize(array $params): string
    {
        if (empty($params)) {
            return '';
        }
        return http_build_query($params);
    }
}