<?php
declare(strict_types=1);

namespace MDAWiki;

use Closure;
use RuntimeException;
use Throwable;

final class MailOutbox
{
    public function __construct(private string $directory, private Closure $send) {}

    public function retryLater(string $to, string $subject, string $body, array $options): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Não foi possível criar a fila de e-mails.');
        }
        $file = tempnam($this->directory, 'pending-');
        if ($file === false) throw new RuntimeException('Não foi possível gravar na fila de e-mails.');
        try {
            $data = json_encode(compact('to', 'subject', 'body', 'options') + ['attempts' => 1], JSON_THROW_ON_ERROR);
            if (file_put_contents($file, $data) !== strlen($data) || !rename($file, $file . '.json')) {
                throw new RuntimeException('Não foi possível gravar na fila de e-mails.');
            }
        } finally {
            if (is_file($file)) unlink($file);
        }
    }

    public function dispatch(): array
    {
        $result = ['checked' => 0, 'sent' => 0];
        if (!is_dir($this->directory)) return $result;
        $lock = fopen($this->directory . '/dispatch.lock', 'c');
        if ($lock === false) throw new RuntimeException('Não foi possível bloquear a fila de e-mails.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return $result;
            foreach (glob($this->directory . '/pending-*.json') ?: [] as $file) {
                if ($result['checked'] >= 50) break;
                if (filemtime($file) > time() - 300) continue;
                $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if ($data['attempts'] >= 10) continue;
                $result['checked']++;
                try {
                    ($this->send)($data['to'], $data['subject'], $data['body'], $data['options'] + ['attempt' => $data['attempts'] + 1]);
                } catch (Throwable) {
                    $data['attempts']++;
                    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
                    continue;
                }
                unlink($file);
                $result['sent']++;
            }
        } finally {
            fclose($lock);
        }
        return $result;
    }
}
