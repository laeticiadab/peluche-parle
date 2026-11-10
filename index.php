<?php
declare(strict_types=1);
const APP_URL='https://studioaty.com/api/sms/';
const FIRST_ADMIN_HASH='$2y$12$1DjJNjRwKx6MDRdISLnPI.VUDgaJFpjFGjmuAOb.m7Oqpn.sakSxG';
date_default_timezone_set('UTC');
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function db():PDO{
 static $pdo=null;if($pdo!==null)return $pdo;
 $dir=dirname(__DIR__,3).'/studioaty_sms_private';
 if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('private storage unavailable');
 @chmod($dir,0700);$file=$dir.'/messages.sqlite';
 $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 @chmod($file,0600);$pdo->exec('PRAGMA busy_timeout=5000');$pdo->exec('PRAGMA journal_mode=WAL');
 $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY,value TEXT NOT NULL)');
 $pdo->exec('CREATE TABLE IF NOT EXISTS sms(id INTEGER PRIMARY KEY AUTOINCREMENT,sender TEXT NOT NULL,body TEXT NOT NULL,received_at TEXT NOT NULL,created_at TEXT NOT NULL,sim TEXT,app TEXT,source TEXT)');
 $pdo->exec('CREATE INDEX IF NOT EXISTS sms_by_date ON sms(created_at DESC)');
 $pdo->exec('CREATE TABLE IF NOT EXISTS deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT,created_at TEXT NOT NULL,status INTEGER NOT NULL,reason TEXT NOT NULL)');
 $pdo->exec('CREATE INDEX IF NOT EXISTS deliveries_recent ON deliveries(id DESC)');
 $pdo->exec('CREATE TABLE IF NOT EXISTS failures(ip TEXT,at INTEGER)');
 $pdo->exec('CREATE INDEX IF NOT EXISTS failures_recent ON failures(ip,at)');
 $i=$pdo->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)');
 $i->execute(['password_hash',FIRST_ADMIN_HASH]);$i->execute(['retention','90']);
 return $pdo;
}
function opt(string $key):string{$q=db()->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?'':(string)$v;}
function saveopt(string $k,string $v):void{$q=db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');$q->execute([$k,$v]);}
function jsonout(int $status,array $data):never{
 if(!empty($GLOBALS['sms_api_call'])){
  try{
   $log=db()->prepare('INSERT INTO deliveries(created_at,status,reason) VALUES(?,?,?)');
   $log->execute([gmdate('Y-m-d\TH:i:s\Z'),$status,mb_substr((string)($data['error']??($status===201?'accepted':'response')),0,70)]);
   db()->exec('DELETE FROM deliveries WHERE id NOT IN (SELECT id FROM deliveries ORDER BY id DESC LIMIT 100)');
  }catch(Throwable $e){}
 }
 http_response_code($status);header('X-StudioATY-SMS: receiver-v2');
 header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function token():string{$v=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';return preg_match('/^Bearer\s+(.+)$/i',trim($v),$m)?trim($m[1]):'';}
function scalar(mixed $v):string{return is_string($v)||is_numeric($v)?trim((string)$v):'';}
function api():never{
 $GLOBALS['sms_api_call']=true;
 if($_SERVER['REQUEST_METHOD']!=='POST')jsonout(405,['error'=>'method_not_allowed']);
 $hash=opt('token_hash');
 if($hash==='')jsonout(503,['error'=>'webhook_not_configured']);
 if(token()===''||!hash_equals($hash,hash('sha256',token())))jsonout(401,['error'=>'unauthorized']);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)jsonout(413,['error'=>'too_large']);
 $raw=file_get_contents('php://input',false,null,0,65537);
 if($raw===false||strlen($raw)>65536)jsonout(413,['error'=>'too_large']);
 $d=json_decode($raw,true);if(!is_array($d)||array_is_list($d))jsonout(400,['error'=>'invalid_json']);
 $m=$d['message']??null;
 $text=scalar($d['text']??(is_array($m)?($m['text']??''):$m));
 $sender=scalar($d['sender']??$d['from']??'');
 if($sender===''||$text==='')jsonout(422,['error'=>'sender_and_text_required']);
 if(mb_strlen($sender)>160||mb_strlen($text)>12000)jsonout(422,['error'=>'field_too_long']);
 $received=$d['receivedAt']??$d['received_at']??null;$at=gmdate('Y-m-d\TH:i:s\Z');
 if(is_numeric($received)){$ts=(float)$received;if($ts>1e11)$ts/=1000;if($ts>0&&$ts<=time()+86400)$at=gmdate('Y-m-d\TH:i:s\Z',(int)$ts);}
 elseif(is_string($received)&&strlen($received)<90){$ts=strtotime($received);if($ts!==false&&$ts<=time()+86400)$at=gmdate('Y-m-d\TH:i:s\Z',$ts);}
 $q=db()->prepare('INSERT INTO sms(sender,body,received_at,created_at,sim,app,source) VALUES(?,?,?,?,?,?,?)');
 $q->execute([$sender,$text,$at,gmdate('Y-m-d\TH:i:s\Z'),mb_substr(scalar($d['sim']??''),0,80),mb_substr(scalar($d['app']??''),0,120),mb_substr(scalar($d['source']??''),0,120)]);
 $id=(int)db()->lastInsertId();$days=(int)opt('retention');
 if($days>0){$q=db()->prepare('DELETE FROM sms WHERE created_at<?');$q->execute([gmdate('Y-m-d\TH:i:s\Z',time()-$days*86400)]);}
 jsonout(201,['ok'=>true,'id'=>$id]);
}
$page=(string)($_GET['p']??'messages');
// Every POST on the webhook root MUST be handled by the API, even if
// Authorization/Content-Type is missing. Otherwise PHP login redirects can
// be mistaken by the Android sender for a successful webhook transfer.
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
$adminForm=$method==='POST'&&in_array($page,['login','logout','settings'],true);
if($page==='api'||($method==='POST'&&!$adminForm)){
 if($method==='GET')jsonout(200,['ok'=>true,'service'=>'StudioATY SMS','version'=>2]);
 api();
}
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Strict');ini_set('session.cookie_secure','1');
session_name('studioaty_sms_session');session_start();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
function csrf():string{return '<input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'">';}
function verify_csrf():void{if(!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Requête non autorisée.');}}
function authed():bool{return ($_SESSION['admin']??false)===true;}
function jump(string $p):never{header('Location: '.APP_URL.'?p='.rawurlencode($p),true,303);exit;}
function require_admin():void{if(!authed())jump('login');}
function top(string $heading,bool $nav=true):void{
 echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc($heading).' | StudioATY SMS</title><style>';
 echo '*,*:before,*:after{box-sizing:border-box}body{margin:0;background:#0e121c;color:#eff3fd;font:15px/1.55 system-ui,-apple-system,Segoe UI,Arial,sans-serif}a{color:#b1c8ff;text-decoration:none}button,input,select{font:inherit}button,.btn{cursor:pointer;background:#b2c8ff;color:#182b4b;border:0;border-radius:12px;padding:11px 16px;font-weight:750;display:inline-block}.secondary{background:#27334b;color:#e7edfc}.wrap{max-width:1120px;padding:25px 18px 60px;margin:auto}.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:32px}.brand{font-size:19px;font-weight:800;color:#fff}.mark{background:#b2c8ff;color:#182b4b;border-radius:11px;padding:8px 11px;margin-right:7px}nav{display:flex;align-items:center;gap:16px;flex-wrap:wrap}nav form{margin:0}nav button{padding:8px 12px}h1{font-size:31px;line-height:1.2;margin:0 0 7px}h2{font-size:19px;margin:0 0 16px}.muted{color:#a2b0cd}.panel,.stat{background:#192130;border:1px solid #344158;border-radius:17px;padding:22px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:25px 0}.stat strong{display:block;font-size:30px;margin-top:5px}.small{font-size:13px}.columns{display:grid;grid-template-columns:1fr 1fr;gap:18px}.alert{padding:14px;border-radius:12px;border:1px solid #647396;background:#23324e;margin:15px 0;overflow-wrap:anywhere}.good{border-color:#426e61;background:#1c3d34}.bad{border-color:#934a57;background:#442630}input,select{width:100%;padding:12px 14px;background:#101725;border:1px solid #40506a;border-radius:11px;color:#fff}label{display:block;font-weight:650;margin:12px 0 6px}.mono{background:#101827;border:1px solid #34445e;padding:12px;border-radius:11px;font:13px/1.6 ui-monospace,Consolas,monospace;overflow-wrap:anywhere}.bar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:19px}.search{display:grid;grid-template-columns:1fr auto;gap:10px}table{width:100%;border-collapse:collapse}th{text-align:left;color:#a4b0c9;font-size:12px;text-transform:uppercase}td,th{padding:14px 9px;border-bottom:1px solid #303c51;vertical-align:top}.textcut{max-width:540px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.msg{white-space:pre-wrap;overflow-wrap:anywhere;padding:20px;border-radius:12px;background:#111929;font-size:17px}.pill{background:#2b3957;color:#c6d7ff;font-size:12px;padding:4px 9px;border-radius:40px}.pagination{display:flex;justify-content:space-between;margin-top:22px}.login{max-width:450px;margin:8vh auto}.footer{font-size:12px;color:#8795af;margin-top:35px}@media(max-width:750px){.grid,.columns{grid-template-columns:1fr}.search{grid-template-columns:1fr}.wrap{padding:17px 13px 50px}.panel,.stat{padding:17px}h1{font-size:26px}th:nth-child(3),td:nth-child(3),th:nth-child(4),td:nth-child(4){display:none}.textcut{max-width:170px}}';
 echo '</style></head><body><main class="wrap"><div class="header"><a class="brand" href="'.APP_URL.'"><span class="mark">S</span> StudioATY SMS</a>';
 if($nav&&authed())echo '<nav><a href="?p=messages">Messages</a><a href="?p=settings">Paramètres</a><form method="post" action="?p=logout">'.csrf().'<button class="secondary">Déconnexion</button></form></nav>';
 echo '</div>';
}
function bottom():never{echo '<div class="footer">StudioATY · Accès administrateur privé · Connexion HTTPS</div></main></body></html>';exit;}
if($page==='login'){
 if(authed())jump('messages');$error='';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
  db()->prepare('DELETE FROM failures WHERE at<?')->execute([time()-900]);
  $q=db()->prepare('SELECT COUNT(*) FROM failures WHERE ip=?');$q->execute([$ip]);$locked=(int)$q->fetchColumn()>=5;
  if(!$locked&&hash_equals('admin',(string)($_POST['username']??''))&&password_verify((string)($_POST['password']??''),opt('password_hash'))){
   db()->prepare('DELETE FROM failures WHERE ip=?')->execute([$ip]);session_regenerate_id(true);$_SESSION['admin']=true;jump('messages');
  }
  db()->prepare('INSERT INTO failures(ip,at) VALUES(?,?)')->execute([$ip,time()]);
  $error=$locked?'Trop de tentatives. Réessayez dans 15 minutes.':'Identifiants incorrects.';
 }
 top('Connexion',false);echo '<section class="panel login"><h1>Connexion administrateur</h1><p class="muted">Boîte de réception privée.</p>';
 if($error)echo '<div class="alert bad">'.esc($error).'</div>';
 echo '<form method="post" action="?p=login">'.csrf().'<label>Identifiant</label><input name="username" value="admin" autocomplete="username" required><label>Mot de passe</label><input type="password" name="password" autocomplete="current-password" required><p><button>Se connecter</button></p></form></section>';bottom();
}
if($page==='logout'){require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}verify_csrf();$_SESSION=[];session_destroy();jump('login');}
require_admin();
if($page==='settings'){
 $ok='';$err='';$new='';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$action=(string)($_POST['action']??'');
  if($action==='token'){$new=bin2hex(random_bytes(32));saveopt('token_hash',hash('sha256',$new));$ok='Nouveau jeton créé. Copiez-le maintenant : il ne sera plus affiché.';}
  elseif($action==='password'){
   $old=(string)($_POST['old']??'');$pass=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');
   if(!password_verify($old,opt('password_hash')))$err='Ancien mot de passe incorrect.';
   elseif(strlen($pass)<14||strlen($pass)>200)$err='Choisissez au moins 14 caractères.';
   elseif($pass!==$confirm)$err='Confirmation différente.';
   else{saveopt('password_hash',password_hash($pass,PASSWORD_DEFAULT));$ok='Mot de passe changé.';}
  }
  elseif($action==='retention'){$days=(string)($_POST['days']??'');if(in_array($days,['7','30','90','365','0'],true)){saveopt('retention',$days);$ok='Durée de conservation enregistrée.';}else $err='Valeur incorrecte.';}
  elseif($action==='storage_test'){
   try{
    db()->beginTransaction();
    $q=db()->prepare('INSERT INTO sms(sender,body,received_at,created_at) VALUES(?,?,?,?)');
    $q->execute(['TEST_INTERNE','Test de stockage (transaction annulée)',gmdate('c'),gmdate('c')]);
    db()->rollBack();
    $ok='Test local réussi : la base accepte les écritures. Aucun faux SMS conservé.';
   }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err='Test de stockage échoué. Vérifiez les permissions du serveur.';}
  }
 }
 top('Paramètres');echo '<h1>Paramètres</h1><p class="muted">Réception JSON, sécurité et conservation.</p>';
 if($ok)echo '<div class="alert good">'.esc($ok).'</div>';if($err)echo '<div class="alert bad">'.esc($err).'</div>';
 echo '<div class="columns"><section class="panel"><h2>Connecter Android</h2><p class="muted">URL de destination HTTPS</p><div class="mono">'.APP_URL.'</div><p>En-tête : <b>Authorization: Bearer VOTRE_JETON</b></p>';
 if($new!=='')echo '<div class="alert good"><b>À copier une seule fois :</b><div class="mono">Authorization: Bearer '.esc($new).'</div></div>';
 echo '<p class="muted">État : '.(opt('token_hash')?'Jeton actif':'Aucun jeton').'</p><form method="post" action="?p=settings">'.csrf().'<input type="hidden" name="action" value="token"><button>'.(opt('token_hash')?'Régénérer le jeton':'Générer un jeton').'</button></form><p class="muted small">Une régénération invalide le précédent jeton.</p></section>';
 echo '<section class="panel"><h2>Mot de passe administrateur</h2><form method="post" action="?p=settings">'.csrf().'<input type="hidden" name="action" value="password"><label>Mot de passe actuel</label><input type="password" name="old" required><label>Nouveau mot de passe (14 caractères min.)</label><input type="password" name="password" minlength="14" required><label>Confirmation</label><input type="password" name="confirm" minlength="14" required><p><button>Changer le mot de passe</button></p></form></section></div>';
 echo '<section class="panel" style="margin-top:18px"><h2>Conservation des SMS</h2><form method="post" action="?p=settings">'.csrf().'<input type="hidden" name="action" value="retention"><label>Durée</label><select name="days">';
 foreach(['7'=>'7 jours','30'=>'30 jours','90'=>'90 jours','365'=>'1 an','0'=>'Sans limite'] as $k=>$v)echo '<option value="'.$k.'"'.(opt('retention')===$k?' selected':'').'>'.$v.'</option>';
 echo '</select><p><button>Enregistrer</button></p><p class="muted">Le nettoyage automatique se fait à la réception du prochain SMS.</p></form></section>';
 echo '<section class="panel" style="margin-top:18px"><h2>Diagnostic des transferts</h2><p class="muted">Le journal ne conserve ni le texte des SMS, ni les jetons. Si aucun événement n’apparaît après un nouveau test, l’application envoie probablement sa requête vers une autre URL.</p>';
 echo '<form method="post" action="?p=settings">'.csrf().'<input type="hidden" name="action" value="storage_test"><button class="secondary">Tester la base de données</button></form>';
 $events=db()->query('SELECT created_at,status,reason FROM deliveries ORDER BY id DESC LIMIT 20')->fetchAll();
 if(!$events){echo '<div class="alert">Aucune requête reçue par l’API depuis l’installation de ce journal.</div>';}
 else {
  echo '<table><thead><tr><th>Date UTC</th><th>HTTP</th><th>Résultat</th></tr></thead><tbody>';
  foreach($events as $event)echo '<tr><td>'.esc($event['created_at']).'</td><td>'.esc($event['status']).'</td><td>'.esc($event['reason']).'</td></tr>';
  echo '</tbody></table>';
 }
 echo '<p class="muted small">201 = enregistré, 401 = jeton incorrect/manquant, 422 = JSON incomplet, 503 = jeton non configuré. Actualisez cette page après un nouveau test.</p></section>';bottom();
}
if($page==='detail'){
 $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$q=db()->prepare('SELECT * FROM sms WHERE id=?');$q->execute([$id?:0]);$msg=$q->fetch();
 if(!$msg){http_response_code(404);top('Introuvable');echo '<h1>SMS introuvable</h1>';bottom();}
 top('Détails du SMS');echo '<p><a href="?p=messages">← Retour</a></p><h1>Message de '.esc($msg['sender']).'</h1><p class="muted">'.esc($msg['received_at']).' (UTC)</p><section class="panel"><div class="msg">'.esc($msg['body']).'</div><p class="muted">SIM : '.esc($msg['sim']?:'—').' · Application : '.esc($msg['app']?:'—').' · Source : '.esc($msg['source']?:'—').'</p></section>';bottom();
}
if($page==='export'){
 $q=db()->query('SELECT sender,body,received_at,sim,app,source FROM sms ORDER BY id DESC LIMIT 10000');
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="studioaty-sms-'.gmdate('Y-m-d').'.csv"');
 $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['Expéditeur','Message','Reçu UTC','SIM','Application','Source'],';');
 while($row=$q->fetch(PDO::FETCH_NUM)){foreach($row as &$v){if(preg_match('/^\s*[=+@-]/u',(string)$v))$v="'".$v;}unset($v);fputcsv($f,$row,';');}fclose($f);exit;
}
if($page!=='messages'){http_response_code(404);top('Introuvable');echo '<h1>Page introuvable</h1>';bottom();}
$search=trim(mb_substr((string)($_GET['q']??''),0,100));$pg=max(1,min(10000,(int)($_GET['page']??1)));
$where=$search!==''?' WHERE sender LIKE ? OR body LIKE ?':'';$args=$search!==''?['%'.$search.'%','%'.$search.'%']:[];
$q=db()->prepare('SELECT COUNT(*) FROM sms'.$where);$q->execute($args);$found=(int)$q->fetchColumn();
$q=db()->prepare('SELECT * FROM sms'.$where.' ORDER BY id DESC LIMIT 30 OFFSET '.(($pg-1)*30));$q->execute($args);$items=$q->fetchAll();
$total=(int)db()->query('SELECT COUNT(*) FROM sms')->fetchColumn();
$q=db()->prepare('SELECT COUNT(*) FROM sms WHERE created_at>=?');$q->execute([gmdate('Y-m-d\TH:i:s\Z',strtotime('today UTC'))]);$today=(int)$q->fetchColumn();
$q->execute([gmdate('Y-m-d\TH:i:s\Z',time()-7*86400)]);$week=(int)$q->fetchColumn();
top('Messages');echo '<h1>Messages reçus</h1><p class="muted">Vos SMS transférés automatiquement depuis Android.</p>';
echo '<div class="grid"><div class="stat"><span class="muted small">Total</span><strong>'.$total.'</strong></div><div class="stat"><span class="muted small">Aujourd’hui (UTC)</span><strong>'.$today.'</strong></div><div class="stat"><span class="muted small">7 derniers jours</span><strong>'.$week.'</strong></div></div>';
echo '<section class="panel"><div class="bar"><div><h2>Boîte de réception</h2><span class="muted">'.$found.' résultat(s)</span></div><a class="btn secondary" href="?p=export">Exporter CSV</a></div>';
echo '<form method="get" class="search"><input type="hidden" name="p" value="messages"><input name="q" value="'.esc($search).'" placeholder="Numéro ou contenu du SMS…"><button>Rechercher</button></form>';
if(!$items)echo '<div class="alert">Aucun SMS pour le moment. Ouvrez <a href="?p=settings">Paramètres</a> pour connecter votre téléphone.</div>';
else{echo '<div style="overflow:auto"><table><thead><tr><th>Expéditeur</th><th>Message</th><th>Reçu UTC</th><th>SIM</th></tr></thead><tbody>';
 foreach($items as $item)echo '<tr><td><a href="?p=detail&id='.(int)$item['id'].'"><b>'.esc($item['sender']).'</b></a></td><td><a href="?p=detail&id='.(int)$item['id'].'"><div class="textcut">'.esc($item['body']).'</div></a></td><td>'.esc($item['received_at']).'</td><td><span class="pill">'.esc($item['sim']?:'—').'</span></td></tr>';
 echo '</tbody></table></div>';}
echo '<div class="pagination">';$u='?p=messages&q='.rawurlencode($search).'&page=';
if($pg>1)echo '<a class="btn secondary" href="'.$u.($pg-1).'">← Précédent</a>';else echo '<span></span>';
if($pg*30<$found)echo '<a class="btn secondary" href="'.$u.($pg+1).'">Suivant →</a>';echo '</div></section>';bottom();
