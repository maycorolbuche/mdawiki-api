<?php
declare(strict_types=1);

use MDAWiki\{Mailer, MailOutbox};

if (PHP_SAPI !== 'cli') exit(1);
$config = require dirname(__DIR__) . '/config/bootstrap.php';
$outbox = new MailOutbox($config['storage_dir'] . '/mail-outbox', Closure::fromCallable(new Mailer($config['mail'])));
$result = $outbox->dispatch();
echo 'E-mails: ' . $result['sent'] . ' enviados de ' . $result['checked'] . " tentativas.\n";
