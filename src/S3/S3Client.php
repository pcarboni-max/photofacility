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
final class S3Client implements StorageTarget
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
     * @param int $timeout secondi max per il trasferimento (allineato al budget del tick)
     * @return array{ok:bool, status:int, etag:?string, sse:?string, error:?string, retriable:bool}
     */
    public function putObject(
        string $bucket,
        string $key,
        string $filePath,
        string $sha256Hex,
        string $md5Base64,
        string $contentType,
        array $meta = [],
        int $timeout = 120
    ): array {
        $size = filesize($filePath);
        if ($size === false) {
            return ['ok' => false, 'status' => 0, 'etag' => null, 'sse' => null, 'error' => 'file non leggibile', 'retriable' => false];
        }

        [$host, $urlPath, $baseUrl] = $this->resolveEndpoint($bucket, $key);

        $insecure = $this->insecureNonLoopback($baseUrl);
        if ($insecure !== null) {
            return ['ok' => false, 'status' => 0, 'etag' => null, 'sse' => null, 'error' => $insecure, 'retriable' => false];
        }

        $extra = [
            'content-md5' => $md5Base64,
            'content-type' => $contentType,
        ];
        // Metadati utente → header x-amz-meta-* (firmati). I valori HTTP devono
        // essere ASCII stampabili: sanitizziamo (accenti ecc. → '_'). I valori
        // VUOTI vanno omessi: cURL elimina un header senza valore, ma noi lo
        // avremmo firmato → SignatureDoesNotMatch.
        foreach ($meta as $k => $v) {
            $value = $this->asciiHeaderValue((string) $v);
            if ($value === '') {
                continue;
            }
            $name = 'x-amz-meta-' . strtolower(preg_replace('/[^a-z0-9-]/i', '-', (string) $k));
            $extra[$name] = $value;
        }

        $curlHeaders = $this->signedHeaders('PUT', $host, $urlPath, $sha256Hex, $extra);

        return $this->send('PUT', $baseUrl, $curlHeaders, $filePath, $size, $timeout);
    }

    private function asciiHeaderValue(string $v): string
    {
        // rimuove i caratteri non ASCII-stampabili e limita la lunghezza
        $clean = preg_replace('/[^\x20-\x7E]/', '_', $v) ?? '';
        return substr(trim($clean), 0, 512);
    }

    /**
     * HeadObject: verifica esistenza/dimensione di un oggetto (riconciliazione).
     * @return array{ok:bool, exists:bool, status:int, size:?int, error:?string}
     */
    public function headObject(string $bucket, string $key, int $timeout = 15): array
    {
        [$host, $urlPath, $baseUrl] = $this->resolveEndpoint($bucket, $key);
        if ($this->insecureNonLoopback($baseUrl) !== null) {
            return ['ok' => false, 'exists' => false, 'status' => 0, 'size' => null];
        }
        // per HEAD il payload è vuoto → hash della stringa vuota
        $emptyHash = hash('sha256', '');
        $curlHeaders = $this->signedHeaders('HEAD', $host, $urlPath, $emptyHash, []);

        $res = $this->send('HEAD', $baseUrl, $curlHeaders, null, 0, $timeout);
        $exists = $res['status'] >= 200 && $res['status'] < 300;
        return [
            'ok' => $res['ok'] || $res['status'] === 404,
            'exists' => $exists,
            'status' => $res['status'],
            'size' => $exists ? ($res['contentLength'] ?? null) : null,
            'error' => $res['error'] ?? null,
        ];
    }

    /**
     * Sonda di connettività diagnostica: esegue una GET firmata su una chiave
     * (tipicamente inesistente). A differenza di HEAD, la GET restituisce il
     * CORPO XML con <Code>/<Message>, così su un 400/403 vediamo il motivo reale
     * (es. AuthorizationHeaderMalformed, che indica anche la region corretta).
     * Da usare SOLO per diagnostica: per la logica normale si usa headObject.
     *
     * @return array{status:int, error:?string}
     */
    public function probe(string $bucket, string $key, int $timeout = 10): array
    {
        [$host, $urlPath, $baseUrl] = $this->resolveEndpoint($bucket, $key);
        if ($this->insecureNonLoopback($baseUrl) !== null) {
            return ['status' => 0, 'error' => 'endpoint http:// non-loopback rifiutato'];
        }
        $emptyHash = hash('sha256', '');
        $curlHeaders = $this->signedHeaders('GET', $host, $urlPath, $emptyHash, []);
        $res = $this->send('GET', $baseUrl, $curlHeaders, null, 0, $timeout);
        return ['status' => (int) $res['status'], 'error' => $res['error'] ?? null];
    }

    /**
     * Genera un URL presigned (SigV4 query-string) per un GET a scadenza breve.
     * Usato per il download full-res: il browser scarica direttamente da S3.
     * $contentDisposition es. 'attachment; filename="foto.jpg"'.
     */
    public function presignGet(string $bucket, string $key, int $expires = 600, ?string $contentDisposition = null): string
    {
        [$host, $urlPath, $baseUrl] = $this->resolveEndpoint($bucket, $key);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $params['X-Amz-Security-Token'] = $this->sessionToken;
        }
        if ($contentDisposition !== null && $contentDisposition !== '') {
            $params['response-content-disposition'] = $contentDisposition;
        }
        ksort($params);

        $canonicalQuery = implode('&', array_map(
            static fn ($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($params),
            array_values($params),
        ));

        $canonicalRequest = implode("\n", [
            'GET',
            $urlPath,
            $canonicalQuery,
            'host:' . $host . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp), false);

        return $baseUrl . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Scarica un oggetto S3 su file locale in streaming (per il reconciler
     * thumbnail, quando il file originale non è più in staging).
     */
    public function getToFile(string $bucket, string $key, string $dstPath, int $timeout = 120): bool
    {
        [$host, $urlPath, $baseUrl] = $this->resolveEndpoint($bucket, $key);
        if ($this->insecureNonLoopback($baseUrl) !== null) {
            return false;
        }
        $curlHeaders = $this->signedHeaders('GET', $host, $urlPath, hash('sha256', ''), []);

        $fh = fopen($dstPath, 'wb');
        if ($fh === false) {
            return false;
        }
        $ch = curl_init($baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_FILE => $fh,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fh);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($dstPath);
            return false;
        }
        return true;
    }

    /**
     * Costruisce gli header firmati SigV4 per una richiesta.
     * @param array<string,string> $extra header aggiuntivi (lowercase) da firmare
     * @return list<string> header pronti per cURL (Authorization incluso)
     */
    private function signedHeaders(string $method, string $host, string $urlPath, string $payloadHash, array $extra): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $headers = array_merge($extra, [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ]);
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signed = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
            $signed[] = $name;
        }
        $signedHeadersStr = implode(';', $signed);

        $canonicalRequest = implode("\n", [
            $method,
            $urlPath,
            '',
            $canonicalHeaders,
            $signedHeadersStr,
            $payloadHash,
        ]);

        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp), false);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeadersStr,
            $signature
        );

        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $name => $value) {
            if ($name === 'host') {
                continue; // lo imposta cURL
            }
            $curlHeaders[] = $this->headerName($name) . ': ' . $value;
        }
        return $curlHeaders;
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * @return array{0:string,1:string,2:string} [hostConPorta, canonicalUriPath, fullUrl]
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
                // la porta DEVE far parte dell'host firmato: cURL la include
                // nell'header Host, quindi deve combaciare con la firma.
                $host = $epHost . $port;
                $uriPath = '/' . rawurlencode($bucket) . '/' . $encodedKey;
                $url = "{$scheme}://{$epHost}{$port}/{$bucket}/{$encodedKey}";
            } else {
                $host = $bucket . '.' . $epHost . $port;
                $uriPath = '/' . $encodedKey;
                $url = "{$scheme}://{$bucket}.{$epHost}{$port}/{$encodedKey}";
            }
            return [$host, $uriPath, $url];
        }

        // AWS standard (sempre HTTPS, nessuna porta)
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

    /**
     * Rifiuta http:// verso host NON loopback: l'header Authorization firmato
     * viaggerebbe in chiaro. http è tollerato solo per mock/MinIO locale.
     * @return string|null messaggio d'errore se insicuro, null se ok.
     */
    private function insecureNonLoopback(string $url): ?string
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'http') {
            return null;
        }
        $host = $p['host'] ?? '';
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }
        return "endpoint http:// non-loopback rifiutato ({$host}): la firma viaggerebbe in chiaro. Usa https.";
    }

    private function encodeKeyPath(string $key): string
    {
        $segments = explode('/', $key);
        return implode('/', array_map('rawurlencode', $segments));
    }

    private function headerName(string $lower): string
    {
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
     * @return array{ok:bool, status:int, etag:?string, sse:?string, error:?string, retriable:bool, contentLength?:?int}
     */
    private function send(string $method, string $url, array $curlHeaders, ?string $filePath, int $size, int $timeout): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);

        $opts = [
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(5, $timeout),
            CURLOPT_FAILONERROR => false,
            // TLS esplicito e protocolli limitati a HTTP(S): niente file://, ftp://, ecc.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];

        $fh = null;
        if ($method === 'PUT') {
            $fh = fopen((string) $filePath, 'rb');
            if ($fh === false) {
                return ['ok' => false, 'status' => 0, 'etag' => null, 'sse' => null, 'error' => 'impossibile aprire il file', 'retriable' => false];
            }
            $opts[CURLOPT_PUT] = true;
            $opts[CURLOPT_INFILE] = $fh;
            $opts[CURLOPT_INFILESIZE] = $size;
        } elseif ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }

        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $errno = curl_errno($ch);
        if ($fh !== null) {
            fclose($fh);
        }
        // curl_close() è deprecato (no-op) da PHP 8.5: la risorsa si libera da sola.

        $sse = $responseHeaders['x-amz-server-side-encryption'] ?? null;
        $contentLength = isset($responseHeaders['content-length']) ? (int) $responseHeaders['content-length'] : null;

        if ($body === false || $curlErr !== '') {
            // errori di rete/timeout sono ritentabili
            return [
                'ok' => false, 'status' => $status, 'etag' => null, 'sse' => $sse,
                'error' => "cURL({$errno}): {$curlErr}", 'retriable' => true, 'contentLength' => $contentLength,
            ];
        }

        if ($status >= 200 && $status < 300) {
            $etag = isset($responseHeaders['etag']) ? trim($responseHeaders['etag'], '"') : null;
            return ['ok' => true, 'status' => $status, 'etag' => $etag, 'sse' => $sse, 'error' => null, 'retriable' => false, 'contentLength' => $contentLength];
        }

        // Le richieste HEAD non hanno corpo: il codice d'errore di S3 arriva
        // negli header x-amz-error-code / x-amz-error-message. Usiamoli come
        // fallback quando il body è vuoto (es. errori su HeadObject).
        $err = is_string($body) ? $this->parseS3Error($body) : '';
        if ($err === '' && isset($responseHeaders['x-amz-error-code'])) {
            $err = trim($responseHeaders['x-amz-error-code'] . ' ' . ($responseHeaders['x-amz-error-message'] ?? ''));
        }
        // AWS suggerisce spesso la region corretta in questo header
        if (isset($responseHeaders['x-amz-bucket-region'])) {
            $err = trim($err . ' [region bucket: ' . $responseHeaders['x-amz-bucket-region'] . ']');
        }
        if ($err === '') {
            $err = 'errore sconosciuto (nessun dettaglio nella risposta)';
        }
        // 5xx e 429 ritentabili; 4xx (403 credenziali, 400 richiesta) NO.
        $retriable = $status >= 500 || $status === 429;
        return ['ok' => false, 'status' => $status, 'etag' => null, 'sse' => $sse, 'error' => "HTTP {$status}: {$err}", 'retriable' => $retriable, 'contentLength' => $contentLength];
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
