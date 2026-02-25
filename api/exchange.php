<?php
// 后端逻辑，用户永远看不到
$ret = [
  "success" => false,
  "msg"     => "手慢了，已经被抢光"
];
echo json_encode($ret, JSON_UNESCAPED_UNICODE);