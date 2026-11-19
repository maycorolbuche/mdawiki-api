<?php
use MDAWiki\StudyMaterial;

$directory = sys_get_temp_dir() . '/mdawiki-study-' . bin2hex(random_bytes(6));
$calls = 0;
$service = new StudyMaterial($directory, function (string $url) use (&$calls): string {
    $calls++;
    check($url === 'https://plus.mda.wiki.br/meta/AD-002.json', 'Lookup uses legacy specialty code, not the article slug');
    return '{"url":"https://plus.mda.wiki.br/especialidade-de-avaliacao-da-comunidade/"}';
});
$html = '<template class="mda-code">AD-002</template>';
check($service->lookup('<p>Sem especialidade</p>') === null && $calls === 0, 'Ordinary articles do not request Plus');
check($service->lookup($html) === 'https://plus.mda.wiki.br/especialidade-de-avaliacao-da-comunidade/', 'Resolves the actual metadata URL');
check($service->lookup($html) !== null && $calls === 1, 'Repeated lookup uses cached metadata');
check(StudyMaterial::code("<template class='mda-code'>../../secrets</template>") === null, 'Rejects traversal in specialty codes');
$cache = $directory . '/study-material/' . hash('sha256', 'AD-002') . '.json';
file_put_contents($cache, json_encode(['expires' => 0, 'url' => 'https://plus.mda.wiki.br/anterior/']));
$failure = new StudyMaterial($directory, fn() => throw new RuntimeException('Offline'));
check($failure->lookup($html) === 'https://plus.mda.wiki.br/anterior/', 'Upstream failures keep stale material without breaking the article');
unlink($cache);
$missing = new StudyMaterial($directory, fn() => '{}');
check($missing->lookup($html) === null, 'Missing metadata hides the link');
foreach (['javascript:alert(1)', 'https://evil.test/', 'https://plus.mda.wiki.br.evil.test/', 'https://user@plus.mda.wiki.br/', 'https://plus.mda.wiki.br:444/'] as $unsafe) {
    unlink($cache);
    check((new StudyMaterial($directory, fn() => json_encode(['url' => $unsafe])))->lookup($html) === null, 'Rejects unsafe metadata URLs');
}
unlink($cache);
rmdir($directory . '/study-material');
rmdir($directory);
echo "Study material metadata and cache checks passed.\n";
