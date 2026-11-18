<?php
declare(strict_types=1);
namespace MDAWiki;
final class Mailer
{
    public function __construct(private array $config, private ?Database $db = null) {}
    public function __invoke(string $to, string $subject, string $text): void {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (empty($this->config['host']) && $this->db) {
            $ident=$this->db->one('SELECT IDENTIF FROM EMAIL_MSG WHERE TIPO_EMAIL = ?',['USU_CADASTRO_ATIVAR'])['IDENTIF'] ?? null;
            if ($ident) {
                $old=[];foreach($this->db->rows('SELECT CHAVE,VALOR FROM EMAIL_CONFIG WHERE IDENTIF = ?',[$ident]) as $item) $old[$item['CHAVE']]=$item['VALOR'];
                $this->config=['host'=>$old['Host'] ?? '', 'port'=>(int)($old['Port'] ?? 587),'secure'=>(int)($old['Port'] ?? 587)===465?'ssl':'tls','username'=>$old['Username'] ?? '','password'=>$old['Password'] ?? '','from'=>$old['SetFrom_address'] ?? ''];
            }
        }
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class) || empty($this->config['host']) || empty($this->config['from'])) throw new HttpError(503, 'mail_not_configured', 'O envio de e-mail ainda não foi configurado na API.');
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host = $this->config['host']; $mail->Port = $this->config['port']; $mail->SMTPSecure = $this->config['secure'];
        $mail->SMTPAuth = $this->config['username'] !== ''; $mail->Username = $this->config['username']; $mail->Password = $this->config['password'];
        $mail->CharSet = 'UTF-8'; $mail->Timeout = 15; $mail->setFrom($this->config['from'], 'MDAWiki'); $mail->addAddress($to); $mail->Subject = $subject; $mail->Body = $text; $mail->send();
    }
}
