<?php
declare(strict_types=1);

/**
 * 应老师 AI 问答接口
 *
 * POST 参数:
 *   - question: string  用户问题
 *   - history:  string  JSON 格式的多轮对话历史（可选）
 *
 * 返回 JSON:
 *   { ok: true,  answer: "...", sources: [{ title }] }
 *   { ok: false, msg: "..." }
 *
 * 工作模式:
 *   1. 余额充足 → MiniMax Embedding + ChatCompletion Pro（RAG）
 *   2. 余额不足 → 关键词检索 + 智能模板回复（自动降级，充值后自动恢复）
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/app/ai_config.php';
$db     = db();

// ---------- CSRF 校验 ----------
$csrfToken = $_POST['csrf'] ?? '';
if (!csrf_check($csrfToken)) {
    echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
    exit;
}

// ---------- 参数 ----------
$question = trim($_POST['question'] ?? '');
if ($question === '') {
    echo json_encode(['ok' => false, 'msg' => '请输入问题']);
    exit;
}

$historyRaw = $_POST['history'] ?? '[]';
$history    = [];
if (is_string($historyRaw)) {
    $decoded = json_decode($historyRaw, true);
    if (is_array($decoded)) {
        $history = $decoded;
    }
}

// ========================================================================
//  工具函数
// ========================================================================

/**
 * 调用 MiniMax Embedding API（type=query）
 * @return array|null  向量数组；余额不足时返回 null
 */
function embedText(string $text, array $config): ?array {
    $url = 'https://api.minimaxi.com/v1/embeddings';
    $payload = json_encode([
        'model' => $config['embedding_model'],
        'texts' => [$text],
        'type'  => 'query',
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
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        if (($err['base_resp']['status_code'] ?? 0) === 1008) {
            return null; // insufficient balance
        }
        return [];
    }

    $data = json_decode($response, true);
    $vec = $data['vectors'][0] ?? [];
    return empty($vec) ? [] : $vec;
}

/**
 * 余弦相似度
 */
function cosineSimilarity(array $a, array $b): float {
    if (empty($a) || empty($b)) return 0.0;
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    for ($i = 0, $len = count($a); $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    $denom = sqrt($na) * sqrt($nb);
    return $denom < 1e-10 ? 0.0 : $dot / $denom;
}

/**
 * 向量相似度检索
 */
function vectorSearch(array $queryVec, PDO $db, int $topK): array {
    $rows = $db->query("SELECT content, source, embedding FROM teacher_knowledge")->fetchAll();
    if (empty($rows)) return [];

    $scored = [];
    foreach ($rows as $row) {
        $docVec = json_decode($row['embedding'], true);
        if (!is_array($docVec) || empty($docVec)) continue;
        $score = cosineSimilarity($queryVec, $docVec);
        $scored[] = [
            'content' => $row['content'],
            'source'  => $row['source'],
            'score'   => $score,
        ];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $topK);
}

/**
 * 关键词检索（降级方案）
 */
function keywordSearch(string $question, PDO $db, int $topK): array {
    $rows = $db->query("SELECT content, source FROM teacher_knowledge")->fetchAll();
    if (empty($rows)) return [];

    // 提取有意义的词（去掉常见停用词）
    $stopWords = ['的', '了', '在', '是', '我', '有', '和', '就', '不', '人', '都', '一', '一个',
                   '上', '也', '很', '到', '说', '要', '去', '你', '会', '着', '没有', '看', '好',
                   '自己', '这', '他', '她', '它', '们', '那', '什么', '怎么', '如何', '为什么',
                   '可以', '吗', '吧', '呢', '啊', '哦', '嗯', '呀', '嘛'];
    $keywords = [];
    $chars = preg_split('//u', $question, -1, PREG_SPLIT_NO_EMPTY);
    // 提取双字及以上词
    for ($i = 0; $i < count($chars) - 1; $i++) {
        $bigram = $chars[$i] . $chars[$i + 1];
        if (!in_array($bigram, $stopWords, true) && !in_array($chars[$i], $stopWords, true)) {
            $keywords[$bigram] = true;
        }
    }
    // 也保留单个非停用词字符
    foreach ($chars as $ch) {
        if (!in_array($ch, $stopWords, true) && trim($ch) !== '') {
            $keywords[$ch] = true;
        }
    }

    $keywordList = array_keys($keywords);
    if (empty($keywordList)) return [];

    $scored = [];
    foreach ($rows as $row) {
        $score = 0;
        foreach ($keywordList as $kw) {
            if (mb_strpos($row['content'], $kw) !== false) {
                $score++;
            }
        }
        if ($score > 0) {
            $scored[] = [
                'content' => $row['content'],
                'source'  => $row['source'],
                'score'   => $score,
            ];
        }
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $topK);
}

/**
 * 调用 MiniMax ChatCompletion V2 API（M2.7）
 * @return string|null  回答文本；余额不足/失败返回 null
 */
function chatCompletion(array $messages, array $config): ?string {
    $url = 'https://api.minimaxi.com/v1/text/chatcompletion_v2';

    $payload = json_encode([
        'model'       => $config['chat_model'],
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 2048,
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
        if (($err['base_resp']['status_code'] ?? 0) === 1008) {
            return null; // insufficient balance
        }
        return null;
    }

    $data = json_decode($response, true);

    // M2.7 / v2 返回格式: choices[0].message.content
    if (isset($data['reply']) && $data['reply'] !== '') {
        return $data['reply'];
    }
    if (isset($data['choices'][0]['message']['content'])) {
        return $data['choices'][0]['message']['content'];
    }
    return null;
}

// ========================================================================
//  降级回复模板（结合知识库内容生成）
// ========================================================================

function buildFallbackAnswer(string $question, array $chunks): string {
    // 合并知识库上下文
    $context = '';
    foreach ($chunks as $ch) {
        $context .= $ch['content'] . "\n";
    }

    $questionLower = mb_strtolower($question, 'UTF-8');

    // 根据问题类型 + 知识库内容生成回复
    $hasFunction = mb_strpos($questionLower, '函数') !== false;
    $hasProof    = mb_strpos($questionLower, '证明') !== false;
    $hasBase     = mb_strpos($questionLower, '基础') !== false || mb_strpos($questionLower, '怎么学') !== false;
    $hasMethod   = mb_strpos($questionLower, '思路') !== false || mb_strpos($questionLower, '方法') !== false;

    $kbHasAlgebra = mb_strpos($context, '代数') !== false;
    $kbHasGeo     = mb_strpos($context, '几何') !== false;
    $kbHasDepth   = mb_strpos($context, '深度') !== false;

    if ($hasFunction) {
        return '同学你好，这个问题问得很好。' . "\n\n" . '关于函数的概念，我们可以这样理解：函数描述的是两个变量之间的**对应关系**——给你一个 $x$，能唯一确定一个 $y$。' . "\n\n" . '你可以把它想象成一台"输入-输出"机器：' . "\n" . '- 输入一个值' . "\n" . '- 经过规则运算' . "\n" . '- 得到一个输出' . "\n\n" . '> **关键：** 判断一个关系是不是函数，就看对于每一个 $x$，是否只有唯一一个 $y$ 和它对应。' . "\n\n" . '**小练习：** 试试判断 $y = \pm\sqrt{x}$ 是不是函数？想清楚再回答我 😊';

    } elseif ($hasProof) {
        return "证明题没思路？这是很正常的，别着急。\n\n证明的本质是**从已知条件出发，通过逻辑推理，到达结论**。关键步骤是：\n\n1. **把条件和结论写清楚**，看看已知了什么、要证什么\n2. **回忆相关定理**——这道题可能和哪个学过的定理有关？\n3. **尝试倒推**：要得到结论，需要先证明什么？\n\n> **一个小技巧：** 如果正向推理卡住了，试试反证法——假设结论不成立，看看会不会推出和已知条件矛盾的结果。\n\n证明题就像拼图，每个条件都是一块拼图碎片，关键是找到它们拼接的方式。你具体遇到哪道题了？";

    } elseif ($hasBase || $hasMethod) {
        $geoAdvice = $kbHasGeo ? "\n- **八上几何**是关键分水岭，几何基本功一定要在这个阶段打扎实" : "";
        $depthAdvice = $kbHasDepth ? "\n- **深度优先于进度**——宁可慢一点，也要把每个知识点理解透" : "";

        return "这个话题问得好。学好数学最核心的就两点：**扎实的基本功**和**主动的思考**。\n\n这里给你几个具体建议：\n\n- **回归课本**：先把课本上的概念、例题彻底弄懂，做到能用自己的话讲出来\n- **每日坚持**：与其一周刷两小时题，不如每天做 15 分钟同类练习——大脑需要的是持续的熟悉度{$geoAdvice}{$depthAdvice}\n- **错题复盘**：每道错题写一句复盘（10-20 字），说清楚自己为什么错\n\n不要着急，数学学习是一场马拉松，不是短跑。你目前主要在学哪个年级的内容？";

    } else {
        // 通用回复
        $parts = [];
        if ($kbHasAlgebra) $parts[] = "注意到你提到了代数相关的内容，这让我想起——代数基本功是数学学习的基石。";
        if ($kbHasGeo) $parts[] = "几何学习的关键是建立直观的空间想象，然后再去理解背后的逻辑结构。";
        if ($kbHasDepth) $parts[] = "我一直强调**深度优先于进度**——学数学不怕慢，就怕基础不牢。";

        $extra = !empty($parts) ? "\n\n" . implode("\n", $parts) : "";

        return "同学你好，很高兴你愿意提问。在数学学习中，敢于问问题本身就是很重要的一步。$extra\n\n这道题或者说这个知识点，你可以试着从这几个角度去思考：\n\n1. **先回顾相关的定义和定理**——很多题卡住是因为基本概念没吃透\n2. **试着画图或列个表**——把抽象的问题具体化\n3. **找一道类似的例题**——看看例题的解题思路，再对照这道题\n\n你能把具体题目或知识点告诉我吗？我们一起分析 😊";
    }
}

// ========================================================================
//  主流程
// ========================================================================

try {
    $chunks       = [];
    $useFallback  = false;

    // ---- 尝试向量检索（需要余额） ----
    $queryVec = embedText($question, $config);

    if ($queryVec === null) {
        // 余额不足，降级为关键词检索
        $useFallback = true;
        $chunks = keywordSearch($question, $db, $config['top_k']);
    } elseif (!empty($queryVec)) {
        // 向量检索
        $chunks = vectorSearch($queryVec, $db, $config['top_k']);
    }

    // ---- 生成回答 ----
    if ($useFallback) {
        // 降级模式：用知识库内容生成回复
        $answer = buildFallbackAnswer($question, $chunks);
    } else {
        // 正常模式：调用 Chat API
        $personaFile = $config['persona_file'];
        $personaContent = file_exists($personaFile) ? file_get_contents($personaFile) : '';

        // system prompt：完整人设（优先使用 persona.md，含 5 层行为模板）
        $systemPrompt = trim($personaContent) ?: '你是一位资深初中数学老师，名字叫应老师。'
            . '教学理念：深度优先于进度，先建立直观模型再揭示结构，重视基本功和体系化积累。'
            . '表达风格：克制、直接、分层，不说"这题很简单"，不说空喊口号的话。'
            . '常用口头禅："先把结构看清楚"、"不是难，是还没看到关键"。'
            . '回答要求：用中文；数学公式用 LaTeX（行内$...$，展示$$...$$）；结合备课资料回答，不生硬引用；资料不足可结合自己知识补充；鼓励学生。';

        // 将详细的备课资料放到 user 消息的上下文里
        $contextPrefix = '';
        if (!empty($chunks)) {
            $contextParts = [];
            foreach ($chunks as $i => $chunk) {
                $contextParts[] = "[参考 {$i}]（来源: {$chunk['source']}）\n{$chunk['content']}";
            }
            $contextPrefix = "以下是你备课资料中与当前问题相关的内容，请参考但不生硬引用：\n\n"
                . implode("\n\n---\n\n", $contextParts) . "\n\n";
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        $trimmedHistory = array_slice($history, -10);
        foreach ($trimmedHistory as $msg) {
            if (isset($msg['role'], $msg['content']) && in_array($msg['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }
        // 把上下文和问题拼在一起作为用户消息
        $messages[] = ['role' => 'user', 'content' => $contextPrefix . $question];

        $answer = chatCompletion($messages, $config);
        if ($answer === null) {
            // Chat API 失败（可能中途余额扣完），降级
            $answer = buildFallbackAnswer($question, $chunks);
        }
    }

    // ---- 来源 ----
    $sources = [];
    $seen = [];
    foreach ($chunks as $chunk) {
        $src = $chunk['source'];
        if (!isset($seen[$src])) {
            $seen[$src] = true;
            $sources[] = ['title' => str_replace('.md', '', $src)];
        }
    }

    echo json_encode([
        'ok'      => true,
        'answer'  => $answer,
        'sources' => $sources,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => '系统繁忙，请稍后再试。']);
}
