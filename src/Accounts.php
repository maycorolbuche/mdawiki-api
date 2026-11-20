<?php
declare(strict_types=1);
namespace MDAWiki;
use Closure;
final class Accounts
{
    private LegacyMail $notifications;
    public function __construct(private Database $db, private Session $session, private array $config, private Closure $mail, ?LegacyMail $notifications = null) { $this->notifications=$notifications ?? new LegacyMail($db,$config,$mail); }
    private function hash(string $password, int $id): string {
        $key = $this->config['app_key'] ?? '';
        if (strlen($key) < 32) throw new HttpError(503, 'auth_not_configured', 'Configure APP_KEY na API para habilitar as contas.');
        // Fits the existing SENHA varchar(32), with a version marker and unique
        // keyed salt. Existing MD5 credentials are upgraded on successful login.
        return 'p2$' . substr(hash_pbkdf2('sha256', $password, hash_hmac('sha256', (string) $id, $key), 600000, 32), 0, 29);
    }
    private function verify(string $password, array $user): bool {
        $hash = $user['SENHA'] ?? '';
        if ($hash === '') return false;
        return hash_equals($hash, str_starts_with($hash, 'p2$') ? $this->hash($password, (int) $user['ID']) : md5($password));
    }
    private function password(array $body): string {
        $password = $body['password'] ?? '';
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 1024) throw new HttpError(422, 'invalid_password', 'Use uma senha com pelo menos 10 caracteres.');
        if ($password !== ($body['password_confirmation'] ?? null)) throw new HttpError(422, 'password_mismatch', 'A confirmação de senha está diferente.');
        return $password;
    }
    private function email(mixed $email): string {
        if (!is_string($email) || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'invalid_email', 'Informe um e-mail válido.');
        return mb_strtolower(trim($email));
    }
    public function current(bool $required = false): ?array {
        $user = isset($this->session->data['id']) ? $this->db->one('SELECT * FROM USUARIOS WHERE ID = ?', [(int) $this->session->data['id']]) : null;
        if ($user && ($user['ATIVO'] !== 'S' || ($user['SENHA'] ?? '') === '' || !hash_equals($this->session->data['fingerprint'] ?? '', hash('sha256', $user['SENHA'])) || ($this->session->data['expires'] ?? 0) < time())) $user = null;
        if (!$user) { unset($this->session->data['id'], $this->session->data['fingerprint']); if ($required) throw new HttpError(401, 'login_required', 'Entre na sua conta para continuar.'); }
        return $user;
    }
    public function profile(array $user): array {
        $fields = ['ID','NOME','APELIDO','EMAIL','DATA_NASC','CIDADE','ESTADO','CLUBE','ASSOCIACAO','TIPO_CLUBE','BIOGRAFIA','NIVEL','ENVIA_EMAIL','ATIVO','DT_CADASTRO','DT_ALTERACAO'];
        $data = array_intersect_key($user, array_flip($fields));
        $data['photo'] = self::photoUrl($user,$this->config);
        return $data;
    }
    public static function photoUrl(array $user, array $config, bool $thumbnail = false): ?string {
        $file=$thumbnail && !empty($user['FOTO_32X32']) ? $user['FOTO_32X32'] : ($user['FOTO'] ?? '');
        return $file!=='' && $file!==null ? rtrim($config['media_public_url'],'/').'/users/'.rawurlencode(basename($file)) : null;
    }
    public function session(): array { $u = $this->current(); return ['user'=>$u ? $this->profile($u) : null, 'csrf_token'=>$this->session->data['csrf']]; }
    public function login(array $body): array {
        $email = $this->email($body['email'] ?? null);
        $password = $body['password'] ?? '';
        if (!is_string($password) || strlen($password) > 1024) throw new HttpError(422, 'invalid_password', 'Senha inválida.');
        $u = $this->db->one('SELECT * FROM USUARIOS WHERE EMAIL = ?', [$email]);
        if (!$u || !$this->verify($password, $u)) throw new HttpError(401, 'invalid_credentials', 'E-mail ou senha inválidos. Se você ainda precisa cadastrar uma senha, use a opção de redefinição.');
        if ($u['ATIVO'] !== 'S') throw new HttpError(403, 'inactive_account', 'Ative sua conta pelo código enviado por e-mail.');
        if (!str_starts_with($u['SENHA'], 'p2$')) { $u['SENHA'] = $this->hash($password, (int) $u['ID']); $this->db->run('UPDATE USUARIOS SET SENHA = ?, SENHA_PROV = NULL WHERE ID = ?', [$u['SENHA'], (int) $u['ID']]); }
        $lifetime=($body['remember'] ?? false)===true?2592000:43200;
        $this->session->rotate(); $this->session->data['id'] = (int) $u['ID']; $this->session->data['fingerprint'] = hash('sha256', $u['SENHA']); $this->session->data['expires'] = time() + $lifetime;
        if ($lifetime>43200) $this->session->remember($lifetime);
        $this->db->run("UPDATE USUARIOS SET FEZ_LOGIN = 'S', DT_ULT_ACESSO = ? WHERE ID = ?", [date('Y-m-d H:i:s'), (int) $u['ID']]);
        return $this->session();
    }
    public function logout(): array { $this->session->data = []; $this->session->rotate(); return $this->session(); }
    public function register(array $body): array {
        $email = $this->email($body['email'] ?? null); $password = $this->password($body);
        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) throw new HttpError(422, 'invalid_name', 'Informe seu nome.');
        if ($this->db->one('SELECT ID FROM USUARIOS WHERE EMAIL = ?', [$email])) throw new HttpError(409, 'email_exists', 'Este e-mail já possui cadastro.');
        $this->hash($password, 0); // Check configuration before writing anything.
        $code = bin2hex(random_bytes(10)); $now = date('Y-m-d H:i:s');
        $this->db->pdo->beginTransaction();
        try {
            $this->db->run("INSERT INTO USUARIOS (EMAIL,NOME,SENHA,APELIDO,CODIGO_CONTA,DATA_CODIGO_CONTA,DT_CADASTRO,ATIVO,NIVEL) VALUES (?,?,?,?,?,?,?,'N',1)", [$email,$name,bin2hex(random_bytes(16)),mb_substr(strstr($email, '@', true),0,50),$code,$now,$now]);
            $id = (int) $this->db->pdo->lastInsertId(); $this->db->run('UPDATE USUARIOS SET SENHA = ? WHERE ID = ?', [$this->hash($password,$id),$id]);
            $this->notifications->security('USU_CADASTRO_ATIVAR',$email,$email,'Ative sua conta MDAWiki',"Seu código: $code\n" . $this->config['frontend_url'] . '/page@AtivaConta?code=' . $code,['CODIGO'=>$code]);
            $this->db->pdo->commit();
        } catch (\Throwable $e) { if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack(); throw $e; }
        return ['message'=>'Cadastro criado. Confira seu e-mail para ativar a conta.'];
    }
    public function activation(array $body): array {
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $u = $code !== '' ? $this->db->one("SELECT * FROM USUARIOS WHERE CODIGO_CONTA = ? AND ATIVO = 'N'", [$code]) : null;
        if (!$u || strtotime($u['DATA_CODIGO_CONTA'] ?? '') < time() - 86400) throw new HttpError(422, 'invalid_code', 'Código inválido ou expirado. Solicite outro código.');
        $this->db->run("UPDATE USUARIOS SET ATIVO = 'S', CODIGO_CONTA = NULL, DATA_CODIGO_CONTA = NULL WHERE ID = ?", [(int) $u['ID']]);
        $this->notifications->notify('USU_CADASTRO_ATIVADO',$u['EMAIL']);
        return ['message'=>'Conta ativada. Você já pode entrar.'];
    }
    public function requestCode(array $body, bool $reset): array {
        $email = $this->email($body['email'] ?? null); $u = $this->db->one('SELECT * FROM USUARIOS WHERE EMAIL = ?', [$email]);
        if ($u && (($reset && $u['ATIVO'] === 'S') || (!$reset && $u['ATIVO'] !== 'S'))) {
            $token = bin2hex(random_bytes($reset ? 32 : 10)); $now = date('Y-m-d H:i:s');
            $column = $reset ? 'SENHA_PROV' : 'CODIGO_CONTA';
            $stored = $reset ? substr(hash('sha256', $token),0,32) : $token;
            $link = $this->config['frontend_url'] . ($reset ? '/page@RedefinirSenha?email=' . rawurlencode($email) . '&token=' : '/page@AtivaConta?code=') . $token;
            $this->db->pdo->beginTransaction();
            try {
                $this->db->run("UPDATE USUARIOS SET $column = ?, DATA_CODIGO_CONTA = ? WHERE ID = ?", [$stored,$now,(int) $u['ID']]);
                if ($reset) ($this->mail)($email,'Redefinir senha MDAWiki',"Acesse: $link\nO link expira em uma hora.");
                else $this->notifications->security('USU_CADASTRO_ATIVAR_ENVCODIGO',$email,$email,'Código de ativação MDAWiki',"Acesse: $link\nSeu código: $token",['CODIGO'=>$token]);
                $this->db->pdo->commit();
            } catch (\Throwable $e) { if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack(); throw $e; }
        }
        return ['message'=>'Se houver uma conta correspondente, você receberá um e-mail com as instruções.'];
    }
    public function reset(array $body): array {
        $email=$this->email($body['email'] ?? null); $password=$this->password($body); $token=$body['token'] ?? '';
        $u=$this->db->one("SELECT * FROM USUARIOS WHERE EMAIL = ? AND ATIVO = 'S'",[$email]);
        if (!is_string($token) || strlen($token)!==64 || !$u || empty($u['SENHA_PROV']) || !hash_equals($u['SENHA_PROV'],substr(hash('sha256',$token),0,32)) || strtotime($u['DATA_CODIGO_CONTA'] ?? '') < time()-3600) throw new HttpError(422,'invalid_token','Link inválido ou expirado.');
        $updated=$this->db->run('UPDATE USUARIOS SET SENHA = ?, SENHA_PROV = NULL, DATA_CODIGO_CONTA = NULL WHERE ID = ? AND SENHA_PROV = ?',[$this->hash($password,(int)$u['ID']),(int)$u['ID'],$u['SENHA_PROV']]);
        if ($updated->rowCount()!==1) throw new HttpError(422,'invalid_token','Link já utilizado.');
        $this->notifications->notify('USU_ALTERACAO_SENHA',$email);
        return ['message'=>'Senha redefinida. Entre com a nova senha.'];
    }
    public function update(array $body): array {
        $u=$this->current(true); $allowed=['NOME'=>150,'APELIDO'=>50,'CIDADE'=>150,'ESTADO'=>2,'CLUBE'=>50,'ASSOCIACAO'=>50,'TIPO_CLUBE'=>50,'BIOGRAFIA'=>10000,'DATA_NASC'=>10,'ENVIA_EMAIL'=>1]; $sets=[]; $values=[];
        foreach ($allowed as $key=>$maximum) if (array_key_exists($key,$body)) {
            $raw=$body[$key] ?? '';
            if (!is_string($raw) || mb_strlen($raw)>$maximum) throw new HttpError(422,'invalid_profile','Campo inválido: '.$key);
            $value=trim($raw);
            if ($key==='DATA_NASC' && $value!=='' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))) throw new HttpError(422,'invalid_date','Data de nascimento inválida.');
            if ($key==='NOME' && mb_strlen($value)<2) throw new HttpError(422,'invalid_name','Informe seu nome.');
            if ($key==='ENVIA_EMAIL' && !in_array($value,['S','N'],true)) throw new HttpError(422,'invalid_profile','Preferência inválida.');
            $sets[]="$key = ?"; $values[]=$value==='' && $key==='DATA_NASC' ? null : $value;
        }
        if ($sets) { $values[]=date('Y-m-d H:i:s'); $values[]=(int)$u['ID']; $this->db->run('UPDATE USUARIOS SET '.implode(',',$sets).', DT_ALTERACAO = ? WHERE ID = ?',$values); }
        if ($sets) $this->notifications->notify('USU_ALTERACAO',$u['EMAIL']);
        return $this->profile($this->current(true));
    }
    public function changePassword(array $body): array {
        $u=$this->current(true); $password=$this->password($body);
        if (!is_string($body['current_password'] ?? null) || !$this->verify($body['current_password'],$u)) throw new HttpError(422,'invalid_password','Senha atual incorreta.');
        $hash=$this->hash($password,(int)$u['ID']); $this->db->run('UPDATE USUARIOS SET SENHA = ?, SENHA_PROV = NULL WHERE ID = ?',[$hash,(int)$u['ID']]);
        $this->session->rotate(); $this->session->data['fingerprint']=hash('sha256',$hash);
        $this->notifications->notify('USU_ALTERACAO_SENHA',$u['EMAIL']);
        return $this->session()+['message'=>'Senha alterada.'];
    }
    public function changeEmail(array $body): array {
        $u=$this->current(true); $email=$this->email($body['email'] ?? null);
        if (strlen($email)>50) throw new HttpError(422,'invalid_email','O banco atual permite até 50 caracteres no novo e-mail.');
        if ($this->db->one('SELECT ID FROM USUARIOS WHERE EMAIL = ?',[$email])) throw new HttpError(409,'email_exists','E-mail já cadastrado.');
        if (empty($body['code'])) {
            $code=bin2hex(random_bytes(10));
            $this->db->pdo->beginTransaction();
            try {
                $this->db->run('UPDATE USUARIOS SET EMAIL_NOVO = ?, CODIGO_CONTA = ?, DATA_CODIGO_CONTA = ? WHERE ID = ?',[$email,$code,date('Y-m-d H:i:s'),(int)$u['ID']]);
                $this->notifications->security('USU_ALTERACAO_EMAIL_ENVCODIGO',$u['EMAIL'],$email,'Confirme o novo e-mail MDAWiki',"Código: $code",['CODIGO'=>$code,'EMAIL_NOVO'=>$email]);
                $this->db->pdo->commit();
            } catch (\Throwable $error) { if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack(); throw $error; }
            return ['message'=>'Código enviado para o novo e-mail.'];
        }
        if (!is_string($body['code']) || $email!==$u['EMAIL_NOVO'] || empty($u['CODIGO_CONTA']) || !hash_equals($u['CODIGO_CONTA'],$body['code']) || strtotime($u['DATA_CODIGO_CONTA'] ?? '') < time()-3600) throw new HttpError(422,'invalid_code','Código inválido ou expirado.');
        $this->db->run('UPDATE USUARIOS SET EMAIL = ?, EMAIL_NOVO = NULL, CODIGO_CONTA = NULL, DATA_CODIGO_CONTA = NULL WHERE ID = ?',[$email,(int)$u['ID']]);
        $this->notifications->notify('USU_ALTERACAO_EMAIL',$email,'',['EMAIL_ANTIGO'=>$u['EMAIL'],'EMAIL_NOVO'=>$email]);
        return $this->session()+['message'=>'E-mail alterado.'];
    }
}
