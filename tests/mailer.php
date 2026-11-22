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

$logDb = new MDAWiki\Database(new PDO('sqlite::memory:'));
$logDb->pdo->exec('CREATE TABLE system_error_logs (component TEXT, operation TEXT, error_code TEXT, message TEXT, context TEXT)');
$logger = new MDAWiki\SystemErrorLog(static fn () => $logDb);
$missing = new MDAWiki\Mailer(['port' => 587], errors: $logger);
try {
    $missing('private@example.test', 'Assunto', 'Corpo');
    throw new RuntimeException('Expected missing SMTP configuration');
} catch (MDAWiki\HttpError $error) {
    checkTransport($error->errorCode === 'mail_not_configured', 'Preserves the transport error for callers');
}

$failure = static fn () => new class(true) extends PHPMailer\PHPMailer\PHPMailer {
    public function send(): bool
    {
        throw new RuntimeException('SMTP Error: Could not authenticate. user=private@example.test password=private-secret');
    }
};
$offline = new MDAWiki\Mailer([
    'host' => 'smtp.example.test', 'port' => 465, 'secure' => 'ssl', 'username' => 'private@example.test',
    'password' => 'private-secret', 'from' => 'sender@example.test',
], $failure, $logger);
try {
    $offline('', 'Assunto', 'Conteúdo privado', ['bcc' => 'hidden@example.test', 'original_to' => 'private@example.test', 'attempt' => 2]);
    throw new LogicException('Expected SMTP failure');
} catch (RuntimeException $error) {
    checkTransport(str_contains($error->getMessage(), 'Could not authenticate'), 'Preserves the original SMTP error');
}
$errors = $logDb->rows('SELECT * FROM system_error_logs');
checkTransport(array_column($errors, 'error_code') === ['mail_not_configured', 'smtp_authentication_failed'], 'Logs configuration and SMTP errors');
$context = json_decode($errors[1]['context'], true);
checkTransport($context['user_copy'] === true && $context['attempt'] === 2 && $context['port'] === 465, 'Logs safe retry and transport metadata');
checkTransport(!str_contains(json_encode($errors), 'private') && !str_contains(json_encode($errors), 'hidden@example.test'), 'Keeps SMTP secrets and recipients out of logs');
