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

    private function request(string $method, string $key, ?string $bytes = null): ?string
    {
        ['account_id' => $account, 'bucket' => $bucket, 'access_key' => $access, 'secret_key' => $secret] =
            $this->r2 + ['account_id' => '', 'bucket' => '', 'access_key' => '', 'secret_key' => ''];
        if (!defined('CURLOPT_AWS_SIGV4') || !preg_match('/^[a-f0-9]{32}$/D', $account)
            || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $bucket) || $access === '' || $secret === '') {
            throw new \RuntimeException('O R2 requer credenciais S3 e PHP cURL com suporte a AWS SigV4.');
        }
        $path = implode('/', array_map('rawurlencode', explode('/', $key)));
        $curl = curl_init("https://$account.r2.cloudflarestorage.com/$bucket/$path");
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
