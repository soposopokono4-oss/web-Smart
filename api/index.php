<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $data, int $status=200): never {
  http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
function body(): array {
  $raw=file_get_contents('php://input'); $data=json_decode($raw ?: '{}', true);
  return is_array($data)?$data:[];
}
function db(): PDO {
  static $pdo=null; if($pdo instanceof PDO)return $pdo;
  $configFile=__DIR__.'/config.php';
  if(!file_exists($configFile)) respond(['error'=>'Server belum dikonfigurasi. Salin api/config.example.php menjadi api/config.php.'],503);
  $cfg=require $configFile; $d=$cfg['db'];
  $dsn="mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
  try{$pdo=new PDO($dsn,$d['user'],$d['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
  catch(Throwable $e){respond(['error'=>'Koneksi database gagal. Periksa konfigurasi database.'],500);}
  return $pdo;
}
function userId(): int {
  if(empty($_SESSION['user_id'])) respond(['error'=>'Silakan masuk terlebih dahulu.'],401);
  return (int)$_SESSION['user_id'];
}
function cleanList($items): array {
  if(!is_array($items))return [];
  return array_slice(array_values(array_filter($items,'is_array')),0,500);
}
$action=$_GET['action']??'';
$input=body();
if($action==='register'){
  $name=trim((string)($input['name']??''));$email=strtolower(trim((string)($input['email']??'')));$password=(string)($input['password']??'');
  if(mb_strlen($name)<2||mb_strlen($name)>80||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8)respond(['error'=>'Isi nama, email valid, dan password minimal 8 karakter.'],422);
  $pdo=db();$check=$pdo->prepare('SELECT id FROM users WHERE email=?');$check->execute([$email]);if($check->fetch())respond(['error'=>'Email sudah terdaftar. Silakan masuk.'],409);
  $stmt=$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');$stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
  session_regenerate_id(true);$_SESSION['user_id']=(int)$pdo->lastInsertId();$_SESSION['user_name']=$name;
  respond(['user'=>['name'=>$name,'email'=>$email]]);
}
if($action==='login'){
  $email=strtolower(trim((string)($input['email']??'')));$password=(string)($input['password']??'');
  $stmt=db()->prepare('SELECT id,name,email,password_hash FROM users WHERE email=?');$stmt->execute([$email]);$u=$stmt->fetch();
  if(!$u||!password_verify($password,$u['password_hash']))respond(['error'=>'Email atau password tidak cocok.'],401);
  session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['user_name']=$u['name'];respond(['user'=>['name'=>$u['name'],'email'=>$u['email']]]);
}
if($action==='logout'){$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();respond(['ok'=>true]);}
if($action==='me'){if(empty($_SESSION['user_id']))respond(['user'=>null]);respond(['user'=>['name'=>$_SESSION['user_name']??'Pelajar']]);}
if($action==='data'){
  $uid=userId();$pdo=db();$s=$pdo->prepare('SELECT payload FROM user_data WHERE user_id=? AND data_type=?');$s->execute([$uid,'schedules']);$schedule=json_decode($s->fetchColumn()?:'[]',true);
  $s->execute([$uid,'tasks']);$tasks=json_decode($s->fetchColumn()?:'[]',true);respond(['schedules'=>is_array($schedule)?$schedule:[],'tasks'=>is_array($tasks)?$tasks:[]]);
}
if($action==='saveData'){
  $uid=userId();$pdo=db();$pdo->beginTransaction();
  try{foreach(['schedules','tasks'] as $type){$payload=json_encode(cleanList($input[$type]??[]),JSON_UNESCAPED_UNICODE);$q=$pdo->prepare('INSERT INTO user_data(user_id,data_type,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');$q->execute([$uid,$type,$payload]);}$pdo->commit();}
  catch(Throwable $e){$pdo->rollBack();respond(['error'=>'Gagal menyimpan data.'],500);}
  respond(['ok'=>true]);
}
if($action==='askAI'){
  userId();$question=trim((string)($input['question']??''));if($question===''||mb_strlen($question)>2000)respond(['error'=>'Pertanyaan harus diisi (maksimal 2.000 karakter).'],422);
  $cfg=require __DIR__.'/config.php';$ai=$cfg['ai']??[];$key=$ai['api_key']??'';
  if(!$key||$key==='ISI_API_KEY_DI_SERVER')respond(['error'=>'API AI belum diatur di api/config.php.'],503);
  if(!function_exists('curl_init'))respond(['error'=>'Ekstensi PHP cURL belum aktif di server.'],503);
  $payload=['model'=>$ai['model']??'gpt-4o-mini','messages'=>[
    ['role'=>'system','content'=>'Kamu adalah asisten belajar yang ramah untuk pelajar SMA/SMK. Jelaskan konsep dengan bahasa Indonesia yang jelas, bertahap, akurat, dan berikan contoh sederhana. Jangan mengerjakan ujian aktif untuk siswa.'],
    ['role'=>'user','content'=>$question]
  ],'temperature'=>0.5];
  $ch=curl_init($ai['endpoint']??'https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>35]);
  $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  $result=json_decode((string)$raw,true);$answer=$result['choices'][0]['message']['content']??null;
  if($code<200||$code>=300||!is_string($answer))respond(['error'=>'Layanan AI tidak dapat menjawab saat ini. Periksa endpoint, model, dan API key.'],502);
  respond(['answer'=>$answer]);
}
respond(['error'=>'Aksi tidak ditemukan.'],404);
