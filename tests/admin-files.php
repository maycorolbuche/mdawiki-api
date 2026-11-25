<?php
declare(strict_types=1);

use MDAWiki\{AdminFiles, Database, HttpError, MediaStorage, NormalizedPages};

require dirname(__DIR__) . '/config/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new Database(new PDO('sqlite::memory:'));
$db->pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, storage_key TEXT, size_bytes INTEGER);
    CREATE TABLE users (id INTEGER PRIMARY KEY, avatar_filename TEXT);
    CREATE TABLE images (id INTEGER PRIMARY KEY, file_id INTEGER, meta TEXT);
    CREATE TABLE image_revisions (id INTEGER PRIMARY KEY, file_id INTEGER);
    CREATE TABLE page_media (page_id INTEGER, image_id INTEGER);
    CREATE TABLE pages (id INTEGER PRIMARY KEY, slug TEXT, kind TEXT, legacy_identifier TEXT, source TEXT, rendered_html TEXT);
    CREATE TABLE page_revisions (id INTEGER PRIMARY KEY, source TEXT, rendered_html TEXT);
    INSERT INTO files VALUES (1, "articles/linked.png", 5), (2, "articles/unused.png", 5), (3, "articles/missing.png", 5);
    INSERT INTO images VALUES (1, 1, "{}"), (2, 2, "{}"), (3, 3, "{}");
    INSERT INTO image_revisions VALUES (1, 2), (2, 3), (3, 1);
    INSERT INTO page_media VALUES (1, 1);
    INSERT INTO users VALUES (1, "missing.png"), (2, "profile.png");');
$root = sys_get_temp_dir() . '/mdawiki-admin-files-' . bin2hex(random_bytes(8));
$config = ['media_root' => $root];
$media = new MediaStorage($config);
$pages = (new ReflectionClass(NormalizedPages::class))->newInstanceWithoutConstructor();
$admin = new AdminFiles($db, $config, $pages);
try {
    foreach (['articles/linked.png', 'articles/unused.png', 'articles/orphan.png', 'users/profile.png', 'users/orphan.png'] as $key) {
        $media->write($key, 'image');
    }
    $audit = $admin->audit();
    check(array_column($audit['unlinked'], 'media_key') === ['articles/unused.png'], 'Only unused images should be classified as unlinked.');
    check(count($audit['orphan']) === 2 && count($audit['missing']) === 2, 'Audit must distinguish orphan and missing media.');
    check($admin->preview(['media_key' => 'articles/orphan.png'])['image'] === 'data:image/png;base64,aW1hZ2U=', 'Preview reads the original object.');
    foreach ([
        ['action' => 'unlinked', 'id' => 1, 'media_key' => 'articles/linked.png'],
        ['action' => 'orphan', 'location' => 'local', 'media_key' => 'users/profile.png'],
        ['action' => 'missing_file', 'id' => 2, 'media_key' => 'articles/unused.png'],
    ] as $request) {
        try {
            $admin->remove($request);
            throw new RuntimeException('Unsafe removal was accepted.');
        } catch (HttpError $error) {
            check($error->status === 409, 'Unsafe removal must return a conflict.');
        }
    }
    $admin->remove(['action' => 'unlinked', 'id' => 2, 'media_key' => 'articles/unused.png']);
    $admin->remove(['action' => 'missing_file', 'id' => 3, 'media_key' => 'articles/missing.png']);
    $admin->remove(['action' => 'missing_avatar', 'id' => 1, 'media_key' => 'users/missing.png']);
    $admin->remove(['action' => 'orphan', 'location' => 'local', 'media_key' => 'articles/orphan.png']);
    check(array_column($db->rows('SELECT id FROM files ORDER BY id'), 'id') === [1], 'Only the linked file must remain.');
    check(array_column($db->rows('SELECT file_id FROM image_revisions ORDER BY id'), 'file_id') === [1], 'Only revisions tied to removed files must be deleted.');
    check($db->one('SELECT file_id FROM images WHERE id = 3')['file_id'] === null, 'Missing file removal must clear images.file_id.');
    check($db->one('SELECT avatar_filename FROM users WHERE id = 1')['avatar_filename'] === null, 'Clear the missing avatar while preserving the account.');
    check($media->read('articles/unused.png') === null && $media->read('articles/orphan.png') === null, 'The selected physical copies must be removed.');
} finally {
    if (is_dir($root)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($root);
    }
}
echo "Administrative media audit and removal checks passed.\n";
