<?php
declare(strict_types=1);

/**
 * 简单的文件级频率限制
 * @param string $action 行为标识（如 'like', 'comment'）
 * @param int    $max    时间窗口内最大请求数
 * @param int    $window 时间窗口（秒），默认 60
 */
function check_rate_limit(string $action, int $max = 30, int $window = 60): void {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $key = 'rl_' . $action . '_' . md5($ip);
  $file = sys_get_temp_dir() . '/' . $key . '.php';

  $now = time();
  $cutoff = $now - $window;

  $records = [];
  if (file_exists($file)) {
    $data = @file_get_contents($file);
    if ($data) {
      $records = unserialize($data);
      if (!is_array($records)) $records = [];
    }
  }

  // 清理过期记录
  $records = array_values(array_filter($records, fn($t) => $t > $cutoff));

  if (count($records) >= $max) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '操作过于频繁，请稍后再试']);
    exit;
  }

  $records[] = $now;
  @file_put_contents($file, serialize($records), LOCK_EX);
}
