<?php
declare(strict_types=1);
use MDAWiki\{Accounts,Database,Features,LegacyMail,Pages,Session,WikiRenderer};
require dirname(__DIR__).'/config/bootstrap.php';
function checkMail(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$mysql=getenv('TEST_MYSQL_DSN');
$pdo=$mysql?new PDO($mysql,getenv('TEST_MYSQL_USER') ?: 'root',getenv('TEST_MYSQL_PASSWORD') ?: 'test'):new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if ($mysql) { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);foreach(explode(';',file_get_contents(__DIR__.'/schema.mysql.sql')) as $sql) if(trim($sql)!=='') $pdo->exec($sql); }
else $pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db=new Database($pdo);
$config=['frontend_url'=>'https://site.mda.wiki.br','media_public_url'=>'https://api.mda.wiki.br/media','public_max_level'=>1,'storage_dir'=>sys_get_temp_dir().'/mdawiki-mail-'.bin2hex(random_bytes(5)),'app_key'=>str_repeat('test-key-',8)];
$config['media_root']=$config['storage_dir'].'/media';
$sent=[];$fail=false;
$transport=function(string $to,string $subject,string $body,array $options=[]) use (&$sent,&$fail) {
    if ($fail) throw new RuntimeException('SMTP offline');
    $sent[]=compact('to','subject','body','options');
};
$notifications=new LegacyMail($db,$config,$transport);
$events=['PAGE_ALT','USU_CADASTRO_ATIVAR','USU_CADASTRO_ATIVADO','USU_CADASTRO_ATIVAR_ENVCODIGO','USU_ALTERACAO','USU_ALTERACAO_SENHA','USU_ALTERACAO_EMAIL','USU_ALTERACAO_EMAIL_ENVCODIGO'];
foreach ($events as $type) {
    $db->run('INSERT INTO EMAIL_MSG (TIPO_EMAIL,DESCRICAO,ASSUNTO,MENSAGEM,IDENTIF) VALUES (?,?,?,?,?)',[$type,'Teste',$type.' <[NOME]>','<p><[NOME]> <[EMAIL]> <[PAGINA]> <[PAGINA_URL]> <[LINK]> <[URL_SITE]> <[CODIGO]> <[EMAIL_NOVO]></p>','mail-test']);
    $to=$type==='USU_ALTERACAO_EMAIL_ENVCODIGO'?'<[EMAIL_NOVO]>':(str_contains($type,'ATIVAR')?'<[EMAIL]>':'admin@example.test');
    $db->run('INSERT INTO EMAIL_ENVIO (TIPO_EMAIL,EMAIL,CC,CCO) VALUES (?,?,?,?)',[$type,$to,'audit@example.test','hidden@example.test']);
}
$accounts=new Accounts($db,new Session(),$config,$transport,$notifications);
$accounts->register(['name'=>'Novo usuário','email'=>'mail-new@example.test','password'=>'new-password-123','password_confirmation'=>'new-password-123']);
$created=$db->one('SELECT * FROM USUARIOS WHERE EMAIL = ?',['mail-new@example.test']);
checkMail(count($sent)===1 && $sent[0]['to']==='mail-new@example.test','Templated activation replaces the plain message, without duplicate delivery');
checkMail($sent[0]['options']['cc']==='audit@example.test' && $sent[0]['options']['bcc']==='hidden@example.test' && $sent[0]['options']['html']===true && $sent[0]['options']['ident']==='mail-test','Preserves CC, BCC, HTML and SMTP identity');
checkMail(str_contains($sent[0]['body'],$created['CODIGO_CONTA']) && str_contains($sent[0]['body'],'Novo usuário'),'Replaces account variables with UTF-8 and actual code');
$accounts->requestCode(['email'=>'mail-new@example.test'],false);
checkMail($db->one('SELECT ID FROM EMAIL_LOGS WHERE TIPO_EMAIL = ?',['USU_CADASTRO_ATIVAR_ENVCODIGO'])!==null,'Resend activation event');
$code=$db->one('SELECT CODIGO_CONTA FROM USUARIOS WHERE EMAIL = ?',['mail-new@example.test'])['CODIGO_CONTA'];
$accounts->activation(['code'=>$code]);
checkMail($db->one('SELECT ID FROM EMAIL_LOGS WHERE TIPO_EMAIL = ?',['USU_CADASTRO_ATIVADO'])!==null,'Account activated event');
$session=new Session();$accounts=new Accounts($db,$session,$config,$transport,$notifications);
$accounts->login(['email'=>'mail-new@example.test','password'=>'new-password-123']);
$accounts->update(['NOME'=>'Nome alterado']);
$accounts->changePassword(['current_password'=>'new-password-123','password'=>'changed-password','password_confirmation'=>'changed-password']);
$accounts->changeEmail(['email'=>'mail-changed@example.test']);
$code=$db->one('SELECT CODIGO_CONTA FROM USUARIOS WHERE EMAIL = ?',['mail-new@example.test'])['CODIGO_CONTA'];
$accounts->changeEmail(['email'=>'mail-changed@example.test','code'=>$code]);
foreach (['USU_ALTERACAO','USU_ALTERACAO_SENHA','USU_ALTERACAO_EMAIL','USU_ALTERACAO_EMAIL_ENVCODIGO'] as $type) checkMail($db->one('SELECT ID FROM EMAIL_LOGS WHERE TIPO_EMAIL = ?',[$type])!==null,'Missing event '.$type);

$pages=new Pages($db,$accounts,new WikiRenderer($db,$config),$config,$notifications);
$pages->save('Mail_article',['title'=>'Artigo de teste','source'=>'Texto original','base_version'=>'']);
$log=$db->one('SELECT * FROM EMAIL_LOGS WHERE TIPO_EMAIL = ? ORDER BY ID DESC',['PAGE_ALT']);
checkMail($log['ENVIADO']==='S' && (int)$log['TENTATIVAS_ENVIO']===1,'Article changes are logged and sent');
checkMail(str_contains($log['MENSAGEM'],'Artigo de teste') && str_contains($log['MENSAGEM'],'https://site.mda.wiki.br/Mail_article/'),'Page and canonical link variables');
$before=count($sent);$notifications->dispatch($log['UID']);checkMail(count($sent)===$before,'Sent logs are never replayed');

$db->run('UPDATE EMAIL_ENVIO SET ASSUNTO = ?, MENSAGEM = ? WHERE TIPO_EMAIL = ?',['Personalizado <[NOME]>','Conteúdo <[PAGINA]>','PAGE_ALT']);
$fail=true;
$pages->save('Mail_article',['title'=>'Artigo alterado','source'=>'Conteúdo salvo com SMTP offline','base_version'=>Pages::version($pages->source('Mail_article'))]);
$failed=$db->one('SELECT * FROM EMAIL_LOGS WHERE TIPO_EMAIL = ? ORDER BY ID DESC',['PAGE_ALT']);
checkMail(str_contains($pages->source('Mail_article')['CONTEUDO_CODE'],'SMTP offline'),'SMTP failures never undo a saved article');
checkMail($failed['ENVIADO']==='E' && $failed['RETORNO']==='SMTP offline' && (int)$failed['TENTATIVAS_ENVIO']===1,'Failure reason and attempt are retained');
checkMail(str_starts_with($failed['ASSUNTO'],'Personalizado') && str_contains($failed['MENSAGEM'],'Artigo alterado'),'Recipient overrides take precedence over defaults');
$fail=false;$notifications->dispatch();checkMail($db->one('SELECT ENVIADO FROM EMAIL_LOGS WHERE ID = ?',[(int)$failed['ID']])['ENVIADO']==='E','Retry respects cooldown');
$db->run('UPDATE EMAIL_LOGS SET DATAHORA_ENVIO = ? WHERE ID = ?',[date('Y-m-d H:i:s',time()-301),(int)$failed['ID']]);
$notifications->dispatch();
$retried=$db->one('SELECT * FROM EMAIL_LOGS WHERE ID = ?',[(int)$failed['ID']]);
checkMail($retried['ENVIADO']==='S' && (int)$retried['TENTATIVAS_ENVIO']===2,'Worker retries failed API messages');
$db->run('UPDATE USUARIOS SET ENVIA_EMAIL = ? WHERE EMAIL = ?',['N','mail-changed@example.test']);
$before=count($sent);$notifications->notify('PAGE_ALT','mail-changed@example.test','mail_article');checkMail(count($sent)===$before,'Optional notifications respect ENVIA_EMAIL=N');
$notifications->security('USU_ALTERACAO_EMAIL_ENVCODIGO','mail-changed@example.test','other@example.test','Código','Código obrigatório',['EMAIL_NOVO'=>'other@example.test','CODIGO'=>'123']);
checkMail(count($sent)===$before+1,'Essential codes are still delivered when optional mail is disabled');

$before=count($sent);
$notifications->security('UNCONFIGURED_EVENT','mail-changed@example.test','mail-changed@example.test','Ativação','Fallback');
checkMail(count($sent)===$before+1 && end($sent)['body']==='Fallback','Unconfigured templates retain current account flows');
$fail=true;
try { $accounts->register(['name'=>'Falha','email'=>'mail-failed@example.test','password'=>'new-password-123','password_confirmation'=>'new-password-123']); throw new RuntimeException('Expected failure'); }
catch (MDAWiki\HttpError $error) { checkMail($error->errorCode==='mail_delivery_failed','Required delivery reports failure'); }
checkMail($db->one('SELECT ID FROM USUARIOS WHERE EMAIL = ?',['mail-failed@example.test'])===null,'Required mail failure rolls back registration');
$fail=false;
$db->run('UPDATE USUARIOS SET ENVIA_EMAIL = ? WHERE EMAIL = ?',['S','mail-changed@example.test']);
$db->run('UPDATE EMAIL_ENVIO SET MENSAGEM = ? WHERE TIPO_EMAIL = ?',['<[CONTEUDO_CODE]>','PAGE_ALT']);
$svg='data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20"/></svg>');
$pages->save('imagem@Mail_test',['title'=>'Imagem de teste','source'=>$svg,'base_version'=>'']);
$imageLog=$db->one('SELECT * FROM EMAIL_LOGS WHERE TIPO_EMAIL = ? ORDER BY ID DESC',['PAGE_ALT']);
checkMail($imageLog['ENVIADO']==='S' && str_contains($imageLog['MENSAGEM'],'<img src="data:image/svg+xml;base64,'),'Image edits preserve the legacy embedded image variable');
$count=(int)$db->one('SELECT COUNT(*) AS n FROM EMAIL_LOGS WHERE TIPO_EMAIL = ?',['PAGE_ALT'])['n'];
$pages->save('Mail_article',['title'=>'Restaurado','source'=>'Versão original','base_version'=>Pages::version($pages->source('Mail_article'))],true);
checkMail((int)$db->one('SELECT COUNT(*) AS n FROM EMAIL_LOGS WHERE TIPO_EMAIL = ?',['PAGE_ALT'])['n']===$count+1,'Restoring an article sends one change notification');
$accounts->requestCode(['email'=>'mail-changed@example.test'],true);
preg_match('/token=([a-f0-9]{64})/',end($sent)['body'],$token);
$accounts->reset(['email'=>'mail-changed@example.test','token'=>$token[1],'password'=>'reset-new-password','password_confirmation'=>'reset-new-password']);
checkMail($db->one('SELECT TIPO_EMAIL FROM EMAIL_LOGS ORDER BY ID DESC')['TIPO_EMAIL']==='USU_ALTERACAO_SENHA','Completed password reset sends the change event');
$db->run("INSERT INTO EMAIL_LOGS (UID,TIPO_EMAIL,IDENTIF,ENVIADO,ASSUNTO) VALUES ('historical-legacy','PAGE_ALT','mail-test','N','Do not replay')");
$notifications->dispatch();
checkMail($db->one("SELECT ENVIADO FROM EMAIL_LOGS WHERE UID = 'historical-legacy'")['ENVIADO']==='N','Retry worker does not replay historical legacy messages');
echo "Legacy email events, templates, preferences, outbox and retry checks passed.\n";
