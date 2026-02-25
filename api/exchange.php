<?php
// 核心后端接口：整合所有业务逻辑，解决跨域、权限、数据存储问题
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
error_reporting(E_ALL);
ini_set('display_errors', 0); // 生产环境关闭错误显示

// 1. 初始化数据目录（自动创建，赋予写入权限）
$dataDir = dirname(__FILE__) . '/../data/'; // 数据目录放在根目录，避免api文件夹权限问题
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true); // 赋予最大写入权限，适配各类服务器
}

// 2. 接收并验证请求数据
$rawData = file_get_contents('php://input');
$request = json_decode($rawData, true);

// 必传参数校验
$uid = $request['uid'] ?? '';
$action = $request['action'] ?? '';
if (empty($uid) || empty($action)) {
    exit(json_encode([
        'success' => false,
        'msg' => '请求参数异常',
        'gold' => 0,
        'power' => 0
    ], JSON_UNESCAPED_UNICODE));
}

// 3. 加载用户数据文件
$userFile = $dataDir . $uid . '.json';
$userData = is_file($userFile) ? json_decode(file_get_contents($userFile), true) : [
    'gold' => 0,          // 用户金币
    'power' => 0,         // 算力（矿工总产出）
    'signDate' => '',     // 最后签到日期
    'miners' => [],       // 已拥有的矿工标识
    'exchanged' => false, // 是否已兑换过Key
    'lastUpdate' => time()// 最后数据更新时间
];

// 4. 核心业务逻辑处理
$response = [
    'success' => true,
    'msg' => '操作成功',
    'gold' => $userData['gold'],
    'power' => $userData['power']
];

switch ($action) {
    // 加载用户数据（含离线收益）
    case 'load':
        $now = time();
        $diffSec = $now - $userData['lastUpdate'];
        $maxOfflineSec = 8 * 3600; // 最大离线收益时长：8小时
        $calcSec = min($diffSec, $maxOfflineSec);
        // 计算离线收益：算力 × 秒数 × 0.04（与前端实时产出一致）
        $offlineGold = $userData['power'] * $calcSec * 0.04;
        $userData['gold'] += $offlineGold;
        $userData['lastUpdate'] = $now;
        $response['gold'] = $userData['gold'];
        $response['msg'] = $offlineGold > 0 ? '已领取离线收益' : '数据加载成功';
        break;

    // 每日签到
    case 'sign':
        $today = date('Y-m-d');
        if ($userData['signDate'] === $today) {
            $response['success'] = false;
            $response['msg'] = '今日已签到，无需重复签到';
        } else {
            $userData['gold'] += 100;
            $userData['signDate'] = $today;
            $response['gold'] = $userData['gold'];
            $response['msg'] = '签到成功，获得100金币！';
        }
        break;

    // 雇佣矿工
    case 'hire':
        $minerId = $request['id'] ?? 0;
        $minerRules = [
            1 => ['cost' => 0, 'power' => 1, 'flag' => 'novice', 'tip' => '新手矿工已领取'],
            2 => ['cost' => 500, 'power' => 2, 'flag' => 'skilled', 'tip' => '金币不足，无法雇佣熟练矿工'],
            3 => ['cost' => 1200, 'power' => 3, 'flag' => 'elite', 'tip' => '金币不足，无法雇佣精英矿工'],
            4 => ['cost' => 2500, 'power' => 5, 'flag' => 'expert', 'tip' => '金币不足，无法雇佣专家矿工'],
            5 => ['cost' => 5000, 'power' => 8, 'flag' => 'master', 'tip' => '金币不足，无法雇佣大师矿工'],
            6 => ['cost' => 10000, 'power' => 15, 'flag' => 'legend', 'tip' => '金币不足，无法雇佣传说矿工']
        ];

        if (!isset($minerRules[$minerId])) {
            $response['success'] = false;
            $response['msg'] = '无效的矿工类型';
            break;
        }

        $rule = $minerRules[$minerId];
        // 免费矿工校验
        if ($minerId === 1 && in_array($rule['flag'], $userData['miners'])) {
            $response['success'] = false;
            $response['msg'] = '你已经领取过新手矿工啦！';
            break;
        }
        // 付费矿工校验
        if ($minerId > 1 && $userData['gold'] < $rule['cost']) {
            $response['success'] = false;
            $response['msg'] = $rule['tip'];
            break;
        }

        // 执行雇佣逻辑
        $userData['gold'] -= $rule['cost'];
        $userData['power'] += $rule['power'];
        $userData['miners'][] = $rule['flag'];
        $userData['miners'] = array_unique($userData['miners']); // 去重
        $response['gold'] = $userData['gold'];
        $response['power'] = $userData['power'];
        $response['msg'] = $minerId === 1 ? '新手矿工领取成功！' : '矿工雇佣成功！';
        break;

    // 购买道具
    case 'buff':
        $buffType = $request['t'] ?? 0;
        $buffRules = [
            1 => ['cost' => 500, 'tip' => '加速1小时生效！'],
            2 => ['cost' => 1000, 'tip' => '幸运光环生效！'],
            3 => ['cost' => 2000, 'tip' => '超级矿场生效！']
        ];

        if (!isset($buffRules[$buffType])) {
            $response['success'] = false;
            $response['msg'] = '无效的道具类型';
            break;
        }

        $rule = $buffRules[$buffType];
        if ($userData['gold'] < $rule['cost']) {
            $response['success'] = false;
            $response['msg'] = '金币不足，无法购买该道具';
            break;
        }

        $userData['gold'] -= $rule['cost'];
        $response['gold'] = $userData['gold'];
        $response['msg'] = $rule['tip'];
        break;

    // 千问Key兑换（核心防刷逻辑）
    case 'exchange':
        if ($userData['exchanged']) {
            $response['success'] = false;
            $response['msg'] = '你已经尝试过兑换，无法重复操作';
            break;
        }
        if ($userData['gold'] < 10000) {
            $response['success'] = false;
            $response['msg'] = '金币不足，需要10000金币才能兑换';
            break;
        }

        // 执行扣金币+标记已兑换
        $userData['gold'] -= 10000;
        $userData['exchanged'] = true;
        $response['gold'] = $userData['gold'];
        $response['msg'] = '手慢了，已经被抢光！';
        break;

    // 未知操作
    default:
        $response['success'] = false;
        $response['msg'] = '无效的操作指令';
        break;
}

// 5. 保存用户数据到文件
file_put_contents($userFile, json_encode($userData, JSON_UNESCAPED_UNICODE));

// 6. 返回响应结果
exit(json_encode($response, JSON_UNESCAPED_UNICODE));
?>