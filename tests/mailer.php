<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';
function checkTransport(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=new MDAWiki\Database(new PDO('sqlite::memory:'));
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
foreach (['Host'=>'legacy-smtp.example.test','Port'=>'465','Username'=>'legacy-user','Password'=>'test-secret','SetFrom_address'=>'noreply@example.test','SetFrom_name'=>'Wiki legado'] as $key=>$value) $db->run('INSERT INTO EMAIL_CONFIG (IDENTIF,CHAVE,VALOR) VALUES (?,?,?)',['profile-smtp',$key,$value]);
$captured=null;
$factory=function() use (&$captured) {
    return $captured=new class(true) extends PHPMailer\PHPMailer\PHPMailer {
        public function send(): bool { return true; } // No SMTP/network traffic in tests.
    };
};
$mailer=new MDAWiki\Mailer(['host'=>'env.example.test','port'=>587,'secure'=>'tls','username'=>'env','password'=>'test','from'=>'env@example.test'],$db,$factory);
$mailer('Pessoa <person@example.test>;other@example.test','Ação atualizada','<p>Olá, usuário</p>',['html'=>true,'cc'=>'copy@example.test','bcc'=>'hidden@example.test','ident'=>'profile-smtp']);
checkTransport($captured->Host==='legacy-smtp.example.test' && $captured->Port===465 && $captured->SMTPSecure==='ssl','Uses SMTP settings for the template IDENTIF');
checkTransport($captured->FromName==='Wiki legado' && $captured->ContentType==='text/html' && $captured->CharSet==='UTF-8','Legacy sender and HTML encoding');
checkTransport(count($captured->getToAddresses())===2 && count($captured->getCcAddresses())===1 && count($captured->getBccAddresses())===1,'To, CC and BCC reach PHPMailer');
checkTransport($captured->AltBody==='Olá, usuário','HTML mail also has plain text alternative');
$mailer('plain@example.test','Fallback','Texto sem HTML');
checkTransport($captured->Host==='env.example.test' && $captured->ContentType==='text/plain','Per-template settings do not leak into later plain account messages');
echo "PHPMailer SMTP identity, HTML, recipients and fallback checks passed without network.\n";
