<?php
declare(strict_types=1);

namespace MDAWiki;

final class MediaStorage
{
    private string $driver;
    private array $r2;

    public function __construct(private array $config)
    {
        $this->driver = $config['media_driver'] ?? 'local';
        $this->r2 = $config['media_r2'] ?? [];
        if (!in_array($this->driver, ['local', 'r2'], true)) {
            throw new \RuntimeException('MEDIA_STORAGE_DRIVER deve ser local ou r2.');
        }
    }

    public static function validKey(string $key): bool
    {
        return !preg_match('/[\\x00-\\x1f\\x7f\\\\\\\\]/', $key)
            && !array_intersect(explode('/', $key), ['', '.', '..']);
    }

    public static function contentType(string $key): ?string
    {
        return match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
            default => null,
        };
    }

    public function read(string $key): ?string
    {
        if (!self::validKey($key)) return null;
        return $this->driver === 'r2'
            ? $this->request('GET', $key) ?? $this->readLocal($key)
            : $this->readLocal($key) ?? (empty($this->r2['bucket']) ? null : $this->request('GET', $key));
    }

    private function localPath(string $key): ?string
    {
        $root = realpath($this->config['media_root']);
        $path = $root === false ? false : realpath($root . '/' . $key);
        return $path !== false && $path === $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key) && is_file($path)
            ? $path : null;
    }

    private function readLocal(string $key): ?string
    {
        $path = $this->localPath($key);
        if ($path === null) return null;
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException('Não foi possível ler o arquivo de mídia.');
        return $bytes;
    }

    public function write(string $key, string $bytes): void
    {
        if (!self::validKey($key)) throw new \RuntimeException('Chave de mídia inválida.');
        if ($this->driver === 'r2') {
            $this->request('PUT', $key, $bytes);
            return;
        }
        $root = $this->config['media_root'];
        $directory = $root . '/' . dirname($key);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível criar o diretório de mídia.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !str_starts_with($resolved . DIRECTORY_SEPARATOR, realpath($root) . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Diretório de mídia inválido.');
        }
        $temp = tempnam($resolved, '.upload-');
        if ($temp === false) throw new \RuntimeException('Não foi possível preparar o arquivo de mídia.');
        try {
            if (file_put_contents($temp, $bytes) !== strlen($bytes) || !chmod($temp, 0640)
                || !rename($temp, $resolved . '/' . basename($key))) {
                throw new \RuntimeException('Não foi possível salvar o arquivo de mídia.');
            }
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }

    public function delete(string $key): void
    {
        if (!self::validKey($key)) return;
        if ($this->driver === 'r2') {
            $this->request('DELETE', $key);
        } elseif (($path = $this->localPath($key)) !== null) {
            unlink($path);
        }
    }

    public static function withWriteLock(string $directory, callable $action): mixed
    {
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Armazenamento de mídia indisponível.');
        }
        $lock = fopen($directory . '/media-write.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Bloqueio de mídia indisponível.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Bloqueio de mídia indisponível.');
            return $action();
        } finally {
            fclose($lock);
        }
    }

    public function inventory(): array
    {
        $locations = ['local' => []];
        $root = $this->config['media_root'];
        if (is_dir($root)) {
            $root = realpath($root);
            if ($root === false || !is_readable($root)) throw new \RuntimeException('Diretório de mídia indisponível.');
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                if (self::managedKey($key)) $locations['local'][$key] = $file->getSize();
            }
        } elseif (file_exists($root)) {
            throw new \RuntimeException('Diretório de mídia indisponível.');
        }
        if ($this->hasRemote()) {
            $locations['r2'] = [];
            $token = null;
            do {
                $query = ['list-type' => '2', 'encoding-type' => 'url'];
                if ($token !== null) $query['continuation-token'] = $token;
                $doc = new \DOMDocument();
                if (!@$doc->loadXML($this->request('GET', '', null, $query), LIBXML_NONET)
                    || $doc->doctype || $doc->documentElement->localName !== 'ListBucketResult') {
                    throw new \RuntimeException('Resposta de inventário inválida no R2.');
                }
                foreach ($doc->getElementsByTagName('Contents') as $object) {
                    $key = rawurldecode($object->getElementsByTagName('Key')->item(0)->textContent);
                    if (self::managedKey($key)) $locations['r2'][$key] = (int) $object->getElementsByTagName('Size')->item(0)->textContent;
                }
                $truncated = $doc->getElementsByTagName('IsTruncated')->item(0)?->textContent;
                if (!in_array($truncated, ['true', 'false'], true)) throw new \RuntimeException('Inventário incompleto no R2.');
                $more = $truncated === 'true';
                $next = $doc->getElementsByTagName('NextContinuationToken')->item(0)?->textContent;
                if ($more && (!$next || $next === $token)) throw new \RuntimeException('Paginação incompleta no R2.');
                $token = $next;
            } while ($more);
        }
        return $locations;
    }

    public static function managedKey(string $key): bool
    {
        return self::validKey($key) && substr_count($key, '/') === 1
            && preg_match('~^(articles|users|wiki|wiki_mini|wiki_cp)/~D', $key)
            && self::contentType($key) !== null;
    }

    private function hasRemote(): bool
    {
        return $this->driver === 'r2' || !empty($this->r2['bucket']);
    }

    public function locations(string $key): array
    {
        $locations = $this->localPath($key) === null ? [] : ['local'];
        if ($this->hasRemote() && $this->request('GET', $key) !== null) $locations[] = 'r2';
        return $locations;
    }

    public function readAt(string $key, ?string $location): ?string
    {
        return match ($location) {
            null => $this->read($key),
            'local' => $this->readLocal($key),
            'r2' => $this->hasRemote() ? $this->request('GET', $key) : null,
            default => null,
        };
    }

    public function deleteAt(string $key, string $location): void
    {
        if (!self::managedKey($key)) throw new \RuntimeException('Chave de mídia inválida.');
        if ($location === 'r2' && $this->hasRemote()) {
            $this->request('DELETE', $key);
        } elseif ($location === 'local') {
            $path = $this->localPath($key);
            if ($path !== null && !unlink($path)) throw new \RuntimeException('Falha ao excluir a mídia local.');
        } else {
            throw new \RuntimeException('Armazenamento indisponível.');
        }
    }

    private function request(string $method, string $key, ?string $bytes = null, array $query = []): ?string
    {
        ['account_id' => $account, 'bucket' => $bucket, 'access_key' => $access, 'secret_key' => $secret] =
            $this->r2 + ['account_id' => '', 'bucket' => '', 'access_key' => '', 'secret_key' => ''];
        if (!defined('CURLOPT_AWS_SIGV4') || !preg_match('/^[a-f0-9]{32}$/D', $account)
            || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $bucket) || $access === '' || $secret === '') {
            throw new \RuntimeException('O R2 requer credenciais S3 e PHP cURL com suporte a AWS SigV4.');
        }
        $path = implode('/', array_map('rawurlencode', explode('/', $key)));
        $url = "https://$account.r2.cloudflarestorage.com/$bucket/$path";
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        $headers = ['x-amz-content-sha256: ' . hash('sha256', $bytes ?? '')];
        if ($method === 'PUT') {
            $headers[] = 'Content-Type: ' . (self::contentType($key) ?? 'application/octet-stream');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $bytes);
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_AWS_SIGV4 => 'aws:amz:auto:s3',
            CURLOPT_USERPWD => $access . ':' . $secret,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($method === 'GET' && $status === 404 && is_string($response)
            && str_contains($response, '<Code>NoSuchKey</Code>')) return null;
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Falha de mídia no R2 (' . $method . ', HTTP ' . $status
                . ', cURL ' . curl_errno($curl) . ').');
        }
        return $response;
    }
}
