<?php
declare(strict_types=1);
$pdo=new PDO(getenv('TEST_MYSQL_DSN'),getenv('TEST_MYSQL_USER') ?: 'root',getenv('TEST_MYSQL_PASSWORD') ?: 'test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function checkCleanup(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo->exec('DROP TABLE IF EXISTS PAGINAS_LOGS');
$pdo->exec('CREATE TABLE PAGINAS_LOGS (NOME_URL_ID INT NOT NULL, IDENT VARCHAR(250) NOT NULL,NOME VARCHAR(250),CONTEUDO_CODE LONGTEXT,EMAIL VARCHAR(200),DATAHORA DATETIME,PRIMARY KEY(NOME_URL_ID,IDENT)) ENGINE=InnoDB DEFAULT CHARSET=latin1');
$insert=$pdo->prepare('INSERT INTO PAGINAS_LOGS VALUES (?,?,?,?,?,?)');
$fixtures=[
 ['a',1,'Title','A'],['a',2,'Title','A'],['a',4,'Title','A'],['a',7,'Title','B'],['a',9,'Title','B'],['a',10,'Title','A'],
 ['b',1,'Title','A'],['b',2,'Title','a'],['b',3,'Title','a '],['b',4,'Title',"a \n"],['b',5,'Other',"a \n"],
 ['c',1,null,null],['c',2,null,null],['c',3,'',null],['c',4,'',''],['d',5,'Title','One'],
];
foreach ($fixtures as [$id,$rev,$title,$source]) $insert->execute([$rev,$id,$title,$source,'author'.$rev,'2026-11-19 12:00:00']);
$sql=file_get_contents(dirname(__DIR__).'/maintenance/cleanup-duplicate-history.sql');
function runCleanup(PDO $pdo,string $sql): void {
    // Strip line comments, then run each statement in the same connection.
    $sql=preg_replace('/^\s*--.*$/m','',$sql);
    foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') { if (preg_match('/^\s*SELECT\b/i',$statement)) { $query=$pdo->query($statement);$query->fetchAll();$query->closeCursor(); } else $pdo->exec($statement); }
}
runCleanup($pdo,$sql);
checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM PAGINAS_LOGS')->fetchColumn()===16,'Simulation must preserve every revision');
checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM MDAWIKI_HISTORY_DUPLICATES_01')->fetchColumn()===4,'Detect consecutive duplicates only');
runCleanup($pdo,str_replace(['_01','SET @mdawiki_apply_cleanup = 0'],['_02','SET @mdawiki_apply_cleanup = 1'],$sql));
checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM PAGINAS_LOGS')->fetchColumn()===12,'Delete only candidate revisions');
checkCleanup(array_map('intval',$pdo->query("SELECT NOME_URL_ID FROM PAGINAS_LOGS WHERE IDENT='a' ORDER BY NOME_URL_ID")->fetchAll(PDO::FETCH_COLUMN))===[1,7,10],'Preserve first revision, gaps and A-B-A transitions');
checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM MDAWIKI_HISTORY_BACKUP_02')->fetchColumn()===16,'Backup contains all original rows');
checkCleanup((int)$pdo->query("SELECT COUNT(*) FROM PAGINAS_LOGS WHERE IDENT='b'")->fetchColumn()===5,'Case, whitespace and title differences are preserved');
runCleanup($pdo,str_replace(['_01','SET @mdawiki_apply_cleanup = 0'],['_03','SET @mdawiki_apply_cleanup = 1'],$sql));
checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM MDAWIKI_HISTORY_DUPLICATES_03')->fetchColumn()===0,'Second cleanup is idempotent');
$pdo->exec('INSERT INTO PAGINAS_LOGS SELECT b.* FROM MDAWIKI_HISTORY_BACKUP_02 b JOIN MDAWIKI_HISTORY_DUPLICATES_02 d ON d.IDENT=b.IDENT AND d.NOME_URL_ID=b.NOME_URL_ID');
checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM PAGINAS_LOGS')->fetchColumn()===16,'Backup restores all deleted rows');
foreach (['_01','_02','_03'] as $suffix) foreach (['MDAWIKI_HISTORY_BACKUP','MDAWIKI_HISTORY_DUPLICATES'] as $table) $pdo->exec('DROP TABLE '.$table.$suffix);
echo "History cleanup simulation, binary comparison, deletion, idempotence and restoration passed.\n";
