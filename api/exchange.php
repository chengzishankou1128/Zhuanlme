<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

$dir = 'data/';
if(!is_dir($dir)) mkdir($dir);

$post = json_decode(file_get_contents('php://input'), true);
$uid = $post['uid'] ?? '';
$act = $post['action'] ?? '';

if(!$uid) exit(json_encode(['ok'=>0,'msg'=>'异常']));

$f = $dir . $uid . '.json';
$d = is_file($f) ? json_decode(file_get_contents($f),true) : [
    'gold'=>0, 'power'=>0, 'sign'=>'', 'h1'=>0, 'ex'=>0
];

switch($act){
    case 'load':
        $sec = time() - filemtime($f);
        $d['gold'] += $d['power'] * min($sec, 600);
        break;
    case 'sign':
        $day = date('Y-m-d');
        if($d['sign']==$day) $msg='已签到';
        else { $d['gold']+=100; $d['sign']=$day; $msg='签到+100'; }
        break;
    case 'hire':
        $id = $post['id'];
        if($id==1 && $d['h1']==0){ $d['power']+=1; $d['h1']=1; $msg='领取成功'; }
        else if($id==2 && $d['gold']>=500){ $d['gold']-=500; $d['power']+=2; $msg='购买成功'; }
        else if($id==3 && $d['gold']>=1200){ $d['gold']-=1200; $d['power']+=3; $msg='购买成功'; }
        else if($id==4 && $d['gold']>=2500){ $d['gold']-=2500; $d['power']+=5; $msg='购买成功'; }
        else if($id==5 && $d['gold']>=5000){ $d['gold']-=5000; $d['power']+=8; $msg='购买成功'; }
        else if($id==6 && $d['gold']>=10000){ $d['gold']-=10000; $d['power']+=15; $msg='购买成功'; }
        else $msg='金币不足';
        break;
    case 'buff':
        $t = $post['t'];
        if($t==1 && $d['gold']>=500){ $d['gold']-=500; $msg='加速成功'; }
        else if($t==2 && $d['gold']>=1000){ $d['gold']-=1000; $msg='幸运生效'; }
        else if($t==3 && $d['gold']>=2000){ $d['gold']-=2000; $msg='超级生效'; }
        else $msg='金币不足';
        break;
    case 'exchange':
        if($d['ex']==1){
            $msg='已经兑换过';
        }else if($d['gold']>=10000){
            $d['gold']-=10000;
            $d['ex']=1;
            $msg='手慢了，已经被抢光！';
        }else{
            $msg='金币不足';
        }
        break;
    default:
        $msg='无效';
}

file_put_contents($f, json_encode($d));
echo json_encode([
    'gold'=>$d['gold']??0,
    'power'=>$d['power']??0,
    'msg'=>$msg??''
]);
?>