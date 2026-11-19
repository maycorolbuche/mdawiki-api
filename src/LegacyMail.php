<?php
declare(strict_types=1);
namespace MDAWiki;

use Closure;
use Throwable;

/** Legacy EMAIL_MSG/EMAIL_ENVIO templates and EMAIL_LOGS outbox. */
final class LegacyMail
{
    public function __construct(private Database $db, private array $config, private Closure $send) {}

    public function notify(string $type, string $email, string $page = '', array $extra = []): void
    {
        // A notification failure must never turn a committed edit into a failed PUT.
        try { $uid = $this->enqueue($type, $email, $page, $extra); if ($uid) $this->dispatch($uid); }
        catch (Throwable $error) { error_log('MDAWiki mail event '.$type.': '.$error->getMessage()); }
    }

    /** Required codes retain a plain-text fallback if no personal template exists. */
    public function security(string $type, string $email, string $recipient, string $subject, string $fallback, array $extra = []): void
    {
        $uid = $this->enqueue($type, $email, '', $extra, true);
        $rows = $uid ? $this->db->rows('SELECT * FROM EMAIL_LOGS WHERE UID = ? ORDER BY ID', [$uid]) : [];
        $personal = array_filter($rows, fn($row) => self::contains($row, $recipient));
        if (!$personal) ($this->send)($recipient, $subject, $fallback);
        // Deliver required messages first. Administrative copies cannot roll back an account.
        foreach ($personal as $row) {
            $this->deliver((int)$row['ID']);
            if (($this->db->one('SELECT ENVIADO FROM EMAIL_LOGS WHERE ID = ?', [(int)$row['ID']])['ENVIADO'] ?? '') !== 'S') {
                throw new HttpError(503, 'mail_delivery_failed', 'Não foi possível enviar o código por e-mail. Tente novamente.');
            }
        }
        foreach ($rows as $row) if (!self::contains($row, $recipient)) {
            try { $this->deliver((int)$row['ID']); }
            catch (Throwable $error) { error_log('MDAWiki administrative mail: '.$error->getMessage()); }
        }
    }

    private static function contains(array $row, string $email): bool
    {
        foreach (['MAIL_PARA','MAIL_CC','MAIL_CCO'] as $key) {
            foreach (preg_split('/[;,]/', $row[$key] ?? '') as $address) {
                if (preg_match('/<([^<>]+)>/', $address, $m)) $address = $m[1];
                if (strcasecmp(trim($address), $email) === 0) return true;
            }
        }
        return false;
    }

    public function enqueue(string $type, string $email, string $page = '', array $extra = [], bool $required = false): ?string
    {
        $user = $this->db->one('SELECT * FROM USUARIOS WHERE EMAIL = ?', [$email]) ?? [];
        if (!$required && ($user['ENVIA_EMAIL'] ?? '') === 'N') return null;
        $templates = $this->db->rows('SELECT m.IDENTIF,m.ASSUNTO AS DEFAULT_SUBJECT,m.MENSAGEM AS DEFAULT_BODY,e.EMAIL,e.CC,e.CCO,e.ASSUNTO,e.MENSAGEM FROM EMAIL_MSG m LEFT JOIN EMAIL_ENVIO e ON e.TIPO_EMAIL=m.TIPO_EMAIL WHERE m.TIPO_EMAIL = ? ORDER BY e.EMAIL', [$type]);
        if (!$templates) return null;
        $article = $page !== '' ? $this->db->one('SELECT IDENT,NOME,NOME_URL,CONTEUDO_CODE,CONTEUDO FROM PAGINAS WHERE IDENT = ?', [$page]) : null;
        $allowed = ['NOME','EMAIL','EMAIL_NOVO','APELIDO','FOTO_64','CIDADE','ESTADO','CLUBE','ASSOCIACAO','TIPO_CLUBE','DATA_NASC','BIOGRAFIA'];
        $values = array_intersect_key($user, array_flip($allowed));
        $values += ['CODIGO'=>$user['CODIGO_CONTA'] ?? '', 'PAGINA'=>$article['NOME'] ?? '', 'PAGINA_URL'=>$article['NOME_URL'] ?? '', 'CONTEUDO_CODE'=>$article['CONTEUDO_CODE'] ?? '', 'CONTEUDO'=>$article['CONTEUDO'] ?? ''];
        if ($article && str_starts_with(mb_strtolower($article['NOME_URL']), 'imagem@')) $values['CONTEUDO_CODE'] = '<img src="'.htmlspecialchars((string)($article['CONTEUDO_CODE'] ?? ''),ENT_QUOTES,'UTF-8').'">';
        $base = rtrim($this->config['frontend_url'], '/').'/';
        $values += ['IP'=>$_SERVER['REMOTE_ADDR'] ?? '', 'NAVEGADOR'=>$_SERVER['HTTP_USER_AGENT'] ?? '', 'DATAHORA'=>date('d/m/Y H:i:s'), 'LINK'=>$base.($article ? rawurlencode($article['NOME_URL']).'/' : ''), 'URL_SITE'=>$base];
        $values = array_replace($values, $extra);
        $replace = static fn($text) => preg_replace_callback('/<\[([^\[\]]+)\]>/', static fn($m) => (string)($values[$m[1]] ?? ''), (string)$text);
        $uid = 'api-'.bin2hex(random_bytes(16));
        $count = 0;
        foreach ($templates as $template) {
            $to=$replace($template['EMAIL']); $cc=$replace($template['CC']); $bcc=$replace($template['CCO']);
            if (trim($to.$cc.$bcc)==='') continue;
            $subject=$replace(trim($template['ASSUNTO'] ?? '')!=='' ? $template['ASSUNTO'] : $template['DEFAULT_SUBJECT']);
            $body=$replace(trim($template['MENSAGEM'] ?? '')!=='' ? $template['MENSAGEM'] : $template['DEFAULT_BODY']);
            $this->db->run("INSERT INTO EMAIL_LOGS (UID,TIPO_EMAIL,IDENTIF,DATAHORA,ASSUNTO,MENSAGEM,MAIL_PARA,MAIL_CC,MAIL_CCO,ENVIADO,TENTATIVAS_ENVIO) VALUES (?,?,?,?,?,?,?,?,?,'N',0)", [$uid,$type,$template['IDENTIF'] ?? '0',date('Y-m-d H:i:s'),$subject,$body,$to,$cc,$bcc]);
            $count++;
        }
        return $count ? $uid : null;
    }

    private function deliver(int $id, bool $retry = false): bool
    {
        $directory=$this->config['storage_dir'].'/mail-locks';
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create mail lock directory');
        $lock=fopen($directory.'/'.$id.'.lock','c');
        if (!$lock) throw new \RuntimeException('Cannot lock mail delivery');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return false; }
        try {
            $row=$this->db->one('SELECT * FROM EMAIL_LOGS WHERE ID = ?',[$id]);
            if (!$row || $row['ENVIADO']==='S') return false;
            if ($retry && ((int)($row['TENTATIVAS_ENVIO'] ?? 0)>=10 || (!empty($row['DATAHORA_ENVIO']) && strtotime($row['DATAHORA_ENVIO'])>time()-300))) return false;
            $success=true; $reason='';
            try { ($this->send)($row['MAIL_PARA'] ?? '',$row['ASSUNTO'] ?? '',$row['MENSAGEM'] ?? '',['html'=>true,'cc'=>$row['MAIL_CC'] ?? '', 'bcc'=>$row['MAIL_CCO'] ?? '', 'ident'=>$row['IDENTIF']]); }
            catch (Throwable $error) { $success=false; $reason=mb_substr($error->getMessage(),0,4000); }
            $this->db->run('UPDATE EMAIL_LOGS SET ENVIADO = ?, DATAHORA_ENVIO = ?, RETORNO = ?, TENTATIVAS_ENVIO = COALESCE(TENTATIVAS_ENVIO,0)+1 WHERE ID = ?',[$success?'S':'E',date('Y-m-d H:i:s'),$reason,$id]);
            return $success;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public function dispatch(string $uid = '', int $limit = 50): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT ID FROM EMAIL_LOGS WHERE ENVIADO IN ('N','E')"; $params=[];
        if ($uid!=='') { $sql.=' AND UID = ?'; $params[]=$uid; }
        else {
            // Never accidentally replay the historical site's entire outbox.
            $sql.=" AND UID LIKE 'api-%' AND COALESCE(TENTATIVAS_ENVIO,0)<10 AND (DATAHORA_ENVIO IS NULL OR DATAHORA_ENVIO <= ?)";
            $params[]=date('Y-m-d H:i:s',time()-300);
        }
        $params[]=$limit;
        $rows=$this->db->rows($sql.' ORDER BY ID LIMIT ?', $params); $sent=0;
        foreach ($rows as $row) if ($this->deliver((int)$row['ID'],$uid==='')) $sent++;
        return ['checked'=>count($rows),'sent'=>$sent];
    }
}
