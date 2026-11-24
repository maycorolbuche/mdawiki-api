<?php
declare(strict_types=1);
namespace MDAWiki;
final class Session
{
    public array $data = [];
    public function __construct(private bool $native = false, ?string $directory = null) {
        if ($native) {
            if ($directory!==null) { if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create session storage');ini_set('session.save_handler','files');session_save_path($directory); }
            ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', '2592000');
            session_name('mdawiki_session');
            $secure = getenv('SESSION_SECURE') === 'false' ? false : (getenv('SESSION_SECURE') === 'true' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
            session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>$secure, 'httponly'=>true, 'samesite'=>$secure ? 'None' : 'Lax']);
            session_start(); $this->data =& $_SESSION;
        }
        $this->data['csrf'] ??= bin2hex(random_bytes(32));
    }
    public function rotate(): void { if ($this->native) session_regenerate_id(true); $this->data['csrf'] = bin2hex(random_bytes(32)); }
    public function remember(int $seconds): void { if ($this->native && $seconds>0) { $params=session_get_cookie_params();setcookie(session_name(),session_id(),['expires'=>time()+$seconds,'path'=>'/','secure'=>$params['secure'],'httponly'=>true,'samesite'=>$params['samesite']]); } }
    public function csrf(string $token): void { if ($token === '' || !hash_equals($this->data['csrf'], $token)) throw new HttpError(403, 'csrf_failed', 'Sessão expirada. Atualize a página e tente novamente.'); }
}
