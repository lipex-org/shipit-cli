<?php

declare(strict_types=1);

namespace ShipIt\Cloud;

use ShipIt\Contracts\CloudStorageInterface;

class CloudflareR2Client implements CloudStorageInterface
{
    private string $accountId;
    private string $accessKeyId;
    private string $secretAccessKey;
    private string $bucket;
    private string $region;
    private string $endpoint;

    public function __construct(
        string $accountId,
        string $accessKeyId,
        string $secretAccessKey,
        string $bucket,
        string $region = 'auto',
        ?string $customEndpoint = null
    ) {
        $this->accountId = trim($accountId);
        $this->accessKeyId = trim($accessKeyId);
        $this->secretAccessKey = trim($secretAccessKey);
        $this->bucket = trim($bucket);
        $this->region = $region ?: 'auto';
        $this->endpoint = $customEndpoint ?: "https://{$this->accountId}.r2.cloudflarestorage.com";
    }

    public function getDriverName(): string
    {
        return 'r2';
    }

    public function upload(string $localFilePath, string $remoteKey): bool
    {
        if (!file_exists($localFilePath) || !is_readable($localFilePath)) {
            throw new \InvalidArgumentException("Local file not found or unreadable: {$localFilePath}");
        }

        $remoteKey = ltrim($remoteKey, '/');
        $fileSize = filesize($localFilePath);
        $fileData = file_get_contents($localFilePath);
        if ($fileData === false) {
            throw new \RuntimeException("Failed to read file: {$localFilePath}");
        }

        $contentType = 'application/octet-stream';
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $fileSize,
        ];

        $response = $this->sendRequest('PUT', "/{$this->bucket}/{$remoteKey}", $headers, $fileData);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new \RuntimeException(
                "R2 Upload failed (HTTP {$response['code']}): " . ($response['body'] ?: 'Unknown error')
            );
        }

        return true;
    }

    public function download(string $remoteKey, string $localDestinationPath): bool
    {
        $remoteKey = ltrim($remoteKey, '/');
        $response = $this->sendRequest('GET', "/{$this->bucket}/{$remoteKey}");

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new \RuntimeException(
                "R2 Download failed for '{$remoteKey}' (HTTP {$response['code']}): " . ($response['body'] ?: 'Object not found')
            );
        }

        $dir = dirname($localDestinationPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (file_put_contents($localDestinationPath, $response['body']) === false) {
            throw new \RuntimeException("Failed to write downloaded content to: {$localDestinationPath}");
        }

        return true;
    }

    public function listObjects(string $prefix = ''): array
    {
        $prefix = ltrim($prefix, '/');
        $query = [];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        $path = "/{$this->bucket}";
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->sendRequest('GET', $path);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new \RuntimeException(
                "R2 ListObjects failed (HTTP {$response['code']}): " . ($response['body'] ?: 'Unknown error')
            );
        }

        return $this->parseListObjectsXml($response['body']);
    }

    public function delete(string $remoteKey): bool
    {
        $remoteKey = ltrim($remoteKey, '/');
        $response = $this->sendRequest('DELETE', "/{$this->bucket}/{$remoteKey}");

        return $response['code'] >= 200 && $response['code'] < 300;
    }

    public function exists(string $remoteKey): bool
    {
        $remoteKey = ltrim($remoteKey, '/');
        $response = $this->sendRequest('HEAD', "/{$this->bucket}/{$remoteKey}");

        return $response['code'] === 200;
    }

    /**
     * Executes an AWS SigV4 signed HTTP request via cURL.
     */
    private function sendRequest(
        string $method,
        string $pathAndQuery,
        array $headers = [],
        string $payload = ''
    ): array {
        $parts = parse_url($pathAndQuery);
        $canonicalUri = $parts['path'] ?? '/';
        $queryString = $parts['query'] ?? '';

        $urlParts = parse_url($this->endpoint);
        $host = $urlParts['host'];
        if (isset($urlParts['port'])) {
            $host .= ':' . $urlParts['port'];
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $headers['Host'] = $host;
        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = hash('sha256', $payload);

        // Sort headers lowercase for canonical representation
        $canonicalHeadersArr = [];
        foreach ($headers as $k => $v) {
            $canonicalHeadersArr[strtolower(trim($k))] = trim((string) $v);
        }
        ksort($canonicalHeadersArr);

        $canonicalHeadersStr = '';
        foreach ($canonicalHeadersArr as $k => $v) {
            $canonicalHeadersStr .= $k . ':' . $v . "\n";
        }

        $signedHeadersStr = implode(';', array_keys($canonicalHeadersArr));

        // Canonical query string
        $canonicalQueryStr = '';
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
            ksort($queryParams);
            $queryParts = [];
            foreach ($queryParams as $k => $v) {
                $queryParts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
            $canonicalQueryStr = implode('&', $queryParts);
        }

        // Canonical Request
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryStr,
            $canonicalHeadersStr,
            $signedHeadersStr,
            $headers['x-amz-content-sha256']
        ]);

        // String to Sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            $algorithm,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        ]);

        // Calculate Signature
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // Authorization Header
        $authHeader = "{$algorithm} Credential={$this->accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";
        $headers['Authorization'] = $authHeader;

        // Perform cURL request
        $fullUrl = rtrim($this->endpoint, '/') . $pathAndQuery;
        $ch = curl_init($fullUrl);

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minutes max for large backup uploads

        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \RuntimeException("cURL error communicating with R2: {$curlError}");
        }

        return [
            'code' => $httpCode,
            'body' => (string) $body,
        ];
    }

    private function parseListObjectsXml(string $xmlContent): array
    {
        if (trim($xmlContent) === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            return [];
        }

        $results = [];
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $item) {
                $results[] = [
                    'key' => (string) $item->Key,
                    'size' => (int) $item->Size,
                    'last_modified' => (string) $item->LastModified,
                    'etag' => trim((string) $item->ETag, '"'),
                ];
            }
        }

        // Sort latest first
        usort($results, fn($a, $b) => strcmp($b['last_modified'], $a['last_modified']));

        return $results;
    }
}
