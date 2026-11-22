<?php
declare(strict_types=1);
namespace MDAWiki;
final class Mailer
{
    public function __construct(private array $config, private ?\Closure $factory = null, private ?SystemErrorLog $errors = null) {}
    public function __invoke(string $to, string $subject, string $text, array $options = []): void {
        $mail = null;
        $stage = 'configuration';
        try {
            $this->deliver($to, $subject, $text, $options, $mail, $stage);
        } catch (\Throwable $error) {
            $smtp = $mail?->getSMTPInstance()->getError() ?? [];
            $code = $error instanceof HttpError ? $error->errorCode : match ($stage) {
                'configuration' => 'mail_configuration_failed',
                'message' => 'mail_message_failed',
                default => match (true) {
                    str_contains($error->getMessage(), 'authenticate') => 'smtp_authentication_failed',
                    str_contains($error->getMessage(), 'connect') => 'smtp_connection_failed',
                    str_contains($error->getMessage(), 'recipients failed') => 'smtp_recipient_rejected',
                    str_contains($error->getMessage(), 'data not accepted') => 'smtp_message_rejected',
                    default => 'smtp_delivery_failed',
                },
            };
            $message = match ($code) {
                'smtp_authentication_failed' => 'Falha na autenticação SMTP.',
                'smtp_connection_failed' => 'Falha ao estabelecer a conexão SMTP.',
                'smtp_recipient_rejected' => 'O servidor SMTP recusou o destinatário.',
                'smtp_message_rejected' => 'O servidor SMTP recusou a mensagem.',
                'mail_transport_unavailable' => 'PHPMailer indisponível. Confira a instalação das dependências do Composer.',
                'mail_not_configured' => 'SMTP_HOST ou SMTP_FROM ausente.',
                'mail_message_failed' => 'Falha na preparação da mensagem, do remetente ou dos destinatários.',
                default => 'Falha no envio de e-mail.',
            };
            // SMTP replies can echo message content, recipient addresses or secrets.
            $this->errors?->record('mail', 'send', $code, $message, [
                'stage' => $stage,
                'port' => (int) ($this->config['port'] ?? 0),
                'user_copy' => !empty($options['original_to']),
                'attempt' => (int) ($options['attempt'] ?? 1),
                'smtp_code' => preg_match('/^\d{3}$/D', (string) ($smtp['smtp_code'] ?? '')) ? (int) $smtp['smtp_code'] : null,
            ]);
            throw $error;
        }
    }

    private function deliver(string $to, string $subject, string $text, array $options,
        ?\PHPMailer\PHPMailer\PHPMailer &$mail, string &$stage): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        $config=$this->config;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new HttpError(503, 'mail_transport_unavailable', 'O transporte de e-mail não está disponível.');
        }
        if (empty($config['host']) || empty($config['from'])) throw new HttpError(503, 'mail_not_configured', 'O envio de e-mail ainda não foi configurado na API.');
        $mail = $this->factory ? ($this->factory)() : new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->SMTPDebug=0; $mail->Host = $config['host']; $mail->Port = $config['port']; $mail->SMTPSecure = $config['secure'] === 'none' ? '' : $config['secure'];
        $mail->SMTPAutoTLS = $config['secure'] !== 'none';
        $mail->SMTPAuth = $config['auth'] ?? $config['username'] !== ''; $mail->Username = $config['username']; $mail->Password = $config['password'];
        $stage = 'message';
        $mail->CharSet = 'UTF-8'; $mail->Timeout = 15; $mail->setFrom($config['from'], $config['from_name'] ?? 'MDAWiki');
        foreach (['addAddress'=>$to,'addCC'=>$options['cc'] ?? '', 'addBCC'=>$options['bcc'] ?? ''] as $method=>$list) {
            foreach (preg_split('/[;,]/', $list, flags: PREG_SPLIT_NO_EMPTY) as $address) {
                if (trim($address) !== '') $mail->$method(trim($address));
            }
        }
        $mail->isHTML(($options['html'] ?? false)===true);
        if ($options['original_to'] ?? '') $mail->addCustomHeader('X-Original-To', $options['original_to']);
        if (str_contains($text, 'cid:mdawiki-logo')) {
            $mail->addEmbeddedImage(dirname(__DIR__) . '/resources/mail-logo.png', 'mdawiki-logo');
        }
        $mail->Subject = $subject; $mail->Body = $text;
        if ($options['html'] ?? false) $mail->AltBody=$options['plain'] ?? html_entity_decode(strip_tags(preg_replace('~</(?:p|h1|td)>|<br\s*/?>~i', "\n", $text)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $stage = 'smtp';
        if (!$mail->send()) throw new \RuntimeException($mail->ErrorInfo);
    }
}
