<?php
declare(strict_types=1); header('Content-Type: application/json; charset=utf-8');
function out($d,$s=200){http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable $e){out(['error'=>'database_unavailable'],503);}
$headers=getallheaders();$token=$headers['X-Api-Token']??$headers['X-API-TOKEN']??'';if(!$token)out(['error'=>'missing_api_token'],401);
$q=$pdo->prepare('SELECT company_id,user_id FROM company_tokens WHERE token=? AND is_deleted=0 LIMIT 1');$q->execute([$token]);$auth=$q->fetch(PDO::FETCH_ASSOC);if(!$auth)out(['error'=>'invalid_api_token'],401);
$cid=(int)$auth['company_id'];$uid=(int)$auth['user_id'];
$permQ=$pdo->prepare('SELECT cu.is_admin,cu.is_owner,cu.is_locked,COALESCE(tp.can_view,0) can_view,COALESCE(tp.can_transact,0) can_transact,COALESCE(tp.can_manage,0) can_manage FROM company_user cu LEFT JOIN treasury_user_permissions tp ON tp.company_id=cu.company_id AND tp.user_id=cu.user_id WHERE cu.company_id=? AND cu.user_id=? AND cu.deleted_at IS NULL LIMIT 1');$permQ->execute([$cid,$uid]);$perm=$permQ->fetch(PDO::FETCH_ASSOC);if(!$perm||$perm['is_locked'])out(['error'=>'user_access_denied'],403);$isAdmin=(bool)($perm['is_admin']||$perm['is_owner']);$canView=$isAdmin||(bool)$perm['can_view'];$canTransact=$isAdmin||(bool)$perm['can_transact'];$canManage=$isAdmin||(bool)$perm['can_manage'];
$path=preg_replace('#^/api/treasury/v1/?#','',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));$method=$_SERVER['REQUEST_METHOD'];$body=json_decode(file_get_contents('php://input'),true)?:[];
if($method==='DELETE')out(['error'=>'deletion_disabled','message'=>'Deletion is permanently disabled for financial records'],403);
$settingQ=$pdo->prepare("SELECT setting_value FROM treasury_settings WHERE company_id=? AND setting_key='cancellation_enabled' LIMIT 1");$settingQ->execute([$cid]);$reversalEnabled=$settingQ->fetchColumn()==='1';
if(preg_match('#(^|/)(cancel|void)(/|$)#i',$path))out(['error'=>'cancellation_disabled'],403);
if(preg_match('#(^|/)(reverse|reversal)(/|$)#i',$path)&&!$reversalEnabled)out(['error'=>'reversal_disabled','message'=>'Reversal operations are disabled in settings'],403);
if($method==='GET'&&$path!=='health'&&!$canView)out(['error'=>'view_permission_required'],403);
if($method==='POST'&&in_array($path,['transactions','transfers','exchanges','fees'],true)&&!$canTransact)out(['error'=>'transaction_permission_required'],403);
if($method==='POST'&&in_array($path,['currencies','accounts'],true)&&!$canManage)out(['error'=>'manage_permission_required'],403);
if($path===''||$path==='health')out(['ok'=>true,'service'=>'treasury','version'=>'1.0.0']);

if($path==='permissions/me'&&$method==='GET')out(['data'=>['user_id'=>$uid,'company_id'=>$cid,'can_view'=>$canView,'can_transact'=>$canTransact,'can_manage'=>$canManage,'is_admin'=>$isAdmin]]);
if($path==='audit-logs'&&$method==='GET'){
  if(!$canManage)out(['error'=>'manage_permission_required'],403);$limit=max(1,min(100,(int)($_GET['limit']??50)));$offset=max(0,(int)($_GET['offset']??0));$q=$pdo->prepare('SELECT * FROM treasury_audit_logs WHERE company_id=? ORDER BY created_at DESC,id DESC LIMIT '.$limit.' OFFSET '.$offset);$q->execute([$cid]);out(['data'=>$q->fetchAll(PDO::FETCH_ASSOC),'meta'=>['limit'=>$limit,'offset'=>$offset]]);
}
if(preg_match('#^permissions/(\d+)$#',$path,$m)&&$method==='POST'){
  if(!$canManage)out(['error'=>'manage_permission_required'],403);$target=(int)$m[1];$q=$pdo->prepare('SELECT 1 FROM company_user WHERE company_id=? AND user_id=? AND deleted_at IS NULL');$q->execute([$cid,$target]);if(!$q->fetch())out(['error'=>'user_not_found'],404);
  $v=[!empty($body['can_view'])?1:0,!empty($body['can_transact'])?1:0,!empty($body['can_manage'])?1:0];$q=$pdo->prepare('INSERT INTO treasury_user_permissions(company_id,user_id,can_view,can_transact,can_manage,updated_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_transact=VALUES(can_transact),can_manage=VALUES(can_manage),updated_by=VALUES(updated_by)');$q->execute([$cid,$target,$v[0],$v[1],$v[2],$uid]);$pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'permission.updated','treasury_user_permission',$target,json_encode(['can_view'=>$v[0],'can_transact'=>$v[1],'can_manage'=>$v[2]]),$_SERVER['REMOTE_ADDR']??null]);out(['updated'=>true,'user_id'=>$target,'can_view'=>$v[0],'can_transact'=>$v[1],'can_manage'=>$v[2]]);
}

if($path==='currencies'&&$method==='GET'){$s=$pdo->prepare('SELECT * FROM treasury_currencies WHERE company_id=? ORDER BY is_base DESC,code');$s->execute([$cid]);out(['data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
if($path==='currencies'&&$method==='POST'){$code=strtoupper(trim($body['code']??''));$name=trim($body['name']??'');$rate=(float)($body['rate_to_base']??0);if(!preg_match('/^[A-Z0-9]{2,10}$/',$code)||$name===''||$rate<=0)out(['error'=>'invalid_currency_data'],422);$pdo->beginTransaction();if(!empty($body['is_base']))$pdo->prepare('UPDATE treasury_currencies SET is_base=0 WHERE company_id=?')->execute([$cid]);$s=$pdo->prepare('INSERT INTO treasury_currencies(company_id,code,name,symbol,precision_digits,is_base,rate_to_base) VALUES(?,?,?,?,?,?,?)');$s->execute([$cid,$code,$name,$body['symbol']??'',(int)($body['precision_digits']??2),!empty($body['is_base'])?1:0,$rate]);$id=$pdo->lastInsertId();$pdo->prepare('INSERT INTO treasury_exchange_rates(company_id,currency_id,rate_to_base,effective_at,created_by) VALUES(?,?,?,NOW(),?)')->execute([$cid,$id,$rate,'user:'.$uid]);$pdo->commit();out(['id'=>$id,'created'=>true],201);}
if($path==='accounts'&&$method==='GET'){$s=$pdo->prepare("SELECT a.*,c.code,c.symbol,COALESCE(a.opening_balance+SUM(CASE WHEN t.transaction_type IN ('deposit','transfer_in','exchange_in','fee_in','reversal_in','opening') THEN t.amount ELSE -t.amount END),a.opening_balance) balance FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id LEFT JOIN treasury_transactions t ON t.account_id=a.id WHERE a.company_id=? GROUP BY a.id ORDER BY a.id");$s->execute([$cid]);out(['data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
if($path==='accounts'&&$method==='POST'){$name=trim($body['name']??'');$cur=(int)($body['currency_id']??0);$type=$body['account_type']??'cash';if($name===''||!in_array($type,['cash','bank','wallet','custom'],true))out(['error'=>'invalid_account_data'],422);$s=$pdo->prepare('SELECT id FROM treasury_currencies WHERE id=? AND company_id=? AND is_active=1');$s->execute([$cur,$cid]);if(!$s->fetch())out(['error'=>'currency_not_found'],404);$s=$pdo->prepare('INSERT INTO treasury_accounts(company_id,currency_id,name,account_type,institution,account_number,opening_balance,allow_negative,notes) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$cid,$cur,$name,$type,$body['institution']??null,$body['account_number']??null,(float)($body['opening_balance']??0),!empty($body['allow_negative'])?1:0,$body['notes']??null]);out(['id'=>$pdo->lastInsertId(),'created'=>true],201);}






if($path==='settings'&&$method==='GET'){
  $q=$pdo->prepare('SELECT setting_key,setting_value FROM treasury_settings WHERE company_id=?');$q->execute([$cid]);$data=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$data[$r['setting_key']]=$r['setting_value'];out(['data'=>$data]);
}
if($path==='settings/reversal'&&$method==='POST'){
  if(!$canManage)out(['error'=>'manage_permission_required'],403);$enabled=!empty($body['enabled'])?'1':'0';$q=$pdo->prepare("INSERT INTO treasury_settings(company_id,setting_key,setting_value,updated_by) VALUES(?,'cancellation_enabled',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");$q->execute([$cid,$enabled,'user:'.$uid]);$pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'setting.reversal.updated','treasury_setting','cancellation_enabled',json_encode(['enabled'=>$enabled==='1']),$_SERVER['REMOTE_ADDR']??null]);out(['updated'=>true,'reversal_enabled'=>$enabled==='1']);
}
if(preg_match('#^transactions/(\\d+)/reverse$#',$path,$m)&&$method==='POST'){
  if(!$canManage)out(['error'=>'manage_permission_required'],403);$reason=trim($body['reason']??'');if($reason==='')out(['error'=>'reversal_reason_required'],422);$originalId=(int)$m[1];
  try{$pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM treasury_transactions WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$originalId,$cid]);$first=$q->fetch(PDO::FETCH_ASSOC);if(!$first){$pdo->rollBack();out(['error'=>'transaction_not_found'],404);}if(str_starts_with($first['transaction_type'],'reversal_')){$pdo->rollBack();out(['error'=>'cannot_reverse_reversal'],409);}
    if($first['transfer_group']){$q=$pdo->prepare('SELECT * FROM treasury_transactions WHERE company_id=? AND transfer_group=? ORDER BY id FOR UPDATE');$q->execute([$cid,$first['transfer_group']]);$originals=$q->fetchAll(PDO::FETCH_ASSOC);}else{$originals=[$first];}
    $ids=array_column($originals,'id');$marks=str_repeat('?,',count($ids)-1).'?';$q=$pdo->prepare("SELECT reversed_transaction_id FROM treasury_transactions WHERE company_id=? AND reversed_transaction_id IN ($marks) LIMIT 1");$q->execute(array_merge([$cid],$ids));if($q->fetch()){$pdo->rollBack();out(['error'=>'transaction_already_reversed'],409);}
    $incoming=['deposit','transfer_in','exchange_in','fee_in','opening'];$group=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));$created=[];
    $ins=$pdo->prepare('INSERT INTO treasury_transactions(company_id,account_id,transaction_type,amount,currency_id,rate_to_base,base_amount,transfer_group,description,occurred_at,created_by,reversed_transaction_id) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?,?)');
    foreach($originals as $o){$rtype=in_array($o['transaction_type'],$incoming,true)?'reversal_out':'reversal_in';$ins->execute([$cid,$o['account_id'],$rtype,$o['amount'],$o['currency_id'],$o['rate_to_base'],$o['base_amount'],$group,'عكس الحركة: '.$reason,'user:'.$uid,$o['id']]);$created[]=(int)$pdo->lastInsertId();}
    $pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'transaction.reversed','treasury_transaction',$originalId,json_encode(['reason'=>$reason,'reversal_ids'=>$created],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);$pdo->commit();out(['reversed'=>true,'original_transaction_id'=>$originalId,'reversal_transaction_ids'=>$created,'reversal_group'=>$group],201);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'reversal_failed','message'=>$e->getMessage()],500);}
}

if($path==='transactions'&&$method==='GET'){
  $where=['t.company_id=?'];$params=[$cid];
  if(isset($_GET['account_id'])&&(int)$_GET['account_id']>0){$where[]='t.account_id=?';$params[]=(int)$_GET['account_id'];}
  if(!empty($_GET['type'])){$where[]='t.transaction_type=?';$params[]=$_GET['type'];}
  if(!empty($_GET['from'])){$where[]='t.occurred_at>=?';$params[]=$_GET['from'].' 00:00:00';}
  if(!empty($_GET['to'])){$where[]='t.occurred_at<=?';$params[]=$_GET['to'].' 23:59:59';}
  $limit=max(1,min(100,(int)($_GET['limit']??50)));$offset=max(0,(int)($_GET['offset']??0));
  $sql='SELECT t.*,a.name account_name,c.code currency_code,c.symbol currency_symbol FROM treasury_transactions t JOIN treasury_accounts a ON a.id=t.account_id JOIN treasury_currencies c ON c.id=t.currency_id WHERE '.implode(' AND ',$where).' ORDER BY t.occurred_at DESC,t.id DESC LIMIT '.$limit.' OFFSET '.$offset;
  $q=$pdo->prepare($sql);$q->execute($params);$data=$q->fetchAll(PDO::FETCH_ASSOC);
  $count=$pdo->prepare('SELECT COUNT(*) FROM treasury_transactions t WHERE '.implode(' AND ',$where));$count->execute($params);
  out(['data'=>$data,'meta'=>['total'=>(int)$count->fetchColumn(),'limit'=>$limit,'offset'=>$offset]]);
}
if($path==='reports/balances'&&$method==='GET'){
  $q=$pdo->prepare("SELECT a.id,a.name,a.account_type,a.institution,a.allow_negative,c.id currency_id,c.code,c.name currency_name,c.symbol,c.rate_to_base,a.opening_balance,COALESCE(a.opening_balance+SUM(CASE WHEN t.transaction_type IN ('deposit','transfer_in','exchange_in','fee_in','reversal_in','opening') THEN t.amount ELSE -t.amount END),a.opening_balance) balance FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id LEFT JOIN treasury_transactions t ON t.account_id=a.id WHERE a.company_id=? AND a.is_active=1 GROUP BY a.id,c.id ORDER BY c.is_base DESC,c.code,a.id");$q->execute([$cid]);$accounts=$q->fetchAll(PDO::FETCH_ASSOC);
  $currencies=[];$grand=0.0;foreach($accounts as &$a){$a['balance']=(float)$a['balance'];$a['base_balance']=round($a['balance']*(float)$a['rate_to_base'],4);$grand+=$a['base_balance'];$code=$a['code'];if(!isset($currencies[$code]))$currencies[$code]=['code'=>$code,'symbol'=>$a['symbol'],'balance'=>0.0,'base_balance'=>0.0];$currencies[$code]['balance']+=$a['balance'];$currencies[$code]['base_balance']+=$a['base_balance'];}unset($a);
  out(['data'=>['accounts'=>$accounts,'currencies'=>array_values($currencies),'grand_total_base'=>round($grand,4),'base_currency'=>($pdo->query("SELECT code FROM treasury_currencies WHERE company_id=".(int)$cid." AND is_base=1 LIMIT 1")->fetchColumn()?:null),'generated_at'=>date(DATE_ATOM)]]);
}

if($path==='fees'&&$method==='POST'){
  $fromId=(int)($body['from_account_id']??0);$feeAccountId=(int)($body['fee_account_id']??0);$amount=(float)($body['amount']??0);
  $idem=trim($headers['Idempotency-Key']??$headers['IDEMPOTENCY-KEY']??'');
  if($fromId<=0||$feeAccountId<=0||$fromId===$feeAccountId||$amount<=0)out(['error'=>'invalid_fee_data'],422);
  if($idem===''||strlen($idem)>191)out(['error'=>'idempotency_key_required'],422);
  $requestHash=hash('sha256',json_encode([$fromId,$feeAccountId,number_format($amount,4,'.',''),$body['description']??'']));
  try{
    $pdo->beginTransaction();
    try{$s=$pdo->prepare('INSERT INTO treasury_idempotency(company_id,idempotency_key,request_hash) VALUES(?,?,?)');$s->execute([$cid,$idem,$requestHash]);}
    catch(PDOException $e){
      if($e->getCode()!=='23000')throw $e;$s=$pdo->prepare('SELECT request_hash,response_code,response_body FROM treasury_idempotency WHERE company_id=? AND idempotency_key=? FOR UPDATE');$s->execute([$cid,$idem]);$old=$s->fetch(PDO::FETCH_ASSOC);
      if(!$old||$old['request_hash']!==$requestHash){$pdo->rollBack();out(['error'=>'idempotency_key_conflict'],409);}
      if($old['response_body']){$saved=json_decode($old['response_body'],true);$pdo->commit();out($saved,(int)$old['response_code']);}throw new RuntimeException('idempotency_request_in_progress');
    }
    $ids=[$fromId,$feeAccountId];sort($ids);$s=$pdo->prepare('SELECT a.*,c.code,c.rate_to_base FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id WHERE a.company_id=? AND a.is_active=1 AND a.id IN (?,?) ORDER BY a.id FOR UPDATE');$s->execute([$cid,$ids[0],$ids[1]]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==2){$pdo->rollBack();out(['error'=>'account_not_found'],404);}$by=[];foreach($rows as $r)$by[(int)$r['id']]=$r;$from=$by[$fromId];$fee=$by[$feeAccountId];
    if((int)$from['currency_id']!==(int)$fee['currency_id']){$pdo->rollBack();out(['error'=>'fee_currency_mismatch'],422);}
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','fee_in','reversal_in','opening') THEN amount ELSE -amount END),0) FROM treasury_transactions WHERE account_id=?");$s->execute([$fromId]);$balance=(float)$from['opening_balance']+(float)$s->fetchColumn();
    if(!$from['allow_negative']&&$amount>$balance){$pdo->rollBack();out(['error'=>'insufficient_balance','available_balance'=>$balance],409);}
    $group=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));$rate=(float)$from['rate_to_base'];$baseAmount=round($amount*$rate,4);$occurred=$body['occurred_at']??date('Y-m-d H:i:s');$desc=$body['description']??'رسوم/عمولة';
    $ins=$pdo->prepare('INSERT INTO treasury_transactions(company_id,account_id,transaction_type,amount,currency_id,rate_to_base,base_amount,transfer_group,description,occurred_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$cid,$fromId,'fee_out',$amount,$from['currency_id'],$rate,$baseAmount,$group,$desc,$occurred,'user:'.$uid]);$outId=$pdo->lastInsertId();$ins->execute([$cid,$feeAccountId,'fee_in',$amount,$fee['currency_id'],$rate,$baseAmount,$group,$desc,$occurred,'user:'.$uid]);$inId=$pdo->lastInsertId();
    $response=['fee_group'=>$group,'out_transaction_id'=>$outId,'in_transaction_id'=>$inId,'amount'=>$amount,'currency'=>$from['code'],'source_balance'=>$balance-$amount,'created'=>true];
    $pdo->prepare('UPDATE treasury_idempotency SET response_code=201,response_body=? WHERE company_id=? AND idempotency_key=?')->execute([json_encode($response,JSON_UNESCAPED_UNICODE),$cid,$idem]);$pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'fee.created','treasury_fee',$group,json_encode($response,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);$pdo->commit();out($response,201);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'fee_failed','message'=>$e->getMessage()],500);}
}

if($path==='exchanges'&&$method==='POST'){
  $fromId=(int)($body['from_account_id']??0);$toId=(int)($body['to_account_id']??0);$fromAmount=(float)($body['amount']??0);
  $idem=trim($headers['Idempotency-Key']??$headers['IDEMPOTENCY-KEY']??'');
  if($fromId<=0||$toId<=0||$fromId===$toId||$fromAmount<=0)out(['error'=>'invalid_exchange_data'],422);
  if($idem===''||strlen($idem)>191)out(['error'=>'idempotency_key_required'],422);
  $customRate=isset($body['base_amount_per_source_unit'])?(float)$body['base_amount_per_source_unit']:null;
  if($customRate!==null&&$customRate<=0)out(['error'=>'invalid_exchange_rate'],422);
  $requestHash=hash('sha256',json_encode([$fromId,$toId,number_format($fromAmount,4,'.',''),$customRate,$body['description']??'']));
  try{
    $pdo->beginTransaction();
    try{$s=$pdo->prepare('INSERT INTO treasury_idempotency(company_id,idempotency_key,request_hash) VALUES(?,?,?)');$s->execute([$cid,$idem,$requestHash]);}
    catch(PDOException $e){
      if($e->getCode()!=='23000')throw $e;
      $s=$pdo->prepare('SELECT request_hash,response_code,response_body FROM treasury_idempotency WHERE company_id=? AND idempotency_key=? FOR UPDATE');$s->execute([$cid,$idem]);$old=$s->fetch(PDO::FETCH_ASSOC);
      if(!$old||$old['request_hash']!==$requestHash){$pdo->rollBack();out(['error'=>'idempotency_key_conflict'],409);}
      if($old['response_body']){$saved=json_decode($old['response_body'],true);$pdo->commit();out($saved,(int)$old['response_code']);}
      throw new RuntimeException('idempotency_request_in_progress');
    }
    $ids=[$fromId,$toId];sort($ids);$s=$pdo->prepare('SELECT a.*,c.code,c.rate_to_base,c.precision_digits FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id WHERE a.company_id=? AND a.is_active=1 AND a.id IN (?,?) ORDER BY a.id FOR UPDATE');$s->execute([$cid,$ids[0],$ids[1]]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==2){$pdo->rollBack();out(['error'=>'account_not_found'],404);}$by=[];foreach($rows as $r)$by[(int)$r['id']]=$r;$from=$by[$fromId];$to=$by[$toId];
    if((int)$from['currency_id']===(int)$to['currency_id']){$pdo->rollBack();out(['error'=>'same_currency','message'=>'Use the transfers endpoint for the same currency'],422);}
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','fee_in','reversal_in','opening') THEN amount ELSE -amount END),0) FROM treasury_transactions WHERE account_id=?");$s->execute([$fromId]);$balance=(float)$from['opening_balance']+(float)$s->fetchColumn();
    if(!$from['allow_negative']&&$fromAmount>$balance){$pdo->rollBack();out(['error'=>'insufficient_balance','available_balance'=>$balance],409);}
    $fromRate=$customRate??(float)$from['rate_to_base'];$toRate=(float)$to['rate_to_base'];$baseAmount=round($fromAmount*$fromRate,4);$toAmount=round($baseAmount/$toRate,(int)$to['precision_digits']);
    if($toAmount<=0){$pdo->rollBack();out(['error'=>'exchange_amount_too_small'],422);}
    $group=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));$occurred=$body['occurred_at']??date('Y-m-d H:i:s');$desc=$body['description']??null;
    $ins=$pdo->prepare('INSERT INTO treasury_transactions(company_id,account_id,transaction_type,amount,currency_id,rate_to_base,base_amount,transfer_group,description,occurred_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$cid,$fromId,'exchange_out',$fromAmount,$from['currency_id'],$fromRate,$baseAmount,$group,$desc,$occurred,'user:'.$uid]);$outId=$pdo->lastInsertId();
    $ins->execute([$cid,$toId,'exchange_in',$toAmount,$to['currency_id'],$toRate,$baseAmount,$group,$desc,$occurred,'user:'.$uid]);$inId=$pdo->lastInsertId();
    $response=['exchange_group'=>$group,'out_transaction_id'=>$outId,'in_transaction_id'=>$inId,'source_amount'=>$fromAmount,'source_currency'=>$from['code'],'target_amount'=>$toAmount,'target_currency'=>$to['code'],'base_amount'=>$baseAmount,'source_rate_to_base'=>$fromRate,'target_rate_to_base'=>$toRate,'source_balance'=>$balance-$fromAmount,'created'=>true];
    $pdo->prepare('UPDATE treasury_idempotency SET response_code=201,response_body=? WHERE company_id=? AND idempotency_key=?')->execute([json_encode($response,JSON_UNESCAPED_UNICODE),$cid,$idem]);
    $pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'exchange.created','treasury_exchange',$group,json_encode($response,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
    $pdo->commit();out($response,201);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'exchange_failed','message'=>$e->getMessage()],500);}
}

if($path==='transfers'&&$method==='POST'){
  $fromId=(int)($body['from_account_id']??0);$toId=(int)($body['to_account_id']??0);$amount=(float)($body['amount']??0);
  $idem=trim($headers['Idempotency-Key']??$headers['IDEMPOTENCY-KEY']??'');
  if($fromId<=0||$toId<=0||$fromId===$toId||$amount<=0)out(['error'=>'invalid_transfer_data'],422);
  if($idem===''||strlen($idem)>191)out(['error'=>'idempotency_key_required'],422);
  $requestHash=hash('sha256',json_encode([$fromId,$toId,number_format($amount,4,'.',''),$body['description']??'']));
  try{
    $pdo->beginTransaction();
    try{$s=$pdo->prepare('INSERT INTO treasury_idempotency(company_id,idempotency_key,request_hash) VALUES(?,?,?)');$s->execute([$cid,$idem,$requestHash]);}
    catch(PDOException $e){
      if($e->getCode()!=='23000')throw $e;
      $s=$pdo->prepare('SELECT request_hash,response_code,response_body FROM treasury_idempotency WHERE company_id=? AND idempotency_key=? FOR UPDATE');$s->execute([$cid,$idem]);$old=$s->fetch(PDO::FETCH_ASSOC);
      if(!$old||$old['request_hash']!==$requestHash){$pdo->rollBack();out(['error'=>'idempotency_key_conflict'],409);}
      if($old['response_body']){$saved=json_decode($old['response_body'],true);$pdo->commit();out($saved,(int)$old['response_code']);}
      throw new RuntimeException('idempotency_request_in_progress');
    }
    $ids=[$fromId,$toId];sort($ids);
    $s=$pdo->prepare('SELECT a.*,c.code,c.rate_to_base FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id WHERE a.company_id=? AND a.is_active=1 AND a.id IN (?,?) ORDER BY a.id FOR UPDATE');$s->execute([$cid,$ids[0],$ids[1]]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==2){$pdo->rollBack();out(['error'=>'account_not_found'],404);}
    $byId=[];foreach($rows as $r)$byId[(int)$r['id']]=$r;$from=$byId[$fromId];$to=$byId[$toId];
    if((int)$from['currency_id']!==(int)$to['currency_id']){$pdo->rollBack();out(['error'=>'currency_mismatch','message'=>'Use the exchanges endpoint for different currencies'],422);}
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','fee_in','reversal_in','opening') THEN amount ELSE -amount END),0) FROM treasury_transactions WHERE account_id=?");$s->execute([$fromId]);$balance=(float)$from['opening_balance']+(float)$s->fetchColumn();
    if(!$from['allow_negative']&&$amount>$balance){$pdo->rollBack();out(['error'=>'insufficient_balance','available_balance'=>$balance],409);}
    $group=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
    $rate=(float)$from['rate_to_base'];$baseAmount=round($amount*$rate,4);$occurred=$body['occurred_at']??date('Y-m-d H:i:s');$desc=$body['description']??null;
    $ins=$pdo->prepare('INSERT INTO treasury_transactions(company_id,account_id,transaction_type,amount,currency_id,rate_to_base,base_amount,transfer_group,description,occurred_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$cid,$fromId,'transfer_out',$amount,$from['currency_id'],$rate,$baseAmount,$group,$desc,$occurred,'user:'.$uid]);$outId=$pdo->lastInsertId();
    $ins->execute([$cid,$toId,'transfer_in',$amount,$to['currency_id'],$rate,$baseAmount,$group,$desc,$occurred,'user:'.$uid]);$inId=$pdo->lastInsertId();
    $response=['transfer_group'=>$group,'out_transaction_id'=>$outId,'in_transaction_id'=>$inId,'amount'=>$amount,'currency'=>$from['code'],'source_balance'=>$balance-$amount,'created'=>true];
    $pdo->prepare('UPDATE treasury_idempotency SET response_code=201,response_body=? WHERE company_id=? AND idempotency_key=?')->execute([json_encode($response,JSON_UNESCAPED_UNICODE),$cid,$idem]);
    $pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'transfer.created','treasury_transfer',$group,json_encode(['from_account_id'=>$fromId,'to_account_id'=>$toId,'amount'=>$amount],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
    $pdo->commit();out($response,201);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'transfer_failed','message'=>$e->getMessage()],500);}
}

if($path==='transactions'&&$method==='POST'){
  $type=$body['type']??'';$accountId=(int)($body['account_id']??0);$amount=(float)($body['amount']??0);
  $idem=trim($headers['Idempotency-Key']??$headers['IDEMPOTENCY-KEY']??'');
  if(!in_array($type,['deposit','withdrawal'],true)||$accountId<=0||$amount<=0)out(['error'=>'invalid_transaction_data'],422);
  if($idem===''||strlen($idem)>191)out(['error'=>'idempotency_key_required'],422);
  $requestHash=hash('sha256',json_encode([$type,$accountId,number_format($amount,4,'.',''),$body['description']??'']));
  try{
    $pdo->beginTransaction();
    try{$s=$pdo->prepare('INSERT INTO treasury_idempotency(company_id,idempotency_key,request_hash) VALUES(?,?,?)');$s->execute([$cid,$idem,$requestHash]);}
    catch(PDOException $e){
      if($e->getCode()!=='23000')throw $e;
      $s=$pdo->prepare('SELECT request_hash,response_code,response_body FROM treasury_idempotency WHERE company_id=? AND idempotency_key=? FOR UPDATE');$s->execute([$cid,$idem]);$old=$s->fetch(PDO::FETCH_ASSOC);
      if(!$old||$old['request_hash']!==$requestHash){$pdo->rollBack();out(['error'=>'idempotency_key_conflict'],409);}
      if($old['response_body']){$saved=json_decode($old['response_body'],true);$pdo->commit();out($saved,(int)$old['response_code']);}
      throw new RuntimeException('idempotency_request_in_progress');
    }
    $s=$pdo->prepare('SELECT a.*,c.rate_to_base,c.code FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id WHERE a.id=? AND a.company_id=? AND a.is_active=1 FOR UPDATE');$s->execute([$accountId,$cid]);$account=$s->fetch(PDO::FETCH_ASSOC);
    if(!$account){$pdo->rollBack();out(['error'=>'account_not_found'],404);}
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','fee_in','reversal_in','opening') THEN amount ELSE -amount END),0) movement FROM treasury_transactions WHERE account_id=?");$s->execute([$accountId]);
    $balance=(float)$account['opening_balance']+(float)$s->fetchColumn();
    if($type==='withdrawal'&&!$account['allow_negative']&&$amount>$balance){$pdo->rollBack();out(['error'=>'insufficient_balance','available_balance'=>$balance],409);}
    $rate=(float)$account['rate_to_base'];$baseAmount=round($amount*$rate,4);$occurred=$body['occurred_at']??date('Y-m-d H:i:s');
    $s=$pdo->prepare('INSERT INTO treasury_transactions(company_id,account_id,transaction_type,amount,currency_id,rate_to_base,base_amount,reference_type,reference_id,description,occurred_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$cid,$accountId,$type,$amount,$account['currency_id'],$rate,$baseAmount,$body['reference_type']??null,$body['reference_id']??null,$body['description']??null,$occurred,'user:'.$uid]);$txId=$pdo->lastInsertId();
    $newBalance=$type==='deposit'?$balance+$amount:$balance-$amount;
    $response=['id'=>$txId,'created'=>true,'type'=>$type,'amount'=>$amount,'currency'=>$account['code'],'balance'=>$newBalance];
    $pdo->prepare('UPDATE treasury_idempotency SET response_code=201,response_body=? WHERE company_id=? AND idempotency_key=?')->execute([json_encode($response,JSON_UNESCAPED_UNICODE),$cid,$idem]);
    $pdo->prepare('INSERT INTO treasury_audit_logs(company_id,user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$uid,'transaction.created','treasury_transaction',$txId,json_encode(['type'=>$type,'account_id'=>$accountId,'amount'=>$amount],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
    $pdo->commit();out($response,201);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'transaction_failed','message'=>$e->getMessage()],500);}
}

out(['error'=>'route_not_found'],404);
