<?php
declare(strict_types=1);
namespace MDAWiki;
final class Mailer
{
    public function __construct(private array $config, private ?\Closure $factory = null) {}
    public function __invoke(string $to, string $subject, string $text, array $options = []): void {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        $config=$this->config;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class) || empty($config['host']) || empty($config['from'])) throw new HttpError(503, 'mail_not_configured', 'O envio de e-mail ainda não foi configurado na API.');
        $mail = $this->factory ? ($this->factory)() : new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->SMTPDebug=0; $mail->Host = $config['host']; $mail->Port = $config['port']; $mail->SMTPSecure = $config['secure'] === 'none' ? '' : $config['secure'];
        $mail->SMTPAutoTLS = $config['secure'] !== 'none';
        $mail->SMTPAuth = $config['auth'] ?? $config['username'] !== ''; $mail->Username = $config['username']; $mail->Password = $config['password'];
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
        $mail->send();
    }
}
