<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function out(array $value, int $status = 200): void {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $message, int $status = 400): void { out(['ok'=>false,'error'=>$message], $status); }
function token(): string { return bin2hex(random_bytes(32)); }
function device_id(): string { return bin2hex(random_bytes(16)); }
function store(): string {
    $dir = dirname(__DIR__, 2) . '/.tv-pairing-demo-store';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) fail('Stockage indisponible sur cet hébergement', 500);
    return $dir;
}
function filepath(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) fail('Identifiant incorrect', 400);
    return store() . '/d_' . $id . '.json';
}
function lock_device(string $id, callable $fn) {
    $path = filepath($id);
    if (!is_file($path)) fail('Télévision inconnue. Recharger la page TV.', 404);
    $fp = @fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) fail('Stockage indisponible', 500);
    rewind($fp);
    $state = json_decode(stream_get_contents($fp), true);
    if (!is_array($state)) { flock($fp, LOCK_UN); fclose($fp); fail('Session endommagée', 500); }
    $result = $fn($state);
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $result;
}
function code(): string {
    for ($i=0; $i<15; $i++) {
        $candidate = (string)random_int(100000, 999999);
        $taken = false;
        foreach (glob(store() . '/d_*.json') ?: [] as $file) {
            $st = json_decode(@file_get_contents($file), true);
            if (is_array($st) && ($st['code'] ?? null) === $candidate && ($st['codeExpires'] ?? 0) > time()) { $taken = true; break; }
        }
        if (!$taken) return $candidate;
    }
    fail('Impossible de générer un code', 503);
}
function auth(array $st, array $in, string $type): void {
    $key = $type === 'tv' ? 'tvToken' : 'phoneToken';
    if (!is_string($in['token'] ?? null) || !is_string($st[$key] ?? null) || !hash_equals($st[$key], $in['token'])) fail('Association non autorisée', 403);
}
function tv_payload(array $st): array {
    return ['ok'=>true,'deviceId'=>$st['id'],'paired'=>$st['paired'],'code'=>$st['code'],
        'codeExpires'=>$st['codeExpires'],'phoneOnline'=>$st['paired'] && ($st['lastPhoneSeen'] ?? 0) > time()-8,
        'lastCommand'=>$st['lastCommand'],'sequence'=>$st['sequence'],
        'lastCommandAt'=>$st['lastCommandAt']];
}
function phone_payload(array $st): array {
    return ['ok'=>true,'deviceId'=>$st['id'],'tvOnline'=>($st['lastTvSeen'] ?? 0) > time()-8,
        'sequence'=>$st['sequence'],'lastCommand'=>$st['lastCommand']];
}
function rate_limit(): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = store() . '/rate_' . hash('sha256', $ip) . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) fail('Vérification indisponible', 500);
    $r = json_decode(stream_get_contents($fp), true);
    if (!is_array($r) || ($r['start'] ?? 0) < time()-300) $r=['start'=>time(),'count'=>0];
    $r['count']++;
    rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($r));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    if ($r['count'] > 15) fail('Trop de tentatives : réessayer dans quelques minutes.', 429);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'health') out(['ok'=>true,'service'=>'tv-pairing-poc','time'=>gmdate('c')]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée', 405);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) fail('Le format JSON est requis', 415);
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail('Requête JSON invalide');
$action = (string)($in['action'] ?? '');
$id = (string)($in['deviceId'] ?? '');

if ($action === 'register_tv') {
    $existingId = (string)($in['deviceId'] ?? '');
    $existingToken = (string)($in['token'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $existingId) && is_file(filepath($existingId))) {
        $result = lock_device($existingId, function (&$st) use ($existingToken) {
            if (!hash_equals($st['tvToken'], $existingToken)) return null;
            $st['lastTvSeen'] = time();
            if (!$st['paired'] && ($st['codeExpires'] ?? 0) <= time()) {
                $st['code'] = code(); $st['codeExpires'] = time()+300;
            }
            return tv_payload($st);
        });
        if ($result !== null) out($result + ['tvToken'=>$existingToken]);
    }
    $newId = device_id();
    $newToken = token();
    $now = time();
    $st = ['id'=>$newId,'tvToken'=>$newToken,'phoneToken'=>null,'paired'=>false,'code'=>code(),
        'codeExpires'=>$now+300,'lastTvSeen'=>$now,'lastPhoneSeen'=>0,
        'lastCommand'=>null,'lastCommandAt'=>0,'sequence'=>0,'createdAt'=>$now];
    if (@file_put_contents(filepath($newId), json_encode($st), LOCK_EX) === false) fail('Impossible de créer la session', 500);
    out(tv_payload($st) + ['tvToken'=>$newToken]);
}
if ($action === 'tv_status') {
    out(lock_device($id, function (&$st) use ($in) {
        auth($st, $in, 'tv');
        $st['lastTvSeen'] = time();
        if (!$st['paired'] && ($st['codeExpires'] ?? 0) <= time()) {
            $st['code'] = code(); $st['codeExpires'] = time()+300;
        }
        return tv_payload($st);
    }));
}
if ($action === 'pair') {
    rate_limit();
    $pairCode = (string)($in['code'] ?? '');
    if (!preg_match('/^[0-9]{6}$/', $pairCode)) fail('Saisir un code à six chiffres');
    foreach (glob(store() . '/d_*.json') ?: [] as $file) {
        $candidate = json_decode(@file_get_contents($file), true);
        if (!is_array($candidate) || ($candidate['code'] ?? '') !== $pairCode || ($candidate['codeExpires'] ?? 0) <= time()) continue;
        $newPhoneToken = token();
        $result = lock_device($candidate['id'], function (&$st) use ($pairCode, $newPhoneToken) {
            if ($st['code'] !== $pairCode || $st['codeExpires'] <= time()) return null;
            $st['paired']=true; $st['phoneToken']=$newPhoneToken;
            $st['code']=null; $st['codeExpires']=0; $st['lastPhoneSeen']=time();
            return phone_payload($st);
        });
        if ($result !== null) out($result + ['phoneToken'=>$newPhoneToken]);
    }
    fail('Code introuvable ou expiré. Vérifier le code affiché sur la TV.', 404);
}
if ($action === 'phone_status') {
    out(lock_device($id, function (&$st) use ($in) {
        auth($st, $in, 'phone');
        $st['lastPhoneSeen']=time();
        return phone_payload($st);
    }));
}
if ($action === 'command') {
    $cmd = (string)($in['command'] ?? '');
    if (!in_array($cmd, ['haut','bas','gauche','droite','A','B','START'], true)) fail('Commande invalide');
    out(lock_device($id, function (&$st) use ($in, $cmd) {
        auth($st, $in, 'phone');
        $st['lastPhoneSeen']=time();
        $st['sequence']++;
        $st['lastCommand']=$cmd;
        $st['lastCommandAt']=time();
        return phone_payload($st);
    }));
}
if ($action === 'reset_tv') {
    out(lock_device($id, function (&$st) use ($in) {
        auth($st, $in, 'tv');
        $st['paired']=false; $st['phoneToken']=null; $st['lastPhoneSeen']=0;
        $st['code']=code(); $st['codeExpires']=time()+300;
        $st['lastCommand']=null; $st['lastCommandAt']=0; $st['sequence']=0;
        return tv_payload($st);
    }));
}
fail('Action inconnue', 404);
