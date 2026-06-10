<?php

/**
 * DBなし・メールなしで、番組表からキーワードにヒットした番組だけをHTML化するスクリプト。
 *
 * ローカル実行:
 *   php scripts/build.php
 *
 * GitHub Actions実行:
 *   .github/workflows/update-tv.yml から毎日実行される想定。
 */

date_default_timezone_set('Asia/Tokyo');

const BASE_URL = 'https://bangumi.org';
const FETCH_DAYS = 1;
const DISPLAY_DAYS = 1;
const REQUEST_SLEEP_MICROSECONDS = 250000;

$rootDir = dirname(__DIR__);
$keywordFile = $rootDir . '/keywords.json';
$outputFile = $rootDir . '/public/index.html';

/**
 * JSONからキーワードを読み込む。
 *
 * 通常:
 *   ["キーワード1", "キーワード2"]
 *
 * 旧PHP配列に近い形も一応許容:
 *   [["キーワード1"], ["キーワード2"]]
 */
function loadKeywords(string $keywordFile): array
{
    if (!file_exists($keywordFile)) {
        throw new RuntimeException('keywords.json が見つかりません。');
    }

    $json = file_get_contents($keywordFile);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException('keywords.json の形式が正しくありません。');
    }

    $keywords = [];
    foreach ($data as $item) {
        if (is_string($item)) {
            $keyword = trim($item);
        } elseif (is_array($item) && isset($item[0]) && is_string($item[0])) {
            $keyword = trim($item[0]);
        } else {
            continue;
        }

        if ($keyword !== '') {
            $keywords[] = $keyword;
        }
    }

    return array_values(array_unique($keywords));
}

/**
 * HTML出力用にエスケープする。
 */
function h(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}


/**
 * コマンドラインで途中経過をすぐ表示するため、出力バッファを無効化する。
 */
function initializeConsoleOutput(): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    ob_implicit_flush(true);
}

/**
 * 実行ログを1行出力する。
 */
function logLine(string $message): void
{
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;

    if (function_exists('flush')) {
        flush();
    }
}

/**
 * 秒数を見やすい形式へ変換する。
 */
function formatDuration(float $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    $minutes = intdiv($seconds, 60);
    $remainSeconds = $seconds % 60;

    if ($minutes <= 0) {
        return $remainSeconds . '秒';
    }

    return $minutes . '分' . sprintf('%02d秒', $remainSeconds);
}

/**
 * 途中経過を表示するタイミングを判定する。
 */
function shouldShowProgress(int $current, int $total): bool
{
    if ($current === 1 || $current === $total) {
        return true;
    }

    return $current % 10 === 0;
}

/**
 * 進捗ログを表示する。
 */
function logProgress(string $targetName, string $dateYmd, int $current, int $total, int $successCount, int $failureCount, int $hitCount, float $startedAt): void
{
    $elapsed = microtime(true) - $startedAt;
    $percent = $total > 0 ? ($current / $total * 100) : 100;
    $estimatedTotal = $current > 0 ? ($elapsed / $current * $total) : 0;
    $remaining = max(0, $estimatedTotal - $elapsed);

    logLine(sprintf(
        '%s %s 進捗: %d/%d件 %.1f%% / 成功:%d 失敗:%d ヒット:%d / 経過:%s 残り目安:%s',
        $targetName,
        $dateYmd,
        $current,
        $total,
        $percent,
        $successCount,
        $failureCount,
        $hitCount,
        formatDuration($elapsed),
        formatDuration($remaining)
    ));
}

/**
 * 前後空白・改行を整形する。
 */
function normalizeText(?string $text): string
{
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

/**
 * HTTP取得。
 */
function fetchUrl(string $url, $context)
{
    $html = @file_get_contents($url, false, $context);

    if ($html === false) {
        return false;
    }

    return $html;
}

/**
 * HTMLをDOMDocumentへ変換する。
 */
function createDom(string $html): DOMDocument
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

    foreach ($dom->childNodes as $node) {
        if ($node->nodeType === XML_PI_NODE) {
            $dom->removeChild($node);
            break;
        }
    }

    $dom->encoding = 'UTF-8';
    libxml_clear_errors();

    return $dom;
}

/**
 * 一覧ページから個別番組URLを抽出する。
 */
function extractProgramUrls(string $html): array
{
    $dom = createDom($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//a[starts-with(@href, "/tv_events/")]');

    $urls = [];
    foreach ($nodes as $node) {
        $href = $node->getAttribute('href');
        if ($href === '') {
            continue;
        }

        if (strpos($href, 'http') !== 0) {
            $href = BASE_URL . $href;
        }

        $urls[$href] = true;
    }

    return array_keys($urls);
}

/**
 * セクション見出しから本文を抽出する。
 */
function findSectionTextByHeading(DOMXPath $xpath, array $headings): string
{
    foreach ($headings as $heading) {
        $nodes = $xpath->query('//*[self::h2 or self::h3][contains(normalize-space(.), "' . $heading . '")]');

        if ($nodes->length === 0) {
            continue;
        }

        $node = $nodes->item(0);
        $texts = [];
        $sibling = $node->nextSibling;

        while ($sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($sibling->nodeName);

                if ($tag === 'h2' || $tag === 'h3') {
                    break;
                }

                $text = normalizeText($sibling->textContent);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }

            $sibling = $sibling->nextSibling;
        }

        if (!empty($texts)) {
            return implode("\n", $texts);
        }
    }

    return '';
}

/**
 * チャンネル候補から最長一致でチャンネル名を抽出する。
 */
function detectChannelByMaster(string $text, array $channelCandidates): string
{
    $text = normalizeText($text);

    usort($channelCandidates, function ($a, $b) {
        return mb_strlen($b) - mb_strlen($a);
    });

    foreach ($channelCandidates as $channel) {
        if (mb_strpos($text, $channel) !== false) {
            return $channel;
        }
    }

    return '';
}

/**
 * 放送情報行から日時を抽出する。
 */
function parseBroadcastDateTime(string $broadcastLine)
{
    $matches = [];

    if (!preg_match('/(\d{1,2})月(\d{1,2})日.*?(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/u', $broadcastLine, $matches)) {
        return false;
    }

    return [
        'month' => (int)$matches[1],
        'day' => (int)$matches[2],
        'start_time' => $matches[3],
        'end_time' => $matches[4],
    ];
}

/**
 * チャンネル候補から拾えなかった時の保険。
 */
function fallbackChannelFromBroadcastLine(string $broadcastLine): string
{
    $text = normalizeText($broadcastLine);
    $text = preg_replace('/^\d{1,2}月\d{1,2}日.*?\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}\s*/u', '', $text);
    $text = preg_replace('/(出演者|スタッフ|音楽|番組内容|番組概要|番組詳細|ご案内).*$/u', '', (string)$text);
    $text = trim((string)$text);

    if (preg_match('/^(.{2,20}?)(\s|　|▽|▼|★|「|『|$)/u', $text, $matches)) {
        return trim($matches[1]);
    }

    return $text;
}

/**
 * 詳細ページから番組情報を抽出する。
 */
function parseProgramDetail(string $url, $context, array $channelCandidates)
{
    $html = fetchUrl($url, $context);

    if ($html === false) {
        return false;
    }

    $dom = createDom($html);
    $xpath = new DOMXPath($dom);

    $title = '';
    $h1 = $xpath->query('//h1');
    if ($h1->length > 0) {
        $title = normalizeText($h1->item(0)->textContent);
    }

    $subTitle = '';
    $h2 = $xpath->query('//h2');
    if ($h2->length > 0) {
        $subTitle = normalizeText($h2->item(0)->textContent);
    }

    if ($title === '' && $subTitle !== '') {
        $title = $subTitle;
    } elseif ($title !== '' && $subTitle !== '' && mb_strpos($subTitle, $title) !== 0) {
        $title .= ' ' . $subTitle;
    }

    $summary = '';
    $metaDescription = $xpath->query('//meta[@name="description"]');
    if ($metaDescription->length > 0) {
        $summary = normalizeText($metaDescription->item(0)->getAttribute('content'));
        $summary = preg_replace('/\s*-\s*番組表\.Gガイド.*$/u', '', $summary);
        $summary = (string)$summary;
    }

    if ($summary === '') {
        $metaTextNodes = $xpath->query('//h2/following-sibling::*');
        foreach ($metaTextNodes as $node) {
            $text = normalizeText($node->textContent);
            if ($text === '') {
                continue;
            }

            if (!preg_match('/\d{1,2}月\d{1,2}日/u', $text)) {
                $summary = $text;
                break;
            }
        }
    }

    $broadcastLine = '';
    $allTextNodes = $xpath->query('//*[self::p or self::div or self::span]');
    foreach ($allTextNodes as $node) {
        $text = normalizeText($node->textContent);
        if (preg_match('/\d{1,2}月\d{1,2}日.*\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}/u', $text)) {
            $broadcastLine = $text;
            break;
        }
    }

    if ($broadcastLine === '') {
        return false;
    }

    $dateInfo = parseBroadcastDateTime($broadcastLine);
    if ($dateInfo === false) {
        return false;
    }

    $month = $dateInfo['month'];
    $day = $dateInfo['day'];
    $startTime = $dateInfo['start_time'];
    $endTime = $dateInfo['end_time'];

    $channel = detectChannelByMaster($broadcastLine, $channelCandidates);
    if ($channel === '') {
        $channel = fallbackChannelFromBroadcastLine($broadcastLine);
    }

    $year = (int)date('Y');
    $currentMonth = (int)date('n');

    if ($month < $currentMonth - 6) {
        $year++;
    } elseif ($month > $currentMonth + 6) {
        $year--;
    }

    $startDatetime = sprintf('%04d-%02d-%02d %s:00', $year, $month, $day, $startTime);
    $endDatetime = sprintf('%04d-%02d-%02d %s:00', $year, $month, $day, $endTime);

    if (strtotime($endDatetime) <= strtotime($startDatetime)) {
        $endDatetime = date('Y-m-d H:i:s', strtotime($endDatetime . ' +1 day'));
    }

    $duration = (int)round((strtotime($endDatetime) - strtotime($startDatetime)) / 60);

    $detail = $summary;
    $detailCandidate = findSectionTextByHeading($xpath, ['番組内容', 'ご案内', '番組概要']);
    if ($detailCandidate !== '' && mb_strlen($detailCandidate) > mb_strlen($summary)) {
        $detail = $detailCandidate;
    }

    return [
        'start_datetime' => $startDatetime,
        'end_datetime' => $endDatetime,
        'duration' => $duration,
        'channel' => $channel,
        'title' => $title,
        'summary' => $summary,
        'detail' => $detail,
        'url' => $url,
    ];
}

/**
 * 一覧URLを生成する。
 */
function buildListUrl(array $target, string $dateYmd): string
{
    if ($target['type'] === 'terrestrial') {
        return BASE_URL . '/epg/td?broad_cast_date=' . $dateYmd . '&ggm_group_id=' . $target['ggm_group_id'];
    }

    if ($target['type'] === 'bs') {
        return BASE_URL . '/epg/bs?broad_cast_date=' . $dateYmd;
    }

    return '';
}

/**
 * キーワードにヒットするか判定し、ヒット情報を返す。
 */
function findKeywordHits(array $program, array $keywords): array
{
    $targetText = $program['title'] . ' ' . $program['summary'] . ' ' . $program['detail'];
    $searchText = $program['summary'] . ' ' . $program['detail'];

    $hitKeywords = [];
    $snippets = [];

    foreach ($keywords as $keyword) {
        $escapedKeyword = preg_quote($keyword, '/');

        if (preg_match('/' . $escapedKeyword . '/iu', $targetText) !== 1) {
            continue;
        }

        $hitKeywords[] = $keyword;

        if (preg_match('/(.{0,30})(' . $escapedKeyword . ')(.{0,30})/isu', $searchText, $matches) === 1) {
            $snippets[] = h($matches[1]) . '<strong>' . h($matches[2]) . '</strong>' . h($matches[3]);
        }
    }

    return [
        'keywords' => array_values(array_unique($hitKeywords)),
        'snippets' => array_values(array_unique($snippets)),
    ];
}

/**
 * 日時を表示用に整形する。
 */
function formatProgramTime(string $startDatetime): string
{
    $timestamp = strtotime($startDatetime);
    $week = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', $timestamp)];

    return date('n月j日', $timestamp) . '(' . $week . ') ' . date('H:i', $timestamp);
}

/**
 * CSSなしのシンプルなHTMLを生成する。
 */
function buildHtml(array $keywords, array $hits, array $stats): string
{
    // 番組を放送開始日時順に並び替える
    usort($hits, function ($a, $b) {
        $timeA = strtotime($a['start_datetime'] ?? '');
        $timeB = strtotime($b['start_datetime'] ?? '');

        if ($timeA === $timeB) {
            return strcmp($a['channel'] ?? '', $b['channel'] ?? '');
        }

        return $timeA <=> $timeB;
    });

    $updatedAt = date('Y年n月j日 H:i');
    $programCount = $stats['program_count'] ?? 0;
    $hitCount = count($hits);

    $html = "<!doctype html>\n";
    $html .= "<html lang=\"ja\">\n";
    $html .= "<head>\n";
    $html .= "<meta charset=\"UTF-8\">\n";
    $html .= "<title>番組キーワード検索</title>\n";
    $html .= "</head>\n";
    $html .= "<body>\n";

    $html .= "<h1>番組キーワード検索</h1>\n";
    $html .= "<p>更新日時: " . h($updatedAt) . "</p>\n";
    $html .= "<p>検索対象番組数: " . h((string)$programCount) . " / ヒット件数: " . h((string)$hitCount) . "</p>\n";

    $html .= "<p><b>キーワード:</b> " . h(implode(' ', $keywords)) . "</p>\n";
    $html .= "<hr>\n";

    if (empty($hits)) {
        $html .= "<p>ヒットした番組はありません。</p>\n";
    }

    foreach ($hits as $program) {
        $startTimestamp = strtotime($program['start_datetime'] ?? '');
        $startDate = $startTimestamp ? date('n月j日', $startTimestamp) : '';
        $startWeek = $startTimestamp ? mb_substr('日月火水木金土', (int)date('w', $startTimestamp), 1) : '';
        $startTime = $startTimestamp ? date('H:i', $startTimestamp) : '';

        $hitKeywords = $program['hit_keywords'] ?? [];
        $hitKeywordText = is_array($hitKeywords) ? implode(' ', $hitKeywords) : (string)$hitKeywords;

        $duration = $program['duration'] ?? '';
        $channel = $program['channel'] ?? '';
        $title = $program['title'] ?? '';
        $url = $program['url'] ?? '#';

        $html .= "<p>\n";
        $html .= "<b>[" . h($hitKeywordText) . "]</b> ";
        $html .= "<a href=\"" . h($url) . "\" target=\"_blank\">";
        $html .= h($startDate . '(' . $startWeek . ') ' . $startTime . '（' . $duration . '分）');
        $html .= "</a><br>\n";

        $html .= h($channel) . "<br>\n";
        $html .= "<b>" . h($title) . "</b><br>\n";

        if (!empty($program['hit_snippets']) && is_array($program['hit_snippets'])) {
            foreach ($program['hit_snippets'] as $snippet) {
                $html .= $snippet . "<br>\n";
            }
        } 

        $html .= "</p>\n\n";
    }

    $html .= "</body>\n";
    $html .= "</html>\n";

    return $html;
}

$channelMaster = [
    'terrestrial' => [
        'NHK総合1・東京',
        'NHKEテレ1東京',
        '日テレ1',
        'テレビ朝日',
        'TBS1',
        'テレ東',
        'フジテレビ',
        'TOKYO MX1',
        'TOKYO MX2',
        'tvk1',
        'チバテレ1',
        'テレ玉1',
    ],
    'bs' => [
        'NHK BS',
        'NHKBS',
        'ＢＳ日テレ',
        'BS日テレ',
        'ＢＳ朝日',
        'BS朝日1',
        'ＢＳ-ＴＢＳ',
        'BS-TBS',
        'ＢＳテレ東',
        'BSテレ東',
        'ＢＳフジ',
        'BSフジ・181',
        // 'WOWOWプライム',
        // 'WOWOWライブ',
        // 'WOWOWシネマ',
        'BS11イレブン',
        'BS12 トゥエルビ',
        'BS松竹東急',
        'BSJapanext',
        // 'J:COM BS',
        'BSよしもと',
        // 'スターチャンネル',
        '放送大学ex',
        '放送大学on',
    ],
];

$targets = [
    [
        'name' => '地上波',
        'type' => 'terrestrial',
        'ggm_group_id' => '45',
    ],
    [
        'name' => 'BS',
        'type' => 'bs',
    ],
];

$headers = [
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0',
    'Referer: https://bangumi.org/',
];

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => 30,
        'ignore_errors' => true,
    ],
]);

try {
    initializeConsoleOutput();
    set_time_limit(0);

    $keywords = loadKeywords($keywordFile);

    if (empty($keywords)) {
        throw new RuntimeException('検索キーワードが空です。keywords.json にキーワードを追加してください。');
    }

    logLine('検索キーワード: ' . implode(' / ', $keywords));
    logLine('取得日数: ' . FETCH_DAYS . '日 / 表示対象: 直近' . DISPLAY_DAYS . '日');

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $limit = $now->modify('+' . DISPLAY_DAYS . ' days');
    $hits = [];
    $programCount = 0;

    foreach ($targets as $target) {
        logLine($target['name'] . ' 取得開始');
        $channelCandidates = $channelMaster[$target['type']] ?? [];

        for ($dayNum = 0; $dayNum < FETCH_DAYS; $dayNum++) {
            $dateYmd = date('Ymd', strtotime('+' . $dayNum . ' day'));
            $listUrl = buildListUrl($target, $dateYmd);

            if ($listUrl === '') {
                continue;
            }

            logLine('一覧URL: ' . $listUrl);
            $listHtml = fetchUrl($listUrl, $context);

            if ($listHtml === false) {
                logLine('一覧取得失敗: ' . $listUrl);
                continue;
            }

            $programUrls = extractProgramUrls($listHtml);
            $totalCount = count($programUrls);
            $successCount = 0;
            $failureCount = 0;
            $dayHitCount = 0;
            $startedAt = microtime(true);

            logLine('抽出件数: ' . $totalCount . '件');

            foreach ($programUrls as $index => $programUrl) {
                $current = $index + 1;
                $program = parseProgramDetail($programUrl, $context, $channelCandidates);

                if ($program === false) {
                    $failureCount++;
                    logLine(sprintf('解析失敗: %s %s %d/%d件 %s', $target['name'], $dateYmd, $current, $totalCount, $programUrl));

                    if (shouldShowProgress($current, $totalCount)) {
                        logProgress($target['name'], $dateYmd, $current, $totalCount, $successCount, $failureCount, $dayHitCount, $startedAt);
                    }

                    usleep(REQUEST_SLEEP_MICROSECONDS);
                    continue;
                }

                $successCount++;
                $programCount++;

                $start = new DateTimeImmutable($program['start_datetime'], new DateTimeZone('Asia/Tokyo'));
                if ($start >= $now && $start <= $limit) {
                    $hitInfo = findKeywordHits($program, $keywords);

                    if (!empty($hitInfo['keywords'])) {
                        $program['hit_keywords'] = $hitInfo['keywords'];
                        $program['hit_snippets'] = $hitInfo['snippets'];
                        $hits[] = $program;
                        $dayHitCount++;

                        logLine(sprintf(
                            'ヒット: [%s] %s %s %s',
                            implode(' / ', $hitInfo['keywords']),
                            formatProgramTime($program['start_datetime']),
                            $program['channel'],
                            $program['title']
                        ));
                    }
                }

                if (shouldShowProgress($current, $totalCount)) {
                    logProgress($target['name'], $dateYmd, $current, $totalCount, $successCount, $failureCount, $dayHitCount, $startedAt);
                }

                usleep(REQUEST_SLEEP_MICROSECONDS);
            }

            logLine(sprintf(
                '%s %s 完了: 成功:%d 失敗:%d ヒット:%d 経過:%s',
                $target['name'],
                $dateYmd,
                $successCount,
                $failureCount,
                $dayHitCount,
                formatDuration(microtime(true) - $startedAt)
            ));
        }
    }

    usort($hits, function (array $a, array $b): int {
        return strtotime($a['start_datetime']) <=> strtotime($b['start_datetime']);
    
    $html = buildHtml($keywords, $hits, [
        'program_count' => $programCount,
    ]);

    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    file_put_contents($outputFile, $html);

    logLine('HTML生成OK: ' . $outputFile);
    logLine('取得成功件数: ' . $programCount);
    logLine('ヒット件数: ' . count($hits));
} catch (Throwable $e) {
    $errorHtml = "<!doctype html>\n<html lang=\"ja\"><head><meta charset=\"UTF-8\"><title>番組キーワード検索</title></head><body>";
    $errorHtml .= "<h1>番組キーワード検索</h1>";
    $errorHtml .= "<p>HTML生成中にエラーが発生しました。</p>";
    $errorHtml .= "<pre>" . h($e->getMessage()) . "</pre>";
    $errorHtml .= "</body></html>";

    file_put_contents($outputFile, $errorHtml);

    fwrite(STDERR, 'エラー: ' . $e->getMessage() . "\n");
    exit(1);
}
