<?php
declare(strict_types=1);

/**
 * 应老师 AI 问答配置
 *
 * 请填入你的 MiniMax 开放平台账号信息：
 * https://platform.minimaxi.com/
 *
 * 安全提示：此文件包含敏感信息，请勿提交到 Git。
 */

return [
    // MiniMax 开放平台配置
    'minimax_api_key'   => 'sk-api-bJ5uZeQ-2GU4lAyTf23pngyhlD-TRD9waeZQXDJO6gkyceuIr8FFZo24pZNEwjxK3srDMqjPhndkW7fCLeOGK-YoFct7XQDrwEshh2DbV4RubU1IwWD_IN8',
    'minimax_group_id'  => '2033456969217478707',

    // 模型配置
    'chat_model'        => 'MiniMax-M2.7',   // MiniMax Chat 模型
    'embedding_model'   => 'embo-01',    // 向量化模型，1536 维

    // RAG 配置
    'top_k'             => 3,            // 每次检索相关知识条数
    'embedding_batch'   => 20,           // Embedding API 单次批量大小

    // 数据目录
    'data_dir'          => __DIR__ . '/../data/teacher',
    'persona_file'      => __DIR__ . '/../data/teacher/persona.md',
];
