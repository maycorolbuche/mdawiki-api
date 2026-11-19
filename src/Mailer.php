<?php
declare(strict_types=1);
namespace MDAWiki;
final class Mailer
{
    public function __construct(private array $config, private ?Database $db = null, private ?\Closure $factory = null) {}
    public function __invoke(string $to, string $subject, string $text, array $options = []): void {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        $config=$this->config;
        if ($this->db && (isset($options['ident']) || empty($config['host']))) {
            $ident=$options['ident'] ?? $this->db->one('SELECT IDENTIF FROM EMAIL_MSG WHERE TIPO_EMAIL = ?',['USU_CADASTRO_ATIVAR'])['IDENTIF'] ?? null;
            if ($ident) {
                $old=[];foreach($this->db->rows('SELECT CHAVE,VALOR FROM EMAIL_CONFIG WHERE IDENTIF = ?',[$ident]) as $item) $old[$item['CHAVE']]=$item['VALOR'];
                if ($old) $config=['host'=>$old['Host'] ?? '', 'port'=>(int)($old['Port'] ?? 587),'secure'=>$old['SMTPSecure'] ?? ((int)($old['Port'] ?? 587)===465?'ssl':'tls'),'username'=>$old['Username'] ?? '','password'=>$old['Password'] ?? '','from'=>$old['SetFrom_address'] ?? '', 'from_name'=>$old['SetFrom_name'] ?? 'MDAWiki','auth'=>isset($old['SMTPAuth'])?filter_var($old['SMTPAuth'],FILTER_VALIDATE_BOOLEAN):true];
            }
        }
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class) || empty($config['host']) || empty($config['from'])) throw new HttpError(503, 'mail_not_configured', 'O envio de e-mail ainda não foi configurado na API.');
        $mail = $this->factory ? ($this->factory)() : new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->SMTPDebug=0; $mail->Host = $config['host']; $mail->Port = $config['port']; $mail->SMTPSecure = $config['secure'];
        $mail->SMTPAuth = $config['auth'] ?? $config['username'] !== ''; $mail->Username = $config['username']; $mail->Password = $config['password'];
        $mail->CharSet = 'UTF-8'; $mail->Timeout = 15; $mail->setFrom($config['from'], $config['from_name'] ?? 'MDAWiki');
        foreach (['addAddress'=>$to,'addCC'=>$options['cc'] ?? '', 'addBCC'=>$options['bcc'] ?? ''] as $method=>$list) {
            if (trim($list)==='') continue;
            $addresses=\PHPMailer\PHPMailer\PHPMailer::parseAddresses(str_replace(';',',',$list),true,'UTF-8');
            if (!$addresses) throw new HttpError(422,'invalid_mail_recipient','Destinatário inválido no modelo de e-mail.');
            foreach ($addresses as $address) $mail->$method($address['address'],$address['name']);
        }
        $mail->isHTML(($options['html'] ?? false)===true);
        $mail->Subject = $subject; $mail->Body = $text;
        if ($options['html'] ?? false) $mail->AltBody=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $mail->send();
    }
}
