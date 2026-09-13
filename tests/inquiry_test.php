<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/inquiry-lib.php';
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
}
$input=['name'=>'Test Owner','company'=>'Test Business','email'=>'test@example.com','phone'=>'','interest'=>'unsure','message'=>'Please help with our IT planning.','website'=>''];
$fields=mmit_inquiry_validate($input);
check($fields['phone']==='', 'valid inquiry without phone or appointment');
foreach (['email'=>['bad'], 'name'=>'', 'interest'=>'invented', 'message'=>'short', 'website'=>'spam', 'company'=>str_repeat('a',151)] as $key=>$value) {
    $bad=$input; $bad[$key]=$value; $rejected=false;
    try { mmit_inquiry_validate($bad); } catch (InvalidArgumentException $e) { $rejected=true; }
    check($rejected, 'reject invalid ' . $key);
}
$dir=sys_get_temp_dir().'/mmit-inquiry-test-'.bin2hex(random_bytes(8));
mkdir($dir,0700);umask(0077);
try {
    $calls=[];
    $transport=static function($endpoint,$payload) use (&$calls) {$calls[]=[$endpoint,$payload]; return 201;};
    check(mmit_inquiry_deliver($dir.'/a.json',$fields,'a','staging',$transport),'accepted contact and event');
    check(count($calls)===2,'one contact and one event');
    check(array_keys($calls[0][1])===['email','updateEnabled'],'no marketing enrollment or unsubscribe reset');
    check($calls[1][1]['event_name']==='mmit_inquiry_staging','isolated staging event');
    check($calls[1][1]['event_properties']['message']===$fields['message'],'full inquiry in event');
    check(mmit_inquiry_deliver($dir.'/a.json',$fields,'a','staging',$transport)&&count($calls)===2,'retry does not resend delivered event');
    $changed=$fields;$changed['message']='Another request with different content';$rejected=false;
    try { mmit_inquiry_deliver($dir.'/a.json',$changed,'a','staging',$transport); } catch (InvalidArgumentException $e) {$rejected=true;}
    check($rejected,'same reference with changed payload blocked');
    check(json_decode(file_get_contents($dir.'/a.json'),true)['fields']===$fields,'private source retained');
    $uncertainCalls=0;
    $uncertain=static function($endpoint,$payload) use (&$uncertainCalls) {$uncertainCalls++;return $endpoint==='contacts'?201:0;};
    check(!mmit_inquiry_deliver($dir.'/b.json',$fields,'b','production',$uncertain),'event timeout is not success');
    check(!mmit_inquiry_deliver($dir.'/b.json',$fields,'b','production',$uncertain)&&$uncertainCalls===2,'uncertain event is not blindly repeated');
    check(!mmit_inquiry_deliver($dir.'/c.json',$fields,'c','staging',static fn()=>503),'contact failure is not success');
    check(mmit_inquiry_deliver($dir.'/c.json',$fields,'c','staging',$transport),'contact failure can safely retry');
    for($i=0;$i<10;$i++) check(mmit_inquiry_rate_limit($dir,'127.0.0.1',1000),'rate allowance '.($i+1));
    check(!mmit_inquiry_rate_limit($dir,'127.0.0.1',1001),'rate cap enforced');
    check(mmit_inquiry_rate_limit($dir,'127.0.0.1',4600),'rate window resets');
} finally {foreach(glob($dir.'/*') as $f) unlink($f);rmdir($dir);}
echo "INQUIRY TESTS PASSED — NO EXTERNAL REQUESTS SENT\n";
