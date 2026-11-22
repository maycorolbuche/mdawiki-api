<?php
declare(strict_types=1);

namespace MDAWiki;

use Closure;
use Throwable;

final class WikiMail
{
    public function __construct(private array $config, private Closure $send) {}

    public function security(string $recipient, string $subject, string $message, ?string $code = null, ?string $url = null): void
    {
        $content = '<p>' . self::escape($message) . '</p>';
        if ($code !== null) $content .= '<p style="font-size:22px;font-weight:bold">' . self::escape($code) . '</p>';
        if ($url !== null) {
            $content .= '<p><a style="display:inline-block;background:#164a77;color:#fff;padding:10px 18px;border-radius:6px" href="'
                . self::escape($url) . '">Continuar no MDAWiki</a></p>';
        }
        try {
            ($this->send)($recipient, $subject, $this->layout($subject, $content), ['html' => true, 'plain' => implode("\n\n", array_filter([$message, $code, $url]))]);
        } catch (Throwable) {
            throw new HttpError(503, 'mail_delivery_failed', 'Não foi possível enviar o e-mail. Tente novamente.');
        }

        $copies = array_filter($this->recipients('user_copy_to'), static fn ($address) => strcasecmp($address, $recipient) !== 0);
        if ($copies) {
            // A mensagem separada preserva o destinatário original no cabeçalho sem compartilhar o segredo.
            $preview = '<p>' . self::escape($message) . '</p>' . ($code === null ? '' : '<p>[Código ocultado]</p>')
                . ($url === null ? '' : '<p>[Link de acesso ocultado]</p>');
            $this->optional('', $subject, $preview, implode(',', $copies), $recipient);
        }
    }

    public function event(string $type, string $action, string $name, array $actor, array $details = [], ?string $image = null, ?string $path = null): void
    {
        $recipients = $this->recipients('admin_to');
        if (!$recipients) return;
        $subject = '[' . $type . '][' . $action . '] ' . trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
        $details = ['Responsável' => $actor['NOME'] ?? $actor['EMAIL'] ?? '', 'Data e hora' => date('d/m/Y H:i:s T')] + $details;
        $content = '';
        foreach ($details as $label => $value) {
            if ($value === null || $value === '') continue;
            $content .= '<p><strong>' . self::escape((string) $label) . '</strong><br>'
                . nl2br(self::escape((string) $value)) . '</p>';
        }
        if ($image && preg_match('~^https?://~i', $image)) {
            $content .= '<img src="' . self::escape($image) . '" alt="Prévia" width="240" style="max-width:100%;height:auto">';
        }
        if ($path !== null) {
            $label = match ($type) {
                'img' => 'Abrir imagem',
                'user' => 'Abrir perfil',
                default => 'Abrir página',
            };
            $url = rtrim($this->config['frontend_url'], '/') . $path;
            $content .= '<p><a style="display:inline-block;background:#164a77;color:#fff;padding:10px 18px;border-radius:6px" href="'
                . self::escape($url) . '">' . $label . '</a></p>';
        }
        $this->optional(implode(',', $recipients), $subject, $content);
    }

    private function optional(string $to, string $subject, string $content, string $bcc = '', string $originalTo = ''): void
    {
        $body = $this->layout($subject, $content);
        $options = ['html' => true, 'bcc' => $bcc, 'original_to' => $originalTo];
        try {
            ($this->send)($to, $subject, $body, $options);
        } catch (Throwable) {
            try {
                (new MailOutbox($this->config['storage_dir'] . '/mail-outbox', $this->send))
                    ->retryLater($to, $subject, $body, $options);
            } catch (Throwable) {
                error_log('MDAWiki: falha ao colocar e-mail opcional na fila.');
            }
        }
    }

    private function recipients(string $key): array
    {
        $addresses = [];
        foreach (preg_split('/[;,]/', $this->config['mail'][$key] ?? '') as $value) {
            $address = trim($value);
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) $addresses[strtolower($address)] = $address;
        }
        return $addresses;
    }

    private function layout(string $subject, string $content): string
    {
        $site = self::escape(rtrim($this->config['frontend_url'], '/'));
        return '<!doctype html><html lang="pt-BR"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<body style="margin:0;background:#eff3f7;color:#23354b;font:16px Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:20px 10px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:white;border-radius:12px">'
            . '<tr><td style="padding:24px;border-bottom:3px solid #164a77"><a href="' . $site . '">'
            . '<img src="cid:mdawiki-logo" alt="MDAWiki" width="160" style="max-width:100%;height:auto;border:0"></a></td></tr>'
            . '<tr><td style="padding:24px;line-height:1.55;overflow-wrap:anywhere"><h1 style="font-size:24px;margin:0 0 16px">'
            . self::escape($subject) . '</h1>' . $content . '</td></tr>'
            . '<tr><td style="padding:16px 24px;background:#f4f7fa;font-size:13px;color:#496078">Mensagem automática · MDAWiki</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
