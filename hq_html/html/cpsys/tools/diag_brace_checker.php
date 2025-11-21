<?php
declare(strict_types=1);

/**
 * Brace Checker (Stable UI + Immediate Scan)
 * - 根目录：从本文件回退 3 层作为 /hq/hq_html（自动 realpath）
 * - 表单：输入相对根目录的多行路径（/app、/html/cpsys），保存到 brace_checker.targets.json，并立刻扫描
 * - 仅扫描 PHP 扩展：php, phtml, inc, phpt
 * - 基于 token_get_all 忽略字符串/注释/heredoc/inline HTML，检查 { } 与尾部 "?>"
 * - 页面顶部始终显示：基准根、配置文件、已保存/无效目录、扫描统计
 * - JSON 输出：?format=json
 * - 自检：?selftest=1（行数、sha256、函数存在性）
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

/* -------- 基本常量（稳妥求值） -------- */
$SCRIPT_DIR = __DIR__;                               // .../hq_html/html/cpsys/tools
$HQHTML_CAND1 = realpath(dirname(__DIR__, 3));       // 回退3层 → .../hq_html
$HQHTML_CAND2 = realpath($SCRIPT_DIR . '/../../..'); // 另一种等价写法
$HQHTML = $HQHTML_CAND1 ?: $HQHTML_CAND2 ?: '';
$CONFIG = $SCRIPT_DIR . '/brace_checker.targets.json';

$VERSION = '1.1-stable-ui';
$EXTS = ['php','phtml','inc','phpt'];
$IGNORE = ['/.git/','/vendor/','/node_modules/','/.idea/','/.vscode/','/storage/logs/','/tmp/','/cache/'];

/* -------- 小工具 -------- */
function ui_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function starts_with($haystack, $needle){ return substr($haystack, 0, strlen($needle)) === $needle; }

function cfg_load($path){
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function cfg_save($path, $data){
    $data['updated_at'] = date('c');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    return (bool)@file_put_contents($path, $json);
}

function rel_to_abs($rel, $base){
    $rel = trim((string)$rel);
    if ($rel === '') return null;
    if ($rel[0] !== '/') $rel = '/'.$rel;
    $abs = rtrim((string)$base, '/').$rel;
    $real = realpath($abs);
    return ($real !== false && is_dir($real)) ? $real : null;
}

function should_skip($path, $needles){
    $p = str_replace('\\','/',$path);
    foreach ($needles as $n){
        if ($n !== '' && strpos($p, $n) !== false) return true;
    }
    return false;
}

function ends_with_php_close_tag_only($code){
    if (!preg_match('/^\s*<\?php/i', $code)) return null;
    $trim = rtrim($code);
    if ($trim !== '' && substr($trim, -2) === '?>'){
        $pos = strrpos($trim, '?>'); if ($pos === false) return null;
        $pre = substr($trim, 0, $pos);
        return substr_count($pre, "\n") + 1;
    }
    return null;
}

/* -------- brace 扫描（仅 PHP） -------- */
function php_brace_scan($code){
    $tokens = token_get_all($code);
    $issues = [];
    $stack  = [];
    $line   = 1;
    $in_here = false;

    foreach ($tokens as $tk){
        if (is_array($tk)){
            $type = $tk[0];
            $text = $tk[1];

            // 忽略注释/字符串/inline HTML/heredoc 内容
            if ($type === T_COMMENT || $type === T_DOC_COMMENT ||
                $type === T_CONSTANT_ENCAPSED_STRING || $type === T_ENCAPSED_AND_WHITESPACE ||
                $type === T_INLINE_HTML){
                $line += substr_count($text, "\n");
                continue;
            }
            if ($type === T_START_HEREDOC){ $in_here = true;  $line += substr_count($text, "\n"); continue; }
            if ($type === T_END_HEREDOC){   $in_here = false; $line += substr_count($text, "\n"); continue; }
            if ($in_here){ $line += substr_count($text, "\n"); continue; }

            $line += substr_count($text, "\n");
            continue;
        }
        // 单字符
        $ch = $tk;
        if ($ch === '{'){
            $stack[] = $line;
        } elseif ($ch === '}'){
            if (!$stack){
                $issues[] = ['line'=>$line,'type'=>'extra_close','msg'=>"第 {$line} 行存在多余的 '}'（无对应的 '{'）"];
            } else {
                array_pop($stack);
            }
        }
    }

    foreach ($stack as $openLine){
        $issues[] = ['line'=>$openLine,'type'=>'missing_close','msg'=>"第 {$openLine} 行的 '{' 缺少匹配的 '}'"];
    }
    return $issues;
}

/* -------- 递归扫描 -------- */
function run_scan($absDirs, $exts, $ignore){
    $out = [];
    foreach ($absDirs as $base){
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fi){
            $path = $fi->getPathname();
            if ($fi->isDir()){
                if (should_skip($path.'/', $ignore)) continue;
                continue;
            }
            if (should_skip($path, $ignore)) continue;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) continue;

            $code = @file_get_contents($path);
            if ($code === false){
                $out[] = ['file'=>$path,'issues'=>[['line'=>0,'type'=>'io_error','msg'=>'无法读取文件内容']],'close_tag_line'=>null];
                continue;
            }
            $issues = php_brace_scan($code);
            $close  = ends_with_php_close_tag_only($code);
            $out[]  = ['file'=>$path,'issues'=>$issues,'close_tag_line'=>$close];
        }
    }
    return $out;
}

/* -------- 自检 -------- */
if (isset($_GET['selftest']) && $_GET['selftest']=='1'){
    @header('Content-Type: text/plain; charset=utf-8');
    $lines = @file(__FILE__); $lines = is_array($lines) ? count($lines) : 0;
    $sha   = @hash_file('sha256', __FILE__) ?: 'n/a';
    echo "SELFTEST v{$VERSION}\n";
    echo "path   : ".__FILE__."\n";
    echo "lines  : {$lines}\n";
    echo "sha256 : {$sha}\n";
    echo "base   : ".($GLOBALS['HQHTML'] ?: '(not found)')."\n";
    echo "func   : php_brace_scan=". (function_exists('php_brace_scan')?'OK':'MISSING') .
         ", run_scan=".(function_exists('run_scan')?'OK':'MISSING') .
         ", token_get_all=".(function_exists('token_get_all')?'OK':'MISSING') . "\n";
    exit;
}

/* -------- 读/写目录配置（相对 /hq/hq_html） -------- */
if (isset($_GET['reset']) && $_GET['reset']=='1'){ @unlink($CONFIG); }

$saved = cfg_load($CONFIG);
$dirs_rel = [];
if (isset($saved['dirs_rel']) && is_array($saved['dirs_rel'])) $dirs_rel = $saved['dirs_rel'];

/* 用户点击按钮：保存并扫描 */
if (isset($_POST['dirs_rel'])){
    $rows = preg_split('/\R/u', (string)$_POST['dirs_rel']);
    $new_rel = [];
    foreach ($rows as $r){
        $r = trim($r);
        if ($r === '' || starts_with($r, '#') || starts_with($r, '//')) continue;
        if ($r[0] !== '/') $r = '/'.$r;
        $new_rel[] = $r;
    }
    if ($new_rel){
        $dirs_rel = $new_rel;
        cfg_save($CONFIG, ['dirs_rel'=>$dirs_rel]);
    }
}

/* 解析为绝对路径，准备扫描 */
$absDirs = [];
$invalid_rel = [];
foreach ($dirs_rel as $rel){
    $abs = rel_to_abs($rel, $HQHTML);
    if ($abs) $absDirs[] = $abs; else $invalid_rel[] = $rel;
}

/* -------- JSON 输出请求（可用于调试） -------- */
$want_json = (isset($_GET['format']) && strtolower((string)$_GET['format'])==='json');

/* -------- 扫描或展示表单 -------- */
$did_scan = false;
$results = [];
$summary = [
    'version' => $VERSION,
    'base_root' => $HQHTML,
    'config' => $CONFIG,
    'dirs_rel' => $dirs_rel,
    'dirs_abs' => $absDirs,
    'invalid_rel' => $invalid_rel,
    'total_files' => 0,
    'files_with_brace_issues' => 0,
    'files_with_trailing_php_close_tag' => 0,
    'brace_issue_count' => 0,
    'elapsed_sec' => 0.0,
];

if ($absDirs){
    $start = microtime(true);
    $results = run_scan($absDirs, $EXTS, $IGNORE);
    $elapsed = microtime(true) - $start;
    $did_scan = true;

    $total = count($results);
    $filesIssues = 0; $filesClose = 0; $issueCount = 0;
    foreach ($results as $r){
        if (!empty($r['issues'])){ $filesIssues++; $issueCount += count($r['issues']); }
        if ($r['close_tag_line'] !== null) $filesClose++;
    }

    $summary['total_files'] = $total;
    $summary['files_with_brace_issues'] = $filesIssues;
    $summary['files_with_trailing_php_close_tag'] = $filesClose;
    $summary['brace_issue_count'] = $issueCount;
    $summary['elapsed_sec'] = round($elapsed, 3);
}

/* -------- 若要求 JSON -------- */
if ($want_json){
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'summary'=>$summary,'results'=>$results], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

/* -------- HTML 输出（总是显示状态 + 表单；若扫描则展示结果） -------- */
@header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Brace Checker '.$VERSION.'</title>';
echo '<h3>Brace Checker <small>'.ui_h($VERSION).'</small></h3>';
echo '<div>基准根：<code>'.ui_h($HQHTML ?: '(未找到)').'</code></div>';
echo '<div>配置文件：<code>'.ui_h($CONFIG).'</code></div>';
echo '<div style="margin:8px 0 12px 0">';
echo '已保存目录数：'.count($dirs_rel).'，解析成功：'.count($absDirs).'，无效：'.count($invalid_rel).'<br>';
if ($did_scan){
    echo '扫描文件：'.$summary['total_files'].'；花括号问题文件：'.$summary['files_with_brace_issues'].'；尾部含 ?> 文件：'.$summary['files_with_trailing_php_close_tag'].'；总问题：'.$summary['brace_issue_count'].'；耗时：'.$summary['elapsed_sec'].'s';
} else {
    echo '尚未扫描：请在下方输入目录并点击“开始扫描并保存”。';
}
echo '</div>';

if (!empty($invalid_rel)){
    echo '<div style="color:#f88">无效目录（相对 /hq/hq_html）：<code>'.ui_h(implode(', ', $invalid_rel)).'</code></div>';
}

/* 表单（始终显示，方便调整） */
echo '<h4 style="margin-top:12px">扫描目录（每行一个，相对 /hq/hq_html）</h4>';
$prefill = ui_h(implode("\n", $dirs_rel));
echo '<form method="post"><textarea name="dirs_rel" style="width:100%;max-width:900px;height:140px;">'.$prefill.'</textarea><br>';
echo '<button type="submit">开始扫描并保存</button> ';
echo '<a href="?reset=1">清空记忆</a> · <a href="?selftest=1">自检</a> · <a href="?format=json">JSON</a>';
echo '</form>';

/* 若已扫描，展示结果简表 */
if ($did_scan){
    echo '<h4 style="margin-top:16px">扫描结果</h4>';
    echo '<pre style="background:#111;padding:10px;color:#ddd;max-width:1200px;overflow:auto">';
    foreach ($results as $r){
        $flag = (!empty($r['issues']) || $r['close_tag_line'] !== null) ? '[!]' : '[OK]';
        echo $flag.' '.$r['file']."\n";
        if ($r['close_tag_line'] !== null){
            echo "  - tail '?>' at line ".$r['close_tag_line']."\n";
        }
        if (!empty($r['issues'])){
            foreach ($r['issues'] as $iss){
                echo '  - '.$iss['msg']."\n";
            }
        }
    }
    echo '</pre>';
}
