<?php
declare(strict_types=1);
namespace MDAWiki;
final class RateLimiter
{
    public function __construct(private string $directory) {}
    public function check(string $key, int $maximum = 15, int $window = 900): void {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new \RuntimeException('Cannot create rate-limit directory');
        $f = fopen($this->directory . '/' . hash('sha256', $key) . '.json', 'c+');
        if (!$f || !flock($f, LOCK_EX)) throw new \RuntimeException('Cannot lock rate limit');
        try {
            $times = array_values(array_filter(json_decode(stream_get_contents($f), true) ?: [], static fn ($t) => $t > time() - $window));
            if (count($times) >= $maximum) throw new HttpError(429, 'rate_limited', 'Muitas tentativas. Tente novamente mais tarde.');
            $times[] = time(); rewind($f); ftruncate($f, 0); fwrite($f, json_encode($times));
        } finally { flock($f, LOCK_UN); fclose($f); }
    }
}
