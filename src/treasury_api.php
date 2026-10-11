<?php
declare(strict_types=1); header('Content-Type: application/json; charset=utf-8');
function out($d,$s=200){http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable $e){out(['error'=>'database_unavailable'],503);}
$headers=getallheaders();$token=$headers['X-Api-Token']??$headers['X-API-TOKEN']??'';if(!$token)out(['error'=>'missing_api_token'],401);
$q=$pdo->prepare('SELECT company_id,user_id FROM company_tokens WHERE token=? AND is_deleted=0 LIMIT 1');$q->execute([$token]);$auth=$q->fetch(PDO::FETCH_ASSOC);if(!$auth)out(['error'=>'invalid_api_token'],401);
$cid=(int)$auth['company_id'];$uid=(int)$auth['user_id'];$path=preg_replace('#^/api/treasury/v1/?#','',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));$method=$_SERVER['REQUEST_METHOD'];$body=json_decode(file_get_contents('php://input'),true)?:[];
if($method==='DELETE'||preg_match('#(^|/)(reverse|reversal|cancel|void)(/|$)#i',$path)){out(['error'=>'cancellation_disabled','message'=>'Cancellation and reversal operations are temporarily disabled'],403);}
if($path===''||$path==='health')out(['ok'=>true,'service'=>'treasury','version'=>'1.0.0']);
if($path==='currencies'&&$method==='GET'){$s=$pdo->prepare('SELECT * FROM treasury_currencies WHERE company_id=? ORDER BY is_base DESC,code');$s->execute([$cid]);out(['data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
if($path==='currencies'&&$method==='POST'){$code=strtoupper(trim($body['code']??''));$name=trim($body['name']??'');$rate=(float)($body['rate_to_base']??0);if(!preg_match('/^[A-Z0-9]{2,10}$/',$code)||$name===''||$rate<=0)out(['error'=>'invalid_currency_data'],422);$pdo->beginTransaction();if(!empty($body['is_base']))$pdo->prepare('UPDATE treasury_currencies SET is_base=0 WHERE company_id=?')->execute([$cid]);$s=$pdo->prepare('INSERT INTO treasury_currencies(company_id,code,name,symbol,precision_digits,is_base,rate_to_base) VALUES(?,?,?,?,?,?,?)');$s->execute([$cid,$code,$name,$body['symbol']??'',(int)($body['precision_digits']??2),!empty($body['is_base'])?1:0,$rate]);$id=$pdo->lastInsertId();$pdo->prepare('INSERT INTO treasury_exchange_rates(company_id,currency_id,rate_to_base,effective_at,created_by) VALUES(?,?,?,NOW(),?)')->execute([$cid,$id,$rate,'user:'.$uid]);$pdo->commit();out(['id'=>$id,'created'=>true],201);}
if($path==='accounts'&&$method==='GET'){$s=$pdo->prepare("SELECT a.*,c.code,c.symbol,COALESCE(a.opening_balance+SUM(CASE WHEN t.transaction_type IN ('deposit','transfer_in','exchange_in','opening') THEN t.amount ELSE -t.amount END),a.opening_balance) balance FROM treasury_accounts a JOIN treasury_currencies c ON c.id=a.currency_id LEFT JOIN treasury_transactions t ON t.account_id=a.id WHERE a.company_id=? GROUP BY a.id ORDER BY a.id");$s->execute([$cid]);out(['data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
if($path==='accounts'&&$method==='POST'){$name=trim($body['name']??'');$cur=(int)($body['currency_id']??0);$type=$body['account_type']??'cash';if($name===''||!in_array($type,['cash','bank','wallet','custom'],true))out(['error'=>'invalid_account_data'],422);$s=$pdo->prepare('SELECT id FROM treasury_currencies WHERE id=? AND company_id=? AND is_active=1');$s->execute([$cur,$cid]);if(!$s->fetch())out(['error'=>'currency_not_found'],404);$s=$pdo->prepare('INSERT INTO treasury_accounts(company_id,currency_id,name,account_type,institution,account_number,opening_balance,allow_negative,notes) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$cid,$cur,$name,$type,$body['institution']??null,$body['account_number']??null,(float)($body['opening_balance']??0),!empty($body['allow_negative'])?1:0,$body['notes']??null]);out(['id'=>$pdo->lastInsertId(),'created'=>true],201);}



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
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','opening') THEN amount ELSE -amount END),0) FROM treasury_transactions WHERE account_id=?");$s->execute([$fromId]);$balance=(float)$from['opening_balance']+(float)$s->fetchColumn();
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
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','opening') THEN amount ELSE -amount END),0) FROM treasury_transactions WHERE account_id=?");$s->execute([$fromId]);$balance=(float)$from['opening_balance']+(float)$s->fetchColumn();
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
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in','exchange_in','opening') THEN amount ELSE -amount END),0) movement FROM treasury_transactions WHERE account_id=?");$s->execute([$accountId]);
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
