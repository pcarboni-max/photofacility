<?php

declare(strict_types=1);

namespace PhotoFacility\S3;

/**
 * Client S3 minimale, senza AWS SDK, con firma AWS Signature Version 4.
 *
 * Perché fatto a mano: su hosting condiviso non possiamo installare l'SDK via
 * Composer. Questo client richiede solo ext-curl e ext-hash. Esegue una
 * PutObject in streaming dal file su disco (nessun caricamento del RAW in RAM)
 * con Content-MD5, così S3 rifiuta l'oggetto se i byte non combaciano.
 *
 * Supporta virtual-hosted-style e path-style (utile per bucket con punti nel
 * nome o endpoint S3-compatibili).
 */
final class S3Client
{
    public function __construct(
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly ?string $endpoint = null,   // override per S3-compatibili (MinIO, ecc.)
        private readonly bool $usePathStyle = false,
        private readonly ?string $sessionToken = null,
    ) {
    }

    /**
     * Carica un file con PutObject.
     *
     * @return array{ok:bool, status:int, etag:?string, error:?string}
     */
    public function putObject(
        string $bucket,
        string $key,
        string $filePath,
        string $sha256Hex,
        string $md5Base64,
        string $contentType
    ): array {
        $size = filesize($filePath);
        if ($size === false) {
            return ['ok' => false, 'status' => 0, 'etag' => null, 'error' => 'file non leggibile'];
        }

        [$host, $urlPath, $baseUrl] = $this->resolveEndpoint($bucket, $key);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $headers = [
            'host' => $host,
            'content-md5' => $md5Base64,
            'content-type' => $contentType,
            'x-amz-content-sha256' => $sha256Hex,
            'x-amz-date' => $amzDate,
        ];
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        // --- Canonical request ---
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
            $signedHeaders[] = $name;
        }
        $signedHeadersStr = implode(';', $signedHeaders);

        $canonicalRequest = implode("\n", [
            'PUT',
            $urlPath,
            '', // query string vuota
            $canonicalHeaders,
            $signedHeadersStr,
            $sha256Hex, // payload signed (hash già calcolato in validazione)
        ]);

        // --- String to sign ---
        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        // --- Signing key + signature ---
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp), false);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeadersStr,
            $signature
        );

        // --- HTTP headers per cURL ---
        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $name => $value) {
            // 'host' lo imposta cURL; gli altri li passiamo espliciti
            if ($name === 'host') {
                continue;
            }
            $curlHeaders[] = $this->headerName($name) . ': ' . $value;
        }

        return $this->send($baseUrl, $filePath, $size, $curlHeaders);
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * @return array{0:string,1:string,2:string} [host, canonicalUriPath, fullUrl]
     */
    private function resolveEndpoint(string $bucket, string $key): array
    {
        $encodedKey = $this->encodeKeyPath($key);

        if ($this->endpoint !== null && $this->endpoint !== '') {
            $parsed = parse_url($this->endpoint);
            $scheme = $parsed['scheme'] ?? 'https';
            $epHost = $parsed['host'] ?? $this->endpoint;
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

            if ($this->usePathStyle) {
                $host = $epHost;
                $uriPath = '/' . rawurlencode($bucket) . '/' . $encodedKey;
                $url = "{$scheme}://{$epHost}{$port}/{$bucket}/{$encodedKey}";
            } else {
                $host = $bucket . '.' . $epHost;
                $uriPath = '/' . $encodedKey;
                $url = "{$scheme}://{$host}{$port}/{$encodedKey}";
            }
            return [$host, $uriPath, $url];
        }

        // AWS standard
        if ($this->usePathStyle) {
            $host = "s3.{$this->region}.amazonaws.com";
            $uriPath = '/' . rawurlencode($bucket) . '/' . $encodedKey;
            $url = "https://{$host}/{$bucket}/{$encodedKey}";
        } else {
            $host = "{$bucket}.s3.{$this->region}.amazonaws.com";
            $uriPath = '/' . $encodedKey;
            $url = "https://{$host}/{$encodedKey}";
        }
        return [$host, $uriPath, $url];
    }

    /** Codifica ogni segmento del key preservando gli slash. */
    private function encodeKeyPath(string $key): string
    {
        $segments = explode('/', $key);
        return implode('/', array_map('rawurlencode', $segments));
    }

    private function headerName(string $lower): string
    {
        // ricostruisce il case canonico degli header noti
        return match ($lower) {
            'content-md5' => 'Content-MD5',
            'content-type' => 'Content-Type',
            'x-amz-content-sha256' => 'x-amz-content-sha256',
            'x-amz-date' => 'x-amz-date',
            'x-amz-security-token' => 'x-amz-security-token',
            default => $lower,
        };
    }

    /**
     * @param list<string> $curlHeaders
     * @return array{ok:bool, status:int, etag:?string, error:?string}
     */
    private function send(string $url, string $filePath, int $size, array $curlHeaders): array
    {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'status' => 0, 'etag' => null, 'error' => 'impossibile aprire il file'];
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($body === false || $curlErr !== '') {
            return ['ok' => false, 'status' => $status, 'etag' => null, 'error' => "cURL: {$curlErr}"];
        }

        if ($status >= 200 && $status < 300) {
            $etag = isset($responseHeaders['etag']) ? trim($responseHeaders['etag'], '"') : null;
            return ['ok' => true, 'status' => $status, 'etag' => $etag, 'error' => null];
        }

        // S3 restituisce XML con <Code> e <Message> in caso di errore
        $err = is_string($body) ? $this->parseS3Error($body) : 'errore sconosciuto';
        return ['ok' => false, 'status' => $status, 'etag' => null, 'error' => "HTTP {$status}: {$err}"];
    }

    private function parseS3Error(string $xml): string
    {
        if (preg_match('#<Code>(.*?)</Code>#s', $xml, $c)) {
            $msg = preg_match('#<Message>(.*?)</Message>#s', $xml, $m) ? $m[1] : '';
            return trim($c[1] . ' ' . $msg);
        }
        return substr(trim($xml), 0, 200);
    }
}
