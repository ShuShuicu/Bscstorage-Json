<?php
/**
 * Bscstorage Json
 * PHP 白山云存储输出Json数据
 * @author 鼠子(Tomoriゞ)
 * @link https://space.bilibili.com/435502585
 * @version 1.0
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);

// 写入key&secret
define("BSC_KEY", "");
define("BSC_SECRET", "");

try {
    // 引入 AWS SDK
    require 'aws.phar';

    // 创建缓存目录
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true); // 创建目录并设置权限
    }

    $paramMapping = [
        'All' => '/'
    ];

    $paramIdentifier = array_keys(array_filter($paramMapping, function ($key) {
        return isset($_GET[$key]);
    }, ARRAY_FILTER_USE_KEY))[0] ?? '';

    if (empty($paramIdentifier)) {
        throw new Exception('API参数错误', 400);
    }

    $PrefixIf = $paramMapping[$paramIdentifier];

    $number = isset($_GET['number']) ? filter_var($_GET['number'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0]
    ]) : 0;

    $page = isset($_GET['page']) ? max(1, filter_var($_GET['page'], FILTER_VALIDATE_INT)) : 1;

    $cli = Aws\S3\S3Client::factory([
        'endpoint' => 'https://ss.bscstorage.com',
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => BSC_KEY,
            'secret' => BSC_SECRET,
        ],
        'region' => 'us-east-1',
        'version' => 'latest',
        'http'    => ['timeout' => 30]
    ]);

    // 缓存路径
    $cacheFile = $cacheDir . "/cache_{$paramIdentifier}.json";
    $cacheTime = 86400;

    if (!file_exists($cacheFile) || time() - filemtime($cacheFile) >= $cacheTime) {
        $result = [];
        $marker = '';

        do {
            $resp = $cli->listObjects([
                'Bucket' => 'tomori',
                'Prefix' => $PrefixIf,
                'Marker' => $marker
            ]);

            if (empty($resp['Contents'])) break;

            // 遍历文件
            foreach ($resp['Contents'] as $content) {
                if (preg_match('/\.(jpe?g|png|gif|bmp|webp)$/i', $content['Key'])) {
                    $result[] = [
                        'file' => basename($content['Key']),
                        'url'  => $cli->getObjectUrl('tomori', $content['Key'])
                    ];
                }
                $marker = $content['Key'];
            }
        } while ($resp['IsTruncated']);

        register_shutdown_function(function () use ($cacheFile, $result) {
            file_put_contents($cacheFile, json_encode($result), LOCK_EX);
        });
    } else {
        $result = json_decode(file_get_contents($cacheFile), true) ?: [];
    }

    if ($number > 0) {
        $total = count($result);
        $totalPages = max(1, ceil($total / $number));
        $page = min(max(1, $page), $totalPages);
        $result = array_slice($result, ($page - 1) * $number, $number);
    }

    $response = [
        'data' => $result,
        'meta' => [
            'current_page' => $page,
            'per_page'     => $number,
            'total_items'  => count($result),
            'total_pages'  => $number > 0 ? ceil(count($result) / $number) : 1
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Aws\S3\Exception\S3Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => '存储服务暂不可用'], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'error' => $e->getMessage(),
        'code'  => $e->getCode() ?: 'UNKNOWN_ERROR'
    ], JSON_PRETTY_PRINT);
}
