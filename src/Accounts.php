<?php
declare(strict_types=1);
namespace MDAWiki;
use Closure;
final class Accounts
{
    private WikiMail $notifications;
    public function __construct(private Database $db, private Session $session, private array $config, Closure $mail, ?WikiMail $notifications = null) { $this->notifications=$notifications ?? new WikiMail($config,$mail); }
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
    private function password(array $body, int $minimum = 10): string {
        $password = $body['password'] ?? '';
        if (!is_string($password) || mb_strlen($password) < $minimum || strlen($password) > 1024) throw new HttpError(422, 'invalid_password', "Use uma senha com pelo menos {$minimum} caracteres.");
        if ($password !== ($body['password_confirmation'] ?? null)) throw new HttpError(422, 'password_mismatch', 'A confirmação de senha está diferente.');
        return $password;
    }
    private function email(mixed $email): string {
        if (!is_string($email) || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'invalid_email', 'Informe um e-mail válido.');
        return mb_strtolower(trim($email));
    }
    private function readAccount(string $field, int|string $value): ?array
    {
        if (!($this->config['normalized_read_only'] ?? false)) {
            return $this->db->one('SELECT * FROM USUARIOS WHERE ' . $field . ' = ?', [$value]);
        }
        $column = $field === 'ID' ? 'id' : 'email';
        $rows = $this->db->rows("SELECT id AS ID, email AS EMAIL, password_hash AS SENHA, public_id AS PUBLIC_ID,
            name AS NOME, display_name AS APELIDO, role AS role, CASE role WHEN 'reader' THEN 0 WHEN 'admin' THEN 5 ELSE 1 END AS NIVEL, avatar_filename AS FOTO,
            birth_date AS DATA_NASC, city AS CIDADE, state_code AS ESTADO, club AS CLUBE,
            association AS ASSOCIACAO, club_type AS TIPO_CLUBE, biography AS BIOGRAFIA,
            created_at AS DT_CADASTRO, updated_at AS DT_ALTERACAO,
            CASE WHEN is_active = 1 THEN 'S' ELSE 'N' END AS ATIVO
            FROM users WHERE {$column} = ? LIMIT 2", [$value]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    public function current(bool $required = false): ?array {
        $normalized = $this->config['normalized_read_only'] ?? false;
        $user = isset($this->session->data['id']) && ($this->session->data['normalized'] ?? false) === $normalized
            ? $this->readAccount('ID', (int) $this->session->data['id']) : null;
        if ($user && ($user['ATIVO'] !== 'S' || ($user['SENHA'] ?? '') === '' || !hash_equals($this->session->data['fingerprint'] ?? '', hash('sha256', $user['SENHA'])) || ($this->session->data['expires'] ?? 0) < time())) $user = null;
        if (!$user) { unset($this->session->data['id'], $this->session->data['fingerprint']); if ($required) throw new HttpError(401, 'login_required', 'Entre na sua conta para continuar.'); }
        return $user;
    }
    public function profile(array $user): array {
        $fields = ['ID','NOME','APELIDO','EMAIL','DATA_NASC','CIDADE','ESTADO','CLUBE','ASSOCIACAO','TIPO_CLUBE','BIOGRAFIA','NIVEL','role','ATIVO','DT_CADASTRO','DT_ALTERACAO'];
        $data = array_intersect_key($user, array_flip($fields));
        $data['photo'] = self::photoUrl($user,$this->config);
        return $data;
    }
    public function notifyProfile(int $id, string $action = 'update'): void
    {
        $user = $this->readAccount('ID', $id);
        if (!$user) return;
        $profile = $this->profile($user);
        $fields = [
            'ID' => 'ID', 'NOME' => 'Nome', 'APELIDO' => 'Nome de exibição', 'EMAIL' => 'E-mail',
            'DATA_NASC' => 'Data de nascimento', 'CIDADE' => 'Cidade', 'ESTADO' => 'Estado',
            'CLUBE' => 'Clube', 'ASSOCIACAO' => 'Associação', 'TIPO_CLUBE' => 'Tipo de clube',
            'BIOGRAFIA' => 'Biografia', 'NIVEL' => 'Nível de acesso',
            'ATIVO' => 'Ativo', 'DT_CADASTRO' => 'Cadastro', 'DT_ALTERACAO' => 'Última alteração',
        ];
        $details = [];
        foreach ($fields as $key => $label) $details[$label] = $profile[$key] ?? null;
        $path = ($user['ATIVO'] ?? null) === 'S' && !empty($user['PUBLIC_ID'])
            ? '/u/' . rawurlencode($user['PUBLIC_ID']) : null;
        $this->notifications->event('user', $action, $user['NOME'] ?? $user['EMAIL'], $user, $details, $profile['photo'], $path);
    }

    public static function photoUrl(array $user, array $config, bool $thumbnail = false): ?string {
        $file=$thumbnail && !empty($user['FOTO_32X32']) ? $user['FOTO_32X32'] : ($user['FOTO'] ?? '');
        return $file!=='' && $file!==null ? rtrim($config['media_public_url'],'/').'/users/'.rawurlencode(basename($file)) : null;
    }
    public function publicProfile(string $publicId, int $offset = 0): array
    {
        $user = $this->db->one('SELECT id, name, display_name, birth_date, city, state_code, club, association,
            club_type, biography, avatar_filename AS FOTO FROM users WHERE public_id = ? AND is_active = 1', [$publicId]);
        if (!$user) throw new HttpError(404, 'user_not_found', 'Perfil não encontrado.');
        $user['photo'] = self::photoUrl($user, $this->config);
        $limit = 20;
        $edits = $this->db->rows("SELECT p.slug, COALESCE(NULLIF(p.title, ''), p.slug) AS title,
                MAX(r.created_at) AS edited_at
            FROM page_revisions r JOIN pages p ON p.id = r.page_id
            WHERE r.created_by = ? AND COALESCE(r.access_level_id, 1) <= ?
              AND COALESCE(r.is_expired, 0) = 0 AND p.is_searchable = 1
              AND CHAR_LENGTH(p.identifier) > 1 AND p.kind <> 'image' AND p.kind <> 'system'
            GROUP BY p.id, p.slug, p.title
            ORDER BY edited_at DESC, p.id DESC LIMIT ? OFFSET ?",
            [(int) $user['id'], (int) $this->config['public_max_level'], $limit + 1, $offset]);
        $user['edited_pages'] = array_slice($edits, 0, $limit);
        $user['next_edits_offset'] = count($edits) > $limit ? $offset + $limit : null;
        unset($user['FOTO'], $user['id']);
        return $user;
    }

    public function session(): array { $u = $this->current(); return ['user'=>$u ? $this->profile($u) : null, 'csrf_token'=>$this->session->data['csrf']]; }
    public function login(array $body): array {
        $email = $this->email($body['email'] ?? null);
        $password = $body['password'] ?? '';
        if (!is_string($password) || strlen($password) > 1024) throw new HttpError(422, 'invalid_password', 'Senha inválida.');
        $u = $this->readAccount('EMAIL', $email);
        if (!$u || !$this->verify($password, $u)) throw new HttpError(401, 'invalid_credentials', 'E-mail ou senha inválidos. Se você ainda precisa cadastrar uma senha, use a opção de redefinição.');
        if ($u['ATIVO'] !== 'S') throw new HttpError(403, 'inactive_account', 'Ative sua conta pelo código enviado por e-mail.');
        $normalized = $this->config['normalized_read_only'] ?? false;
        if (!str_starts_with($u['SENHA'], 'p2$')) {
            $hash = $this->hash($password, (int) $u['ID']);
            if ($normalized) {
                $saved = $this->db->run('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?', [$hash, (int) $u['ID'], $u['SENHA']]);
                if ($saved->rowCount() !== 1) throw new HttpError(409, 'login_retry', 'Tente entrar novamente.');
            } else {
                $this->db->run('UPDATE USUARIOS SET SENHA = ?, SENHA_PROV = NULL WHERE ID = ?', [$hash, (int) $u['ID']]);
            }
            $u['SENHA'] = $hash;
        }
        $lifetime=($body['remember'] ?? false)===true?2592000:43200;
        $this->session->rotate(); $this->session->data['id'] = (int) $u['ID']; $this->session->data['fingerprint'] = hash('sha256', $u['SENHA']); $this->session->data['expires'] = time() + $lifetime;
        $this->session->data['normalized'] = $normalized;
        if ($lifetime>43200) $this->session->remember($lifetime);
        $update = $normalized
            ? 'UPDATE users SET has_logged_in = 1, last_seen_at = ? WHERE id = ?'
            : "UPDATE USUARIOS SET FEZ_LOGIN = 'S', DT_ULT_ACESSO = ? WHERE ID = ?";
        $neverLoggedIn = $normalized ? 'COALESCE(has_logged_in, 0) = 0' : "COALESCE(FEZ_LOGIN, 'N') <> 'S'";
        $values = [date('Y-m-d H:i:s'), (int) $u['ID']];
        $firstLogin = $this->db->run($update . ' AND ' . $neverLoggedIn, $values)->rowCount() === 1;
        if (!$firstLogin) $this->db->run($update, $values);
        return $this->session() + ['first_login' => $firstLogin];
    }
    public function logout(): array { $this->session->data = []; $this->session->rotate(); return $this->session(); }
    public function register(array $body): array {
        $email = $this->email($body['email'] ?? null); $password = $this->password($body, 6);
        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) throw new HttpError(422, 'invalid_name', 'Informe seu nome.');
        if ($this->config['normalized_read_only'] ?? false) {
            return $this->registerNormalized($email, $password, $name);
        }
        if ($this->db->one('SELECT ID FROM USUARIOS WHERE EMAIL = ?', [$email])) throw new HttpError(409, 'email_exists', 'Este e-mail já possui cadastro.');
        $this->hash($password, 0); // Check configuration before writing anything.
        $code = $this->legacyActivationCode(); $now = date('Y-m-d H:i:s');
        $this->db->pdo->beginTransaction();
        try {
            $this->db->run("INSERT INTO USUARIOS (EMAIL,NOME,SENHA,APELIDO,CODIGO_CONTA,DATA_CODIGO_CONTA,DT_CADASTRO,ATIVO,NIVEL) VALUES (?,?,?,?,?,?,?,'N',1)", [$email,$name,bin2hex(random_bytes(16)),mb_substr(strstr($email, '@', true),0,50),$code,$now,$now]);
            $id = (int) $this->db->pdo->lastInsertId(); $this->db->run('UPDATE USUARIOS SET SENHA = ? WHERE ID = ?', [$this->hash($password,$id),$id]);
            $this->notifications->security($email, 'Ative sua conta MDAWiki', 'Use este código para ativar sua conta.', $code,
                $this->config['frontend_url'] . '/p/ativar-conta?code=' . $code . '&email=' . rawurlencode($email));
            $this->db->pdo->commit();
        } catch (\Throwable $e) { if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack(); throw $e; }
        $this->notifyProfile($id, 'new');
        return ['message'=>'Cadastro criado. Confira seu e-mail para ativar a conta.'];
    }
    private function registerNormalized(string $email, string $password, string $name): array
    {
        $this->hash($password, 0);
        $pdo = $this->db->pdo;
        // Imported e-mails can have duplicates, so serialize new registrations per address.
        $lock = 'mdawiki-register-' . substr(hash('sha256', $pdo->query('SELECT DATABASE()')->fetchColumn() . ':' . $email), 0, 40);
        if ((int) $this->db->one('SELECT GET_LOCK(?, 5) AS acquired', [$lock])['acquired'] !== 1) {
            throw new HttpError(503, 'registration_busy', 'Tente cadastrar novamente em alguns instantes.');
        }
        try {
            if ($this->db->one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
                throw new HttpError(409, 'email_exists', 'Este e-mail já possui cadastro.');
            }
            $pdo->beginTransaction();
            $this->db->run('INSERT INTO users (email, name, password_hash, display_name, public_id,
                is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?)',
                [$email, $name, '', mb_substr(strstr($email, '@', true), 0, 50),
                    bin2hex(random_bytes(16)), date('Y-m-d H:i:s')]);
            $id = (int) $pdo->lastInsertId();
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$this->hash($password, $id), $id]);
            $this->sendNormalizedActivation($id, $email, 'Ative sua conta MDAWiki');
            $pdo->commit();
        } finally {
            try {
                if ($pdo->inTransaction()) $pdo->rollBack();
            } finally {
                $this->db->run('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        $this->notifyProfile($id, 'new');
        return ['message' => 'Cadastro criado. Confira seu e-mail para ativar a conta.'];
    }

    private function activationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function legacyActivationCode(): string
    {
        do {
            $code = $this->activationCode();
        } while ($this->db->one("SELECT ID FROM USUARIOS WHERE CODIGO_CONTA = ? AND ATIVO = 'N' LIMIT 1", [$code]));
        return $code;
    }

    private function sendNormalizedActivation(int $id, string $email, string $subject): void
    {
        $expires = date('Y-m-d H:i:s', time() + 86400);
        $previous = $this->db->one('SELECT token_hash FROM user_activations WHERE user_id = ?', [$id]);
        $this->db->run('DELETE FROM user_activations WHERE user_id = ?', [$id]);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->activationCode();
            $hash = hash('sha256', $code);
            if ($hash === ($previous['token_hash'] ?? null)) continue;
            try {
                $this->db->run('INSERT INTO user_activations (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [$id, $hash, $expires]);
                break;
            } catch (\PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            }
        }
        if ($attempt === 10) throw new HttpError(503, 'activation_code_unavailable', 'Tente solicitar um código novamente.');
        $this->notifications->security($email, $subject, 'Use este código para ativar sua conta.', $code,
            $this->config['frontend_url'] . '/p/ativar-conta?code=' . $code . '&email=' . rawurlencode($email));
    }

    private function activateNormalized(string $code, ?string $email): array
    {
        $hash = hash('sha256', $code);
        $activation = $this->db->one('SELECT user_id FROM user_activations WHERE token_hash = ?', [$hash]);
        if (!$activation) throw new HttpError(422, 'invalid_code', 'Código inválido ou expirado. Solicite outro código.');
        $id = (int) $activation['user_id'];
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $user = $this->db->one('SELECT is_active, email FROM users WHERE id = ? FOR UPDATE', [$id]);
            if (!$user || ($email !== null && strcasecmp($user['email'], $email) !== 0) || (int) $user['is_active'] !== 0 || $this->db->run(
                'DELETE FROM user_activations WHERE user_id = ? AND token_hash = ? AND expires_at > ?',
                [$id, $hash, date('Y-m-d H:i:s')]
            )->rowCount() !== 1) {
                throw new HttpError(422, 'invalid_code', 'Código inválido ou expirado. Solicite outro código.');
            }
            $this->db->run('UPDATE users SET is_active = 1, updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        $this->notifyProfile($id);
        return ['message' => 'Conta ativada. Você já pode entrar.'];
    }

    private function resendNormalized(string $email): array
    {
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $users = $this->db->rows('SELECT id, is_active FROM users WHERE email = ? LIMIT 2 FOR UPDATE', [$email]);
            if (count($users) === 1 && (int) $users[0]['is_active'] === 0) {
                $this->sendNormalizedActivation((int) $users[0]['id'], $email, 'Código de ativação MDAWiki');
            }
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        return ['message' => 'Se houver uma conta correspondente, você receberá um e-mail com as instruções.'];
    }

    public function activation(array $body): array {
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $email = preg_match('/^[0-9]{6}$/D', $code) ? $this->email($body['email'] ?? null) : null;
        if ($this->config['normalized_read_only'] ?? false) return $this->activateNormalized($code, $email);
        $u = $code !== '' ? $this->db->one("SELECT * FROM USUARIOS WHERE CODIGO_CONTA = ? AND ATIVO = 'N'", [$code]) : null;
        if (!$u || ($email !== null && strcasecmp($u['EMAIL'], $email) !== 0) || strtotime($u['DATA_CODIGO_CONTA'] ?? '') < time() - 86400) throw new HttpError(422, 'invalid_code', 'Código inválido ou expirado. Solicite outro código.');
        $activated = $this->db->run("UPDATE USUARIOS SET ATIVO = 'S', CODIGO_CONTA = NULL, DATA_CODIGO_CONTA = NULL
            WHERE ID = ? AND CODIGO_CONTA = ? AND ATIVO = 'N'", [(int) $u['ID'], $code]);
        if ($activated->rowCount() !== 1) throw new HttpError(422, 'invalid_code', 'Código inválido ou expirado. Solicite outro código.');
        $this->notifyProfile((int) $u['ID']);
        return ['message'=>'Conta ativada. Você já pode entrar.'];
    }
    public function requestCode(array $body, bool $reset): array {
        $email = $this->email($body['email'] ?? null);
        if (!$reset && ($this->config['normalized_read_only'] ?? false)) return $this->resendNormalized($email);
        $u = $this->db->one('SELECT * FROM USUARIOS WHERE EMAIL = ?', [$email]);
        if ($u && (($reset && $u['ATIVO'] === 'S') || (!$reset && $u['ATIVO'] !== 'S'))) {
            $token = $reset ? bin2hex(random_bytes(32)) : $this->legacyActivationCode(); $now = date('Y-m-d H:i:s');
            $column = $reset ? 'SENHA_PROV' : 'CODIGO_CONTA';
            $stored = $reset ? substr(hash('sha256', $token),0,32) : $token;
            $link = $this->config['frontend_url'] . ($reset ? '/p/redefinir-senha?email=' . rawurlencode($email) . '&token=' : '/p/ativar-conta?code=') . $token . ($reset ? '' : '&email=' . rawurlencode($email));
            $this->db->pdo->beginTransaction();
            try {
                $this->db->run("UPDATE USUARIOS SET $column = ?, DATA_CODIGO_CONTA = ? WHERE ID = ?", [$stored,$now,(int) $u['ID']]);
                if ($reset) {
                    $this->notifications->security($email, 'Redefinir senha MDAWiki', 'Redefina sua senha pelo link. Ele expira em uma hora.', url: $link);
                } else {
                    $this->notifications->security($email, 'Código de ativação MDAWiki', 'Use este código para ativar sua conta.', $token, $link);
                }
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
        $this->notifyProfile((int) $u['ID']);
        return ['message'=>'Senha redefinida. Entre com a nova senha.'];
    }
    public function update(array $body): array {
        $u=$this->current(true); $allowed=['NOME'=>150,'APELIDO'=>50,'CIDADE'=>150,'ESTADO'=>2,'CLUBE'=>50,'ASSOCIACAO'=>50,'TIPO_CLUBE'=>50,'BIOGRAFIA'=>10000,'DATA_NASC'=>10]; $sets=[]; $values=[];
        $normalized = $this->config['normalized_read_only'] ?? false;
        $columns = ['NOME'=>'name','APELIDO'=>'display_name','CIDADE'=>'city','ESTADO'=>'state_code','CLUBE'=>'club',
            'ASSOCIACAO'=>'association','TIPO_CLUBE'=>'club_type','BIOGRAFIA'=>'biography','DATA_NASC'=>'birth_date'];
        foreach ($allowed as $key=>$maximum) if (array_key_exists($key,$body)) {
            $raw=$body[$key] ?? '';
            if (!is_string($raw) || mb_strlen($raw)>$maximum) throw new HttpError(422,'invalid_profile','Campo inválido: '.$key);
            $value=trim($raw);
            if ($key==='DATA_NASC' && $value!=='' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))) throw new HttpError(422,'invalid_date','Data de nascimento inválida.');
            if ($key==='NOME' && mb_strlen($value)<2) throw new HttpError(422,'invalid_name','Informe seu nome.');
            $column = $normalized ? $columns[$key] : $key;
            $sets[] = "$column = ?";
            $values[] = $key === 'DATA_NASC' && $value === '' ? null : $value;
        }
        if ($sets) {
            $values[] = date('Y-m-d H:i:s');
            $values[] = (int) $u['ID'];
            $table = $normalized ? 'users' : 'USUARIOS';
            $updatedAt = $normalized ? 'updated_at' : 'DT_ALTERACAO';
            $this->db->run("UPDATE $table SET " . implode(',', $sets) . ", $updatedAt = ? WHERE id = ?", $values);
            $this->notifyProfile((int) $u['ID']);
        }
        return $this->profile($this->current(true));
    }
    public function changePassword(array $body): array {
        $u=$this->current(true); $password=$this->password($body);
        if (!is_string($body['current_password'] ?? null) || !$this->verify($body['current_password'],$u)) throw new HttpError(422,'invalid_password','Senha atual incorreta.');
        $hash=$this->hash($password,(int)$u['ID']); $this->db->run('UPDATE USUARIOS SET SENHA = ?, SENHA_PROV = NULL WHERE ID = ?',[$hash,(int)$u['ID']]);
        $this->session->rotate(); $this->session->data['fingerprint']=hash('sha256',$hash);
        $this->notifyProfile((int) $u['ID']);
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
                $this->notifications->security($email, 'Confirme o novo e-mail MDAWiki', 'Use este código para confirmar seu novo endereço de e-mail.', $code);
                $this->db->pdo->commit();
            } catch (\Throwable $error) { if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack(); throw $error; }
            return ['message'=>'Código enviado para o novo e-mail.'];
        }
        if (!is_string($body['code']) || $email!==$u['EMAIL_NOVO'] || empty($u['CODIGO_CONTA']) || !hash_equals($u['CODIGO_CONTA'],$body['code']) || strtotime($u['DATA_CODIGO_CONTA'] ?? '') < time()-3600) throw new HttpError(422,'invalid_code','Código inválido ou expirado.');
        $this->db->run('UPDATE USUARIOS SET EMAIL = ?, EMAIL_NOVO = NULL, CODIGO_CONTA = NULL, DATA_CODIGO_CONTA = NULL WHERE ID = ?',[$email,(int)$u['ID']]);
        $this->notifyProfile((int) $u['ID']);
        return $this->session()+['message'=>'E-mail alterado.'];
    }
}
