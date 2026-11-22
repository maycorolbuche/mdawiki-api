<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';
function checkTransport(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$captured=null;
$factory=function() use (&$captured) {
    return $captured=new class(true) extends PHPMailer\PHPMailer\PHPMailer {
        public function send(): bool { return true; } // No SMTP/network traffic in tests.
    };
};
$mailer=new MDAWiki\Mailer(['host'=>'env.example.test','port'=>587,'secure'=>'tls','username'=>'env','password'=>'test','from'=>'env@example.test'],$factory);
$mailer('person@example.test;other@example.test','Ação atualizada','<p>Olá, usuário</p>',['html'=>true,'cc'=>'copy@example.test','bcc'=>'hidden@example.test']);
checkTransport($captured->Host==='env.example.test' && $captured->Port===587 && $captured->SMTPSecure==='tls','Uses SMTP settings from the environment');
checkTransport($captured->FromName==='MDAWiki' && $captured->ContentType==='text/html' && $captured->CharSet==='UTF-8','Wiki sender and HTML encoding');
checkTransport(count($captured->getToAddresses())===2 && count($captured->getCcAddresses())===1 && count($captured->getBccAddresses())===1,'To, CC and BCC reach PHPMailer');
checkTransport(trim($captured->AltBody)==='Olá, usuário','HTML mail also has plain text alternative');
$mailer('plain@example.test','Fallback','Texto sem HTML');
checkTransport($captured->Host==='env.example.test' && $captured->ContentType==='text/plain','Per-template settings do not leak into later plain account messages');
$mailer('', 'Cópia', '<img src="cid:mdawiki-logo"><p>Prévia</p>', [
    'html' => true, 'bcc' => 'hidden@example.test;second@example.test', 'original_to' => 'person@example.test',
]);
checkTransport($captured->preSend(), 'Blind copy produces valid SMTP MIME');
$mime = $captured->getSentMIMEMessage();
checkTransport(count($captured->getBccAddresses()) === 2 && !$captured->getToAddresses()
    && !str_contains($mime, 'hidden@example.test') && !str_contains($mime, 'second@example.test')
    && str_contains($mime, 'X-Original-To: person@example.test')
    && str_contains($mime, 'Content-ID: <mdawiki-logo>'), 'Blind recipients stay out of MIME while the logo is embedded');
echo "PHPMailer SMTP identity, HTML, recipients and fallback checks passed without network.\n";
