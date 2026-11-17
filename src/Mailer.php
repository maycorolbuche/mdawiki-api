<?php
declare(strict_types=1);
namespace MDAWiki;
final class Mailer
{
    public function __construct(private array $config) {}
    public function __invoke(string $to, string $subject, string $text): void {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class) || empty($this->config['host']) || empty($this->config['from'])) throw new HttpError(503, 'mail_not_configured', 'O envio de e-mail ainda não foi configurado na API.');
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host = $this->config['host']; $mail->Port = $this->config['port']; $mail->SMTPSecure = $this->config['secure'];
        $mail->SMTPAuth = $this->config['username'] !== ''; $mail->Username = $this->config['username']; $mail->Password = $this->config['password'];
        $mail->CharSet = 'UTF-8'; $mail->Timeout = 15; $mail->setFrom($this->config['from'], 'MDAWiki'); $mail->addAddress($to); $mail->Subject = $subject; $mail->Body = $text; $mail->send();
    }
}
