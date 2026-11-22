<?php
declare(strict_types=1);

use MDAWiki\{Accounts, Database, HttpError, Pages, Session, WikiMail, WikiRenderer};

require dirname(__DIR__) . '/config/bootstrap.php';

function checkMail(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$mysql = getenv('TEST_MYSQL_DSN');
$pdo = $mysql ? new PDO($mysql, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: 'test') : new PDO('sqlite::memory:');
$schema = $mysql ? 'schema.mysql.sql' : 'schema.sqlite.sql';
foreach (explode(';', file_get_contents(__DIR__ . '/' . $schema)) as $sql) {
    if (trim($sql) !== '') $pdo->exec($sql);
}
$db = new Database($pdo);
$config = [
    'frontend_url' => 'https://site.mda.wiki.br', 'media_public_url' => 'https://api.mda.wiki.br/media',
    'public_max_level' => 1, 'storage_dir' => sys_get_temp_dir() . '/mdawiki-mail-' . bin2hex(random_bytes(5)),
    'app_key' => str_repeat('test-key-', 8),
    'mail' => ['user_copy_to' => 'copy@example.test; other@example.test, COPY@example.test',
        'admin_to' => 'admin@example.test; staff@example.test'],
];
$config['media_root'] = $config['storage_dir'] . '/media';
$messages = [];
$fail = false;
$send = static function (string $to, string $subject, string $body, array $options) use (&$messages, &$fail): void {
    if ($fail) throw new RuntimeException('SMTP offline');
    $messages[] = compact('to', 'subject', 'body', 'options');
};
$mail = new WikiMail($config, $send);
$accounts = new Accounts($db, new Session(), $config, $send, $mail);

$email = 'mail-new@example.test';
$registration = ['name' => 'Nome <Teste>', 'email' => $email,
    'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
$accounts->register($registration);
$user = $db->one('SELECT * FROM USUARIOS WHERE EMAIL = ?', [$email]);
checkMail(count($messages) === 3, 'Sends activation, blind copy and admin notice');
[$personal, $copy, $admin] = $messages;
checkMail($personal['to'] === $email && str_contains($personal['body'], $user['CODIGO_CONTA']), 'User receives their code');
checkMail($copy['to'] === '' && strtolower($copy['options']['bcc']) === 'copy@example.test,other@example.test', 'Copies use hidden recipients with deduplication');
checkMail($copy['options']['original_to'] === $email && !str_contains($copy['body'], $user['CODIGO_CONTA']), 'Preview identifies the original recipient and omits secrets');
checkMail($admin['subject'] === '[user][new] Nome <Teste>' && $admin['to'] === 'admin@example.test,staff@example.test', 'Administrative subject and recipients');
checkMail(str_contains($admin['body'], '&lt;Teste&gt;') && str_contains($admin['body'], $email)
    && !str_contains($admin['body'], $user['SENHA']), 'Administrative profile safely includes user data');
$accounts->activation(['code' => $user['CODIGO_CONTA']]);
$accounts->login(['email' => $email, 'password' => 'new-password-123']);
$accounts->update(['NOME' => 'Nome alterado']);
checkMail(end($messages)['subject'] === '[user][update] Nome alterado', 'Profile changes notify admins');
$pages = new Pages($db, $accounts, new WikiRenderer($db, $config), $config, $mail);
$page = $pages->save('Mail_article', ['title' => 'Artigo de teste', 'source' => 'Texto original', 'base_version' => '']);
checkMail(end($messages)['subject'] === '[page][new] Artigo de teste' && str_contains(end($messages)['body'], 'Texto original'), 'Page creation includes content');
$pages->save('Mail_article', ['title' => 'Artigo alterado', 'source' => 'Texto novo', 'base_version' => $page['base_version']]);
checkMail(end($messages)['subject'] === '[page][update] Artigo alterado', 'Page update notifies admins');
$accounts->requestCode(['email' => $email], true);
$copy = array_pop($messages);
$personal = array_pop($messages);
checkMail(str_contains($personal['options']['plain'], 'token=') && !str_contains($copy['body'], 'token='), 'Reset link stays in the personal message');

$fail = true;
$pages->save('Mail_article', ['title' => 'Artigo salvo', 'source' => 'SMTP offline', 'base_version' => Pages::version($pages->source('Mail_article'))]);
checkMail($pages->source('Mail_article')['CONTEUDO_CODE'] === 'SMTP offline', 'An optional mail failure preserves the update');
$directory = $config['storage_dir'] . '/mail-outbox';
$queued = glob($directory . '/pending-*.json');
checkMail(count($queued) === 1, 'A failed optional notification is queued for retry');
touch($queued[0], time() - 301);
clearstatcache();
$fail = false;
$outbox = new MDAWiki\MailOutbox($directory, $send);
checkMail($outbox->dispatch() === ['checked' => 1, 'sent' => 1], 'An optional notification can be retried by cron');
$fail = true;
try {
    $accounts->register(array_replace($registration, ['email' => 'mail-failed@example.test']));
    throw new RuntimeException('Expected delivery failure');
} catch (HttpError $error) {
    checkMail($error->errorCode === 'mail_delivery_failed', 'Required delivery errors are reported');
}
checkMail(!$db->one('SELECT ID FROM USUARIOS WHERE EMAIL = ?', ['mail-failed@example.test']), 'Required delivery failure rolls back registration');
echo "Mail account and event checks passed.\n";
