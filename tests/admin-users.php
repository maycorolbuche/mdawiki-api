<?php
declare(strict_types=1);

use MDAWiki\{AdminUsers, Database, HttpError, MediaStorage};

require dirname(__DIR__) . '/config/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new Database(new PDO('sqlite::memory:'));
$db->pdo->exec("PRAGMA foreign_keys = ON;
    CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, email TEXT, role TEXT,
        avatar_filename TEXT, public_id TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT);
    CREATE TABLE files (id INTEGER PRIMARY KEY, storage_key TEXT);
    CREATE TABLE images (id INTEGER PRIMARY KEY, file_id INTEGER, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL);
    CREATE TABLE image_revisions (id INTEGER PRIMARY KEY, file_id INTEGER);
    INSERT INTO users VALUES
        (1, 'Admin', NULL, 'admin@example.test', 'admin', NULL, 'admin', 1, '2020-01-01', NULL),
        (2, 'Beta', 'Beto', 'beta@example.test', 'user', 'beta.png', 'beta', 1, '2024-01-01', NULL),
        (3, 'Alfa', NULL, 'alfa@example.test', 'reader', 'shared.png', 'alfa', 0, '2021-01-01', NULL),
        (4, 'Gama', NULL, 'gama@example.test', 'user', 'shared.png', 'gama', 1, '2022-01-01', NULL);
    INSERT INTO files VALUES (1, 'users/beta.png');
    INSERT INTO images VALUES (1, NULL, 2);");
$root = sys_get_temp_dir() . '/mdawiki-admin-users-' . bin2hex(random_bytes(8));
$config = ['media_root' => $root, 'media_public_url' => 'https://api.example.test/media'];
$service = new AdminUsers($db, $config);
$storage = new MediaStorage($config);

try {
    $storage->write('users/beta.png', 'avatar');
    $storage->write('users/shared.png', 'avatar');
    check(array_column($service->list([])['items'], 'id') === [1, 3, 2, 4], 'Sort by name and include inactive users.');
    check(array_column($service->list(['sort' => 'created_at', 'direction' => 'desc'])['items'], 'id') === [2, 4, 3, 1], 'Sort by registration date.');
    check($service->list(['q' => 'Beto'])['total'] === 1 && $service->list(['q' => '%'])['total'] === 0, 'Search by display name and escape wildcards.');
    $filtered = $service->list(['photo' => 'yes', 'role' => 'user', 'q' => 'beta']);
    check($filtered['total'] === 1 && $filtered['items'][0]['photo'] === 'https://api.example.test/media/users/beta.png', 'Combine search, role and photo filters and return the avatar URL.');
    check($service->list(['photo' => 'no', 'role' => 'admin'])['total'] === 1, 'List administrators without a photo.');
    check($service->list(['photo' => 'yes', 'role' => 'reader'])['total'] === 1, 'List readers with a photo.');
    foreach ([['action' => 'delete', 'id' => 1], ['action' => 'role', 'id' => 1, 'role' => 'reader']] as $request) {
        try {
            $service->change(1, $request);
            throw new RuntimeException('Self modification was accepted.');
        } catch (HttpError $error) {
            check($error->status === 403, 'Self modification must return 403.');
        }
    }
    $service->change(1, ['action' => 'role', 'id' => 2, 'role' => 'admin']);
    check($db->one('SELECT role FROM users WHERE id = 2')['role'] === 'admin', 'Change another user role.');
    $service->change(1, ['action' => 'delete', 'id' => 2]);
    check(!$db->one('SELECT id FROM users WHERE id = 2') && $storage->read('users/beta.png') === null, 'Delete account and its exclusive avatar.');
    check(!$db->one('SELECT id FROM files WHERE id = 1'), 'Remove the unused avatar catalog record.');
    check($db->one('SELECT created_by FROM images WHERE id = 1')['created_by'] === null, 'Preserve authored images.');
    $service->change(1, ['action' => 'delete', 'id' => 3]);
    check($storage->read('users/shared.png') === 'avatar', 'Preserve shared avatar.');
} finally {
    foreach (glob($root . '/users/*') ?: [] as $path) unlink($path);
    if (is_dir($root . '/users')) rmdir($root . '/users');
    if (is_dir($root)) rmdir($root);
}
echo "Administrative user management checks passed.\n";
