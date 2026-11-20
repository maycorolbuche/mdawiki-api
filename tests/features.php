<?php
declare(strict_types=1);
use MDAWiki\{Application,Accounts,Database,Features,LegacyPageRepository,Media,Session,WikiRenderer};
require dirname(__DIR__).'/config/bootstrap.php';
function verify(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$mysql=getenv('TEST_MYSQL_DSN');
$pdo=$mysql ? new PDO($mysql,getenv('TEST_MYSQL_USER') ?: 'root',getenv('TEST_MYSQL_PASSWORD') ?: 'test') : new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if ($mysql) { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);foreach(explode(';',file_get_contents(__DIR__.'/schema.mysql.sql')) as $sql) if(trim($sql)!=='') $pdo->exec($sql); }
else $pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db=new Database($pdo);
$storage=sys_get_temp_dir().'/mdawiki-tests-'.bin2hex(random_bytes(5));
$config=['origins'=>['https://site.mda.wiki.br'],'frontend_url'=>'https://site.mda.wiki.br','media_public_url'=>'https://api.mda.wiki.br/media','media_url'=>'https://api.mda.wiki.br/media','public_max_level'=>1,'storage_dir'=>$storage,'media_root'=>$storage.'/media','app_key'=>str_repeat('test-key-',8)];
$session=new Session();$mail=[];$send=function($to,$subject,$text) use (&$mail) {$mail[]=['to'=>$to,'subject'=>$subject,'text'=>$text];};
$feature=new Features($db,$session,$config,$send);$app=new Application(fn()=>new LegacyPageRepository($pdo),$config,fn()=>$feature);
function request(string $path,string $method='GET',array $body=[],?string $csrf=null,array $query=[]): MDAWiki\Response { global $app,$session;return $app->handle($method,$path,$query,'https://site.mda.wiki.br',$body,$csrf ?? $session->data['csrf'],'127.0.0.1'); }
$db->run("INSERT INTO USUARIOS (ID,EMAIL,NOME,SENHA,APELIDO,ATIVO,NIVEL) VALUES (1,?,?,?,?,'S',5)",['admin@example.test','Administrador',md5('old-password'),'Admin']);
verify(request('/v1/auth/session')->body['data']['user']===null,'Anonymous session exposes no user');
verify(request('/v1/auth/login','POST',['email'=>'admin@example.test','password'=>'old-password'],'bad')->status===403,'CSRF required on login');
verify($app->handle('POST','/v1/auth/login',[],'',[])->status===403,'Writes without Origin rejected');
verify(request('/v1/auth/login','POST',['email'=>'admin@example.test','password'=>'wrong'])->status===401,'Invalid credentials rejected');
$oldCsrf=$session->data['csrf'];$login=request('/v1/auth/login','POST',['email'=>'admin@example.test','password'=>'old-password']);
verify($login->status===200 && (int)$login->body['data']['user']['NIVEL']===5,'Existing MD5 account can sign in');
verify($oldCsrf!==$session->data['csrf'],'Rotate CSRF after login');
verify(request('/v1/profile','PUT',['NOME'=>'Forbidden'],$oldCsrf)->status===403,'Old CSRF cannot be reused after login');
$saved=$db->one('SELECT SENHA FROM USUARIOS WHERE ID=1')['SENHA'];verify(strlen($saved)===32 && str_starts_with($saved,'p2$'),'Upgrade MD5 within unchanged column');
verify(!str_contains(json_encode($login->body),'SENHA') && !str_contains(json_encode($login->body),$saved),'No credential hashes in API');
verify(request('/v1/profile','PUT',['NOME'=>'Nome atualizado','NIVEL'=>1,'ATIVO'=>'N'])->status===200,'Profile fields accepted');
verify(request('/v1/profile','PUT',['DATA_NASC'=>null,'CIDADE'=>null])->status===200,'Nullable legacy profile fields can be saved');
verify((int)$db->one('SELECT NIVEL FROM USUARIOS WHERE ID=1')['NIVEL']===5,'Mass assignment cannot change privileges');
$db->run("INSERT INTO PAGINAS (IDENT,NOME_URL,NOME,CONTEUDO_CODE,CONTEUDO,CONTEUDO_TXT,NIVEL) VALUES ('incluido','Incluído','Incluído','Texto incluído.','','',1)");
$code="{{Especialidade:clube=DBV|area=HM|cod=024|nivel=1|ano=1964|origem=Associação Geral|icone=HM024}}\n== Requisitos ==\n# Primeiro requisito\n# [[Outra página|Outro artigo]]\n{{Categoria:Artes e Habilidades Manuais}}\n{{Incluir:Incluído}}";
verify(request('/v1/pages/Sab%C3%A3o/editor')->body['data']['exists']===false,'New article source can be created by authenticated user');
$preview=request('/v1/pages/Sab%C3%A3o/preview','POST',['source'=>$code]);
verify($preview->status===200 && str_contains($preview->body['data']['html'],'#1E90FF') && str_contains($preview->body['data']['html'],'Primeiro requisito'),'Preserve specialty templates in preview');
verify((int)$db->one('SELECT COUNT(*) AS n FROM PAGINAS_LOGS')['n']===0,'Preview does not write revisions');
$save=request('/v1/pages/Sab%C3%A3o','PUT',['title'=>'Especialidade de Sabão','source'=>$code,'base_version'=>'']);
verify($save->status===200,'Save wiki article: '.json_encode($save->body));
$article=request('/v1/pages/Sab%C3%A3o')->body['data'];verify(str_contains($article['html'],'Texto incluído.') && !str_contains($article['html'],'mda.wiki.br/site/'),'Renderer is independent of old URLs');
verify(count($article['categories'])===1 && count($article['missing_links'])===1,'Saved relations immediately available');
verify((int)$db->one('SELECT COUNT(*) AS n FROM PAGINAS_INCLUDES')['n']===1,'Include index persisted');
verify($db->one("SELECT IMAGEM_IDENT,IMAGEM_FILE_URL FROM PAGINAS_IMAGENS WHERE IMAGEM_IDENT = 'hm024'")['IMAGEM_FILE_URL']==='','Missing template image retains its actual identity');
verify(request('/v1/pages/Sab%C3%A3o','PUT',['title'=>'Changed','source'=>'Overwrite','base_version'=>''])->status===409,'Stale editor cannot overwrite newer revision');
$editor=request('/v1/pages/Sab%C3%A3o/editor')->body['data'];
$snapshotBefore=$db->one("SELECT * FROM PAGINAS WHERE NOME_URL = ?",['Sabão']);
$logsBefore=(int)$db->one('SELECT COUNT(*) AS n FROM PAGINAS_LOGS')['n'];
$noop=request('/v1/pages/Sab%C3%A3o','PUT',['title'=>$editor['title'],'source'=>$editor['source'],'base_version'=>$editor['base_version']]);
verify($noop->status===200 && $noop->body['data']['unchanged']===true && $noop->body['data']['revision']===1,'Saving identical source succeeds without a new revision');
verify($noop->body['data']['base_version']===$editor['base_version'],'Unchanged save preserves the concurrency token');
verify((int)$db->one('SELECT COUNT(*) AS n FROM PAGINAS_LOGS')['n']===$logsBefore,'Identical save does not append history');
verify($db->one('SELECT * FROM PAGINAS WHERE NOME_URL = ?',['Sabão'])===$snapshotBefore,'Identical save preserves content, dates, author and cache flags');
$changed=request('/v1/pages/Sab%C3%A3o','PUT',['title'=>$editor['title'],'source'=>"Novo texto\n{{Categoria:Arte' OR 1=1 --}}",'base_version'=>$editor['base_version']]);verify($changed->status===200,'Parameterized category with apostrophe');
$history=request('/v1/pages/Sab%C3%A3o/history')->body['data'];verify(count($history['revisions'])===2 && $history['revisions'][0]['source']!==$history['revisions'][1]['source'],'History contains actual revisions');
verify($history['revisions'][0]['author_photo']===null,'History supports an author without a photo');
$db->run('UPDATE USUARIOS SET FOTO = ?, FOTO_32X32 = ? WHERE ID=1',['original.png','mini avatar.png']);
$avatarHistory=request('/v1/pages/Sab%C3%A3o/history')->body['data']['revisions'];
verify($avatarHistory[0]['author_photo']==='https://api.mda.wiki.br/media/users/mini%20avatar.png','History prefers the same thumbnail as the legacy');
verify(!isset($avatarHistory[0]['author_photo_original']) && !isset($avatarHistory[0]['author_photo_thumbnail']) && !isset($avatarHistory[0]['EMAIL']),'History exposes only the public photo URL');
$db->run('UPDATE USUARIOS SET FOTO_32X32 = NULL WHERE ID=1');
verify(request('/v1/pages/Sab%C3%A3o/history')->body['data']['revisions'][0]['author_photo']==='https://api.mda.wiki.br/media/users/original.png','History falls back to the original photo');
$db->run('UPDATE USUARIOS SET FOTO = NULL WHERE ID=1');
verify(request('/v1/pages/Sab%C3%A3o/restore','POST',['revision'=>1,'base_version'=>$history['base_version']])->status===200,'Restore creates new revision');
verify(count(request('/v1/pages/Sab%C3%A3o/history')->body['data']['revisions'])===3,'Restore preserves original history');
verify(count(request('/v1/search','GET',[],null,['q'=>'Sabão'])->body['data']['items'])===1,'Search returns public matching article');
verify(count(request('/v1/search','GET',[],null,['q'=>"' OR 1=1 --"])->body['data']['items'])===0,'Search input never interpolated as SQL');
verify(request('/v1/catalog','GET',[],null,['type'=>'categorias'])->status===200,'Category catalog');verify(request('/v1/statistics')->body['data']['pages']===2,'Statistics count visible pages');
foreach(['paginas','codigos','documentacao','listas','imagens','videos','links_inexistentes','paginas_sem_categoria','redirecionamentos','imagens_sem_pagina','imagens_inexistentes','atualizar'] as $type) verify(request('/v1/catalog','GET',[],null,['type'=>$type])->status===200,'Catalog query: '.$type);
verify(request('/v1/home')->status===200,'Home rankings and counters');
verify(request('/v1/pages/Sab%C3%A3o/rebuild','POST')->status===200,'Administrator can rebuild independently of legacy cron');
verify(request('/v1/visits','POST',['slug'=>'Sabão'])->body['data']['counted']===true,'Browser views update the independent daily counter');
verify(request('/v1/visits','POST',['slug'=>'Sabão'])->body['data']['counted']===false,'Debounce duplicate view reports');
verify((int)$db->one('SELECT QTD_ACESSOS FROM PAGINAS_ACESSOS_LOGS_QTD_DIARIO')['QTD_ACESSOS']===1,'Build and duplicate reports do not inflate views');
$redirect=request('/v1/pages/Atalho','PUT',['title'=>'Atalho','source'=>'{{{Redirecionar:Sabão}}}','base_version'=>'']);verify($redirect->status===200 && request('/v1/pages/Atalho')->body['data']['redirect_slug']==='Sabão','Redirect metadata is maintained');
verify(request('/v1/users','GET',[],null,['NOME'=>'Nome atualizado'])->body['data']['items'][0]['EMAIL']==='admin@example.test','Parameterized administrator filters');
$db->run("INSERT INTO PAGINAS (IDENT,NOME_URL,NOME,CONTEUDO_CODE,CONTEUDO,NIVEL) VALUES ('private','Privada','Segredo','Privado','<p>Segredo</p>',5)");
verify(request('/v1/view/Privada')->body['data']['is_public']===false,'Authorized private view is explicitly non-public');
verify(request('/v1/pages/Privada')->status===404,'Private content never leaks into public API, even with an admin session');
$db->run("INSERT INTO USUARIOS (EMAIL,NOME,SENHA,ATIVO,NIVEL) VALUES (?,?,?,'S',1)",['editor@example.test','Editor',md5('editor-password')]);
request('/v1/auth/logout','POST');verify(request('/v1/pages/Sab%C3%A3o/editor')->status===401,'Source requires login');
request('/v1/auth/login','POST',['email'=>'editor@example.test','password'=>'editor-password']);
verify(request('/v1/users')->status===403,'User directory restricted to administrators');verify(request('/v1/pages/Privada/editor')->status===403,'Page permission enforced on server');
verify(request('/v1/pages/Sab%C3%A3o/rebuild','POST')->status===403,'Rebuild restricted to administrators');
    $db->run('UPDATE USUARIOS SET NIVEL = 0 WHERE EMAIL = ?',['editor@example.test']);
    verify(request('/v1/pages/Nova_restrita/editor')->status===403,'Read-only accounts cannot create pages above their level');
    verify(request('/v1/view/Sab%C3%A3o')->status===200,'Read-only accounts can still view public articles');
    verify(request('/v1/media','POST',['data'=>'invalid'])->status===403,'Read-only accounts cannot upload wiki images');
    $db->run('UPDATE USUARIOS SET NIVEL = 1 WHERE EMAIL = ?',['editor@example.test']);
verify(request('/v1/view/Privada')->status===403,'Private article view enforces permissions');
verify(request('/v1/pages/page%40Login','PUT',['title'=>'Unsafe','source'=>'<?php echo 1;?>','base_version'=>''])->status===403,'Never write executable system pages');
request('/v1/auth/logout','POST');
$register=request('/v1/auth/register','POST',['name'=>'Conta nova','email'=>'new@example.test','password'=>'new-password-123','password_confirmation'=>'new-password-123']);verify($register->status===200,'Registration: '.json_encode($register->body));
verify(request('/v1/auth/login','POST',['email'=>'new@example.test','password'=>'new-password-123'])->status===403,'Activation required');
$activation=$db->one('SELECT CODIGO_CONTA FROM USUARIOS WHERE EMAIL = ?',['new@example.test'])['CODIGO_CONTA'];verify(count($mail)===1 && str_contains($mail[0]['text'],$activation),'Activation sent through injected mail transport');
verify(request('/v1/auth/activate','POST',['code'=>$activation])->status===200,'Activate new account');
verify(request('/v1/auth/activate','POST',['code'=>$activation])->status===422,'Activation code cannot be replayed');
$db->run("UPDATE USUARIOS SET SENHA = '' WHERE EMAIL = ?", ['new@example.test']);
verify(request('/v1/auth/login','POST',['email'=>'new@example.test','password'=>'new-password-123'])->status===401,'Blank password requires reset');
request('/v1/auth/forgot','POST',['email'=>'new@example.test']);$text=end($mail)['text'];preg_match('/token=([a-f0-9]{64})/',$text,$token);
verify(isset($token[1]),'Reset URL points at new frontend');$reset=['email'=>'new@example.test','token'=>$token[1],'password'=>'reset-password-123','password_confirmation'=>'reset-password-123'];
$db->run('UPDATE USUARIOS SET DATA_CODIGO_CONTA = ? WHERE EMAIL = ?',[date('Y-m-d H:i:s',time()-3601),'new@example.test']);verify(request('/v1/auth/reset','POST',$reset)->status===422,'Expired reset token rejected');$db->run('UPDATE USUARIOS SET DATA_CODIGO_CONTA = ? WHERE EMAIL = ?',[date('Y-m-d H:i:s'),'new@example.test']);
verify(request('/v1/auth/reset','POST',$reset)->status===200,'Reset password');verify(request('/v1/auth/reset','POST',$reset)->status===422,'Reset token is single-use');
verify(request('/v1/auth/login','POST',['email'=>'new@example.test','password'=>'reset-password-123'])->status===200,'Login using new slow hash');
verify(request('/v1/profile/password','POST',['current_password'=>'wrong','password'=>'changed-password','password_confirmation'=>'changed-password'])->status===422,'Password changes require current password');
verify(request('/v1/profile/email','POST',['email'=>'changed@example.test'])->status===200,'Email verification sent');$code=$db->one('SELECT CODIGO_CONTA FROM USUARIOS WHERE EMAIL = ?',['new@example.test'])['CODIGO_CONTA'];
verify(request('/v1/profile/email','POST',['email'=>'changed@example.test','code'=>$code])->status===200,'Verified email can replace old email');
verify(request('/v1/profile')->body['data']['EMAIL']==='changed@example.test','Session uses current verified email');
verify(request('/v1/media','POST',['data'=>'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>')])->status===422,'Reject executable SVG uploads');
$fixture=imagecreatetruecolor(1,1);ob_start();imagepng($fixture);$png='data:image/png;base64,'.base64_encode(ob_get_clean());imagedestroy($fixture);
$rectangle=imagecreatetruecolor(2,1);ob_start();imagepng($rectangle);$rectangularPng='data:image/png;base64,'.base64_encode(ob_get_clean());imagedestroy($rectangle);
$photoBefore=$db->one('SELECT FOTO FROM USUARIOS WHERE EMAIL = ?',['changed@example.test'])['FOTO'];
$filesBefore=glob($config['media_root'].'/users/*') ?: [];
$invalidPhoto=request('/v1/profile/photo','POST',['data'=>$rectangularPng]);
verify($invalidPhoto->status===422 && $invalidPhoto->body['error']['code']==='invalid_profile_photo','Profile photo must be square');
verify($db->one('SELECT FOTO FROM USUARIOS WHERE EMAIL = ?',['changed@example.test'])['FOTO']===$photoBefore && (glob($config['media_root'].'/users/*') ?: [])===$filesBefore,'Rejected photo does not write a file or replace the avatar');
verify(request('/v1/profile/photo','POST',['data'=>'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10"/>')])->status===422,'Uncropped profile SVG must pass through the raster cropper');
$squarePhoto=request('/v1/profile/photo','POST',['data'=>$png]);
verify($squarePhoto->status===200 && $squarePhoto->body['data']['width']===$squarePhoto->body['data']['height'],'Square profile photo is accepted');
verify(request('/v1/media','POST',['data'=>$rectangularPng])->status===200,'Wiki images may remain rectangular');
$upload=request('/v1/media','POST',['data'=>$png]);verify($upload->status===200,'Raster image upload: '.json_encode($upload->body));
$image=request('/v1/pages/imagem%40Teste','PUT',['title'=>'Imagem teste','source'=>$upload->body['data']['url'],'base_version'=>'']);verify($image->status===200 && str_contains(request('/v1/pages/imagem%40Teste')->body['data']['html'],'<img'),'Image page uses the API storage');
require __DIR__.'/images.inc.php';
if (class_exists(DOMDocument::class)) { $svg=(new MDAWiki\Media($config))->restore('data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><path d="M0 0"/></svg>'));$bytes=file_get_contents($config['media_root'].'/wiki/'.$svg['filename']);verify(!str_contains($bytes,'script') && !str_contains($bytes,'onload'),'SVG history restoration strips active content'); }
$renderer=new WikiRenderer($db,$config);$db->run("INSERT INTO PAGINAS (IDENT,NOME_URL,NOME,CONTEUDO_CODE) VALUES ('cycle','Cycle','Cycle','{{Incluir:Cycle}}')");
try {$renderer->render('{{Incluir:Cycle}}','Other');throw new RuntimeException('Cycle accepted');}catch(MDAWiki\HttpError $e){verify($e->errorCode==='include_cycle','Detect recursive includes');}
$badMail=new Accounts($db,new Session(),$config,static function(){throw new RuntimeException('SMTP unavailable');});
try {$badMail->register(['name'=>'Failed','email'=>'failed@example.test','password'=>'valid-password','password_confirmation'=>'valid-password']);throw new RuntimeException('Mail should fail');}catch(RuntimeException $e){verify($db->one('SELECT ID FROM USUARIOS WHERE EMAIL = ?',['failed@example.test'])===null,'Mail failure rolls back registration');}
// Real legacy TAGS rows use SQL NULL to mean "remove the matched text".
$rules=[['null-pre','/APAGAR_PRE/',null,'PRE',1],['null-pos','/APAGAR_POS/',null,'POS',1],['backref-pos','/(Mantido)/u','<strong>$1</strong>','POS',2],['zero-pos','/NUMERO/','0','POS',3]];
foreach($rules as [$id,$pattern,$replacement,$position,$order]) $db->run('INSERT INTO TAGS (ID,PARAM_CODE,PARAM_HTML,FUNCAO,POSICAO,ORDEM) VALUES (?,?,?,?,?,?)',[$id,$pattern,$replacement,'preg_replace',$position,$order]);
$ruleSource='APAGAR_PRE Mantido APAGAR_POS NUMERO';
$ruleSave=request('/v1/pages/Teste_regra_nula','PUT',['title'=>'Teste de regra nula','source'=>$ruleSource,'base_version'=>'']);
verify($ruleSave->status===200,'Legacy null replacement must save successfully: '.json_encode($ruleSave->body));
$rulePage=$db->one('SELECT CONTEUDO,CONTEUDO_CODE FROM PAGINAS WHERE NOME_URL = ?',['Teste_regra_nula']);
verify(!str_contains($rulePage['CONTEUDO'],'APAGAR_') && str_contains($rulePage['CONTEUDO'],'<strong>Mantido</strong>') && str_contains($rulePage['CONTEUDO'],'0'),'PRE/POS null deletion, backreferences and literal zero remain compatible');
verify($rulePage['CONTEUDO_CODE']===$ruleSource,'Rule replacement never changes the stored wiki source');
$rulePreview=request('/v1/pages/Teste_regra_nula/preview','POST',['source'=>$ruleSource]);verify($rulePreview->status===200 && !str_contains($rulePreview->body['data']['html'],'APAGAR_'),'Preview supports legacy null replacements');
foreach([null,'','/[invalid/'] as $index=>$pattern) {
    $db->run('INSERT INTO TAGS (ID,PARAM_CODE,PARAM_HTML,FUNCAO,POSICAO,ORDEM) VALUES (?,?,?,?,?,?)',['invalid-rule',$pattern,null,'preg_replace','PRE',9]);
    $invalid=request('/v1/pages/Regra_invalida_'.$index,'PUT',['title'=>'Inválida','source'=>'Texto','base_version'=>'']);
    verify($invalid->status===422 && $invalid->body['error']['code']==='invalid_wiki_rule','Invalid patterns must return validation errors, not 503');
    verify(str_contains($invalid->body['error']['message'],'invalid-rule') && str_contains($invalid->body['error']['message'],'PRE'),'Identify the invalid TAGS row and processing phase');
    verify($db->one('SELECT IDENT FROM PAGINAS WHERE NOME_URL = ?',['Regra_invalida_'.$index])===null,'Invalid rule cannot partially create an article');
    $db->run('DELETE FROM TAGS WHERE ID = ?',['invalid-rule']);
}
foreach($rules as $rule) $db->run('DELETE FROM TAGS WHERE ID = ?',[$rule[0]]);
// Home blocks use the original indexes without requiring legacy cron jobs or views.
$homeDates=['today'=>date('Y-m-d'),'week'=>date('Y-m-d',strtotime('-6 days')),'outside_week'=>date('Y-m-d',strtotime('-7 days')),'month'=>date('Y-m-d',strtotime('-29 days')),'outside_month'=>date('Y-m-d',strtotime('-30 days')),'future'=>date('Y-m-d',strtotime('+1 day'))];
foreach($homeDates as $name=>$date) {
    $id='home_'.$name;
    $db->run("INSERT INTO PAGINAS (IDENT,NOME_URL,NOME,CONTEUDO_TXT,DT_CADASTRO) VALUES (?,?,?,?,?)",[$id,$id,'Home '.$name,str_repeat('Resumo ',60),$date.' 12:00:00']);
    $db->run('INSERT INTO PAGINAS_ACESSOS_LOGS_QTD_DIARIO (DATA,IDENT,NOME_URL,QTD_ACESSOS) VALUES (?,?,?,?)',[$date,$id,$id,1000]);
}
foreach(['private'=>5,'hidden'=>1,'redirect'=>1] as $name=>$level) {
    $id='home_'.$name;
    $db->run("INSERT INTO PAGINAS (IDENT,NOME_URL,NOME,NIVEL,BUSCA) VALUES (?,?,?,?,?)",[$id,$id,'Hidden '.$name,$level,$name==='hidden'?'N':'S']);
    if($name!=='redirect') $db->run('INSERT INTO PAGINAS_ACESSOS_LOGS_QTD_DIARIO (DATA,IDENT,NOME_URL,QTD_ACESSOS) VALUES (?,?,?,99999)',[date('Y-m-d'),$id,$id]);
}
$db->run("INSERT INTO PAGINAS_REDIRECIONAMENTOS (IDENT,NOME_URL,ID,PAGINA_URL) VALUES ('home_redirect','home_redirect',1,'home_today')");
$db->run("INSERT INTO PAGINAS_IMAGENS (IDENT,NOME_URL,ID,IMAGEM_URL,IMAGEM_FILE_URL,IMAGEM_FILE_MINI_URL,IMAGEM_FILE_CP_URL,TEMPLATE) VALUES ('home_today','home_today',1,'photo','https://mda.wiki.br/site/@imgs_wiki/photo.png','https://mda.wiki.br/site/@imgs_wiki_mini/photo.png','','N')");
foreach([['home_today',1,'home_missing','Falta este conteúdo'],['home_week',1,'home_missing','Falta este conteúdo'],['home_private',1,'home_secret_missing','Link privado']] as [$source,$id,$target,$title]) $db->run('INSERT INTO PAGINAS_LINKS (IDENT,ID,LINK,LINK_IDENT,LINK_URL) VALUES (?,?,?,?,?)',[$source,$id,$title,$target,$target]);
$homeResponse=request('/v1/home');verify($homeResponse->status===200,'Home request succeeds');$home=$homeResponse->body['data'];
foreach(['random','recent','popular','top_today','top_week','top_month','missing'] as $key) {
    verify(is_array($home[$key]) && count($home[$key])<=($key==='random'?3:10),'Home block limits: '.$key);
    verify(!array_intersect(['home_private','home_hidden'],array_column($home[$key],'id')),'Private and unlisted pages excluded: '.$key);
}
verify(count($home['random'])===3 && !in_array('home_redirect',array_column($home['random'],'id'),true),'Three random pages exclude redirects');
$todayIds=array_column($home['top_today'],'id');$weekIds=array_column($home['top_week'],'id');$monthIds=array_column($home['top_month'],'id');
verify(in_array('home_today',$todayIds,true) && !in_array('home_week',$todayIds,true),'Today ranking excludes older visits');
verify(in_array('home_week',$weekIds,true) && !in_array('home_outside_week',$weekIds,true),'Seven-day ranking respects its inclusive boundary');
verify(in_array('home_month',$monthIds,true) && !in_array('home_outside_month',$monthIds,true) && !in_array('home_future',$monthIds,true),'Thirty-day ranking excludes older and future visits');
$homeToday=$home['top_today'][array_search('home_today',$todayIds,true)];
verify($homeToday['image']==='https://api.mda.wiki.br/media/wiki/photo.png','Home uses the original image rather than the mini directory');
verify(mb_strlen($homeToday['description'])===303 && $homeToday['views']===1000,'Home summaries are bounded and period counts are numeric');
verify(count(array_filter($home['missing'],fn($item)=>$item['id']==='home_missing'))===1 && !in_array('home_secret_missing',array_column($home['missing'],'id'),true),'Missing links are unique and originate only from public pages');
verify($home['recent'][0]['id']==='home_future','Recent changes fall back to creation date');
$noopCreated=request('/v1/pages/Noop_test','PUT',['title'=>'Noop test','source'=>'Texto','base_version'=>'']);
verify($noopCreated->status===200,'New page still creates its first revision');
$noopEditor=request('/v1/pages/Noop_test/editor')->body['data'];
$titleChanged=request('/v1/pages/Noop_test','PUT',['title'=>'Outro título','source'=>'Texto','base_version'=>$noopEditor['base_version']]);
verify($titleChanged->status===200 && $titleChanged->body['data']['revision']===2,'Changing only the title records a revision');
$spaceChanged=request('/v1/pages/Noop_test','PUT',['title'=>'Outro título','source'=>"Texto ", 'base_version'=>$titleChanged->body['data']['base_version']]);
verify($spaceChanged->status===200 && $spaceChanged->body['data']['revision']===3,'Whitespace differences are real source edits');
verify(request('/v1/pages/Noop_test','PUT',['title'=>'Outro título','source'=>'Texto ','base_version'=>$noopEditor['base_version']])->status===409,'Stale base version is still rejected even with identical content');
verify(request('/v1/pages/Noop_test/restore','POST',['revision'=>3,'base_version'=>$spaceChanged->body['data']['base_version']])->body['data']['unchanged']===true,'Restoring the already current revision does not duplicate history');
$pendingDir=$config['storage_dir'].'/pending';if (!is_dir($pendingDir)) mkdir($pendingDir,0700,true);
$pendingFile=$pendingDir.'/'.hash('sha256',MDAWiki\Database::id('Noop_test')).'.json';
file_put_contents($pendingFile,'{}');
verify(request('/v1/pages/Noop_test','PUT',['title'=>'Outro título','source'=>'Texto ','base_version'=>$spaceChanged->body['data']['base_version']])->status===503,'Identical save cannot bypass pending recovery');
unlink($pendingFile);
echo "Independent account, wiki editing, history, search, catalogs and security checks passed.\n";



