<?php
/**
 * Relais CLI, à installer à côté de index.php (studioaty.com/api/sms/).
 * NE modifie pas le webhook qui fonctionne et ne sert jamais de page web.
 *
 * Configuration hors Git, via environnement CRON :
 * STUDIOATY_PAIEMENT_RELAIS_URL=https://paiement.studioaty.com/api/relais/v1/sms
 * STUDIOATY_PAIEMENT_RELAIS_TERMINAL=tel_...
 * STUDIOATY_PAIEMENT_RELAIS_SECRET=<clé du terminal fournie une seule fois>
 * Pour plusieurs SIM, remplacer TERMINAL/SECRET par :
 * STUDIOATY_PAIEMENT_SIM_CONFIG_JSON={"SIM1":{"terminal":"tel_...","secret":"..."}}
 * STUDIOATY_PAIEMENT_SMS_START_ID=<id initial des SMS à synchroniser>
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
date_default_timezone_set('UTC');
function envRequis(string $nom): string {
    $valeur = getenv($nom);
    if (!is_string($valeur) || trim($valeur) === '') throw new RuntimeException('Configuration requise : '.$nom);
    return $valeur;
}
function uuidDeterministe(string $terminal, int $smsId): string {
    $hex = substr(hash('sha256', $terminal.':'.$smsId), 0, 32);
    $hex[12] = '4'; // UUID version 4 stable pour les nouvelles tentatives
    $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.
        substr($hex, 16, 4).'-'.substr($hex, 20, 12);
}
function dateIso(mixed $valeur): string {
    if (!is_string($valeur)) throw new RuntimeException('Date SMS non reconnue');
    $date = new DateTimeImmutable($valeur, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
function transmettre(string $url, string $terminal, string $secret, array $sms): bool {
    $chemin = '/api/relais/v1/sms';
    $corps = json_encode($sms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $horodatage = (string)round(microtime(true) * 1000);
    $signature = hash_hmac('sha256', $horodatage."\nPOST\n".$chemin."\n".$corps, $secret);
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('cURL indisponible');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corps,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json',
            'X-Relais: '.$terminal,
            'X-Horodatage: '.$horodatage,
            'X-Signature: '.$signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $reponse = curl_exec($curl);
    $statut = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($reponse) || !in_array($statut, [200, 201], true)) return false;
    $json = json_decode($reponse, true);
    return is_array($json) && ($json['ok'] ?? false) === true;
}
try {
    $url = envRequis('STUDIOATY_PAIEMENT_RELAIS_URL');
    $rawMap = getenv('STUDIOATY_PAIEMENT_SIM_CONFIG_JSON');
    $map = [];
    $terminauxDejaUtilises = [];
    $secretsDejaUtilises = [];
    if (is_string($rawMap) && trim($rawMap) !== '') {
        $parsedMap = json_decode($rawMap, true, 8, JSON_THROW_ON_ERROR);
        if (!str_starts_with(trim($rawMap), '{') || !is_array($parsedMap) ||
            count($parsedMap) > 20 || count($parsedMap) === 0) {
            throw new RuntimeException('Configuration des SIM invalide');
        }
        foreach ($parsedMap as $nomSim => $identifiants) {
            // json_decode peut convertir la clé JSON "0" ou "1" en entier ;
            // les numéros de slots SIM numériques restent pourtant valides.
            $nomSim = (string)$nomSim;
            if ($nomSim === '' || strlen($nomSim) > 80 ||
                !is_array($identifiants) || !is_string($identifiants['terminal'] ?? null) ||
                !is_string($identifiants['secret'] ?? null) ||
                !preg_match('/^tel_[A-Za-z0-9_-]{1,36}$/', $identifiants['terminal']) ||
                strlen($identifiants['secret']) < 32) {
                throw new RuntimeException('Configuration des SIM invalide');
            }
            if (isset($terminauxDejaUtilises[$identifiants['terminal']]) ||
                isset($secretsDejaUtilises[hash('sha256', $identifiants['secret'])])) {
                throw new RuntimeException('Chaque SIM doit avoir un terminal et un secret distincts');
            }
            $terminauxDejaUtilises[$identifiants['terminal']] = true;
            $secretsDejaUtilises[hash('sha256', $identifiants['secret'])] = true;
            $map[$nomSim] = [
                'terminal' => $identifiants['terminal'],
                'secret' => $identifiants['secret'],
            ];
        }
    }
    $terminal = $map === [] ? envRequis('STUDIOATY_PAIEMENT_RELAIS_TERMINAL') : null;
    $secret = $map === [] ? envRequis('STUDIOATY_PAIEMENT_RELAIS_SECRET') : null;
    if ($map === [] && (
        !is_string($terminal) || !preg_match('/^tel_[A-Za-z0-9_-]{1,36}$/', $terminal) ||
        !is_string($secret) || strlen($secret) < 32
    )) {
        throw new RuntimeException('Identifiants du terminal invalides');
    }
    $depart = envRequis('STUDIOATY_PAIEMENT_SMS_START_ID');
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
        ($parts['path'] ?? '') !== '/api/relais/v1/sms' ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
        !preg_match('/^[0-9]+$/', $depart)) {
        throw new RuntimeException('URL ou point de départ invalide');
    }
    $prive = dirname(__DIR__, 3).'/studioaty_sms_private';
    $dbPath = $prive.'/messages.sqlite';
    if (!is_file($dbPath)) throw new RuntimeException('Base SMS privée introuvable');
    $verrou = fopen($prive.'/paiement-relai.lock', 'c');
    if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) exit(0);
    $db = new PDO('sqlite:'.$dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS sms_paiement_transmissions (
        sms_id INTEGER PRIMARY KEY, statut TEXT NOT NULL, essais INTEGER NOT NULL,
        prochain_essai INTEGER NOT NULL, maj_le TEXT NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS sms_paiement_transmissions_reprise
        ON sms_paiement_transmissions(statut, prochain_essai, sms_id)');
    // Le récepteur source applique une durée de conservation aux SMS.
    // Nettoyer les pointeurs de suivi dont le SMS source a été purgé.
    $db->exec('DELETE FROM sms_paiement_transmissions
        WHERE sms_id NOT IN (SELECT id FROM sms)');
    // Réserver 15 places aux nouveaux SMS et 5 aux reprises. Ainsi,
    // une panne prolongée sur d'anciens messages ne bloque pas les nouveaux.
    $nouveaux = $db->prepare("SELECT s.id,s.sender,s.body,s.received_at,s.sim
        FROM sms s LEFT JOIN sms_paiement_transmissions t ON t.sms_id=s.id
        WHERE s.id>:depart AND t.sms_id IS NULL
        ORDER BY s.id ASC LIMIT 15");
    $nouveaux->execute([':depart' => (int)$depart]);
    $messages = $nouveaux->fetchAll();
    $placesReprise = 20 - count($messages);
    if ($placesReprise > 0) {
        $reprises = $db->prepare("SELECT s.id,s.sender,s.body,s.received_at,s.sim
            FROM sms s INNER JOIN sms_paiement_transmissions t ON t.sms_id=s.id
            WHERE s.id>:depart AND t.statut='reessayer' AND t.prochain_essai<=:now
            ORDER BY t.prochain_essai ASC,s.id ASC LIMIT :limite");
        $reprises->bindValue(':depart', (int)$depart, PDO::PARAM_INT);
        $reprises->bindValue(':now', time(), PDO::PARAM_INT);
        $reprises->bindValue(':limite', $placesReprise, PDO::PARAM_INT);
        $reprises->execute();
        $messages = array_merge($messages, $reprises->fetchAll());
    }
    $succes = 0; $erreurs = 0;
    foreach ($messages as $s) {
        $id = (int)$s['id'];
        $sim = (string)($s['sim'] ?? '');
        $identifiants = $map === [] ? ['terminal' => $terminal, 'secret' => $secret] : ($map[$sim] ?? null);
        $ok = false;
        // Une SIM absente de la configuration reste en attente ; elle n'est
        // JAMAIS transmise sous l'identité d'une autre SIM.
        if ($identifiants !== null) {
            try {
                $sms = [
                    'id' => uuidDeterministe($identifiants['terminal'], $id),
                    'expediteur' => (string)$s['sender'],
                    'contenu' => (string)$s['body'],
                    'recu_le' => dateIso($s['received_at']),
                    'sim' => $sim,
                ];
                $ok = transmettre($url, $identifiants['terminal'], $identifiants['secret'], $sms);
            } catch (Throwable $ignore) {
                $ok = false;
            }
        }
        $maj = $db->prepare("INSERT INTO sms_paiement_transmissions
            (sms_id,statut,essais,prochain_essai,maj_le) VALUES (:id,:etat,1,:next,:date)
            ON CONFLICT(sms_id) DO UPDATE SET
            statut=:etat, essais=essais+1, prochain_essai=:next, maj_le=:date");
        // Reprise toutes les 15 minutes tant que l'API ne confirme pas,
        // sans limite de tentatives qui ferait perdre un SMS durablement.
        $maj->execute([
            ':id' => $id, ':etat' => $ok ? 'transmis' : 'reessayer',
            ':next' => $ok ? 0 : time()+900,
            ':date' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        if ($ok) $succes++; else $erreurs++;
    }
    echo 'SMS transmis: '.$succes.' ; en attente de reprise: '.$erreurs.PHP_EOL;
    flock($verrou, LOCK_UN);
    fclose($verrou);
} catch (Throwable $e) {
    // Jamais de texte SMS, de secret ou de réponse distante dans le journal.
    fwrite(STDERR, 'Relais paiement SMS: échec technique. Vérifiez la configuration et les journaux système.'.PHP_EOL);
    exit(1);
}
