<?php
declare(strict_types=1);

/**
 * 应老师知识库索引脚本
 *
 * 运行方式: php app/ai_index.php
 *
 * 功能:
 *   1. 读取 data/teacher/ 下的所有 .md 文件
 *   2. 按章节切分为知识块
 *   3. 调用 MiniMax Embedding API 生成向量（余额不足时跳过，不影响后续关键词检索）
 *   4. 存入 teacher_knowledge 表
 *
 * 幂等: 已存在的相同 content 不会重复插入
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

$config = require __DIR__ . '/ai_config.php';
$db     = db();

// ---------- 1. 读取 markdown 文件 ----------
$mdFiles = glob($config['data_dir'] . '/*.md');
if (!$mdFiles) {
    echo "错误: data/teacher/ 目录下没有 .md 文件\n";
    exit(1);
}

$chunks = [];
foreach ($mdFiles as $filePath) {
    $source = basename($filePath);
    $content = file_get_contents($filePath);
    if ($content === false || trim($content) === '') {
        echo "跳过空文件: {$source}\n";
        continue;
    }

    // 按 ## 或 --- 分割章节
    $parts = preg_split('/^(?=## |---|---)/m', $content, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || mb_strlen($part) < 20) {
            continue;
        }
        $chunks[] = [
            'content' => $part,
            'source'  => $source,
        ];
    }
}

echo "共读取 " . count($chunks) . " 个知识块\n";

// ---------- 2. 去重 ----------
$stmt = $db->query("SELECT content FROM teacher_knowledge");
$existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
$existingMap = [];
foreach ($existing as $row) {
    $existingMap[md5($row)] = true;
}

$toInsert = [];
foreach ($chunks as $chunk) {
    $key = md5($chunk['content']);
    if (!isset($existingMap[$key])) {
        $toInsert[] = $chunk;
    }
}

echo "新增 " . count($toInsert) . " 个知识块（已跳过 " . (count($chunks) - count($toInsert)) . " 个重复）\n";

if (empty($toInsert)) {
    echo "知识库已是最新，无需更新。\n";
    exit(0);
}

// ---------- 3. 批量调用 MiniMax Embedding API ----------

/**
 * 调用 MiniMax Embedding API，返回向量数组。
 * 余额不足时返回 null（调用方决定降级）。
 */
function minimaxEmbed(array $texts, array $config): ?array {
    $url = 'https://api.minimaxi.com/v1/embeddings';

    $payload = json_encode([
        'model' => $config['embedding_model'],
        'texts' => $texts,
        'type'  => 'db',
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['minimax_api_key'],
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        $code = $err['base_resp']['status_code'] ?? 0;
        if ($code === 1008) {
            // insufficient balance — 调用方决定降级
            return null;
        }
        echo "  Embedding API 错误 (HTTP {$httpCode}): {$response}\n";
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['vectors'])) {
        echo "  Embedding API 返回格式异常: {$response}\n";
        return null;
    }

    return $data['vectors'];
}

$batchSize   = $config['embedding_batch'];
$inserted    = 0;
$hasBalance  = true; // 先假设有余额，遇到 1008 后关闭

for ($i = 0; $i < count($toInsert); $i += $batchSize) {
    $batch = array_slice($toInsert, $i, $batchSize);
    $texts = array_column($batch, 'content');

    echo "  批次 " . (int)($i / $batchSize + 1) . "/" . (int)(ceil(count($toInsert) / $batchSize)) . " ... ";

    $vectors = null;
    if ($hasBalance) {
        $vectors = minimaxEmbed($texts, $config);
        if ($vectors === null) {
            // API 调用失败（可能余额不足），后续批次不再尝试嵌入
            $hasBalance = false;
            echo "⚠ Embedding 不可用，后续存入纯文本（无向量）\n";
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO teacher_knowledge (content, source, embedding) VALUES (:content, :source, :embedding)"
    );

    $db->beginTransaction();
    try {
        foreach ($batch as $idx => $chunk) {
            $embeddingJson = ($vectors !== null && isset($vectors[$idx]))
                ? json_encode($vectors[$idx], JSON_UNESCAPED_UNICODE)
                : '[]';
            $stmt->execute([
                ':content'   => $chunk['content'],
                ':source'    => $chunk['source'],
                ':embedding' => $embeddingJson,
            ]);
            $inserted++;
        }
        $db->commit();
        if ($vectors !== null) {
            echo "ok（已嵌入）\n";
        } else {
            echo "ok（纯文本）\n";
        }
    } catch (Exception $e) {
        $db->rollBack();
        echo "失败: " . $e->getMessage() . "\n";
    }

    if ($i + $batchSize < count($toInsert)) {
        usleep(200000);
    }
}

echo "\n完成！共插入 {$inserted} 条知识记录。\n";
if (!$hasBalance) {
    echo "提示: MiniMax 账户余额不足，知识块未生成向量。后续问答将使用关键词检索降级方案，充值后重新运行本脚本即可补充向量。\n";
}
