<?php
/* 棂记 App 桥 · 单文件版（PHP 7.0+，零依赖，不用数据库）
 * 放到网站目录的 /linji/bridge.php，App 里填服务器地址 + 同一个令牌即可。
 * 令牌改下面 $TOKEN；数据存在同目录 bridge-data/（要可写）。
 */

$TOKEN='CHANGE-ME';
$DIR   = __DIR__ . '/bridge-data';

function jread($f, $d) {
    if (!is_file($f)) return $d;
    $s = @file_get_contents($f);
    if ($s === false || $s === '') return $d;
    $v = json_decode($s, true);
    return is_array($v) ? $v : $d;
}
function jwrite($f, $v) {
    $t = $f . '.tmp';
    @file_put_contents($t, json_encode($v, JSON_UNESCAPED_UNICODE));
    @rename($t, $f);
}
function hdr_token() {
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'x-linji-token') return trim($v);
        }
    }
    if (isset($_SERVER['HTTP_X_LINJI_TOKEN'])) return trim($_SERVER['HTTP_X_LINJI_TOKEN']);
    return '';
}
function fmt($ms) {
    if (!$ms) return '-';
    return date('Y-m-d H:i', (int)($ms / 1000));
}

if (!is_dir($DIR)) { @mkdir($DIR, 0755, true); }

$STATE = $DIR . '/state.json';
$CMDS  = $DIR . '/cmds.json';
$ACKS  = $DIR . '/acks.json';

/* ---------- 1) App 上报（POST，带 X-Linji-Token） ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['admin'])) {
    if (hdr_token() !== $TOKEN) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        @file_put_contents($DIR . '/reject.log', date('c') . " " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?') . " bad-token\n", FILE_APPEND);
        echo json_encode(array('error' => 'bad token'));
        exit;
    }
    $body = file_get_contents('php://input');
    $in = json_decode($body, true);
    if (!is_array($in)) $in = array();
    $now = time() * 1000;
    jwrite($STATE, array(
        'ts' => $now,
        'ts_str' => fmt($now),
        'device' => isset($in['device']) ? $in['device'] : '?',
        'version' => isset($in['version']) ? $in['version'] : '?',
        'status' => isset($in['status']) ? $in['status'] : array(),
    ));
    $acks = jread($ACKS, array());
    if (isset($in['acks']) && is_array($in['acks'])) {
        foreach ($in['acks'] as $a) {
            $acks[] = array('at' => $now, 'id' => isset($a['id']) ? $a['id'] : '', 'ok' => isset($a['ok']) ? $a['ok'] : null, 'msg' => isset($a['msg']) ? $a['msg'] : '');
        }
        if (count($acks) > 200) $acks = array_slice($acks, -200);
        jwrite($ACKS, $acks);
    }
    $list = jread($CMDS, array());
    $out = array();
    foreach ($list as $i => $c) {
        if (empty($c['delivered'])) {
            $o = array('id' => $c['id'], 'cmd' => $c['cmd']);
            foreach (array('title', 'text', 'what', 'key', 'value', 'minutes', 'tid', 'done') as $k) {
                if (isset($c[$k])) $o[$k] = $c[$k];
            }
            $out[] = $o;
            $list[$i]['delivered'] = $now;
        }
    }
    if (count($out)) jwrite($CMDS, $list);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('cmds' => $out), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 2) 网页控制台（GET ?admin=令牌） ---------- */
$admin = isset($_GET['admin']) ? $_GET['admin'] : '';
if ($admin === '' || $admin !== $TOKEN) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(401);
    echo "need ?admin=TOKEN\n";
    exit;
}

function enqueue($cmd, $extra) {
    global $CMDS;
    $list = jread($CMDS, array());
    $item = array_merge(array('id' => 'c' . (time() * 1000), 'at' => time() * 1000, 'delivered' => null, 'cmd' => $cmd), $extra);
    $list[] = $item;
    if (count($list) > 50) $list = array_slice($list, -50);
    jwrite($CMDS, $list);
}

$msg = '';
if (isset($_POST['act'])) {
    $act = $_POST['act'];
    if ($act === 'todo') enqueue('todo', array('text' => isset($_POST['text']) ? trim($_POST['text']) : ''));
    if ($act === 'notify') enqueue('notify', array('title' => '棂记', 'text' => isset($_POST['text']) ? trim($_POST['text']) : ''));
    if ($act === 'lock') enqueue('lock', array('minutes' => (int)(isset($_POST['minutes']) ? $_POST['minutes'] : 10)));
    if ($act === 'set') enqueue('set', array('key' => isset($_POST['key']) ? $_POST['key'] : '', 'value' => isset($_POST['value']) ? trim($_POST['value']) : ''));
    $msg = '已加入指令队列，手机下次上报（≤30 秒）就会执行。';
}

$st = jread($STATE, array());
$acks = jread($ACKS, array());
$cmds = jread($CMDS, array());
$s = isset($st['status']) ? $st['status'] : array();
$sleeps = isset($s['sleeps']) && is_array($s['sleeps']) ? $s['sleeps'] : array();
$todos = isset($s['todos']) && is_array($s['todos']) ? $s['todos'] : array();
$cfg = isset($s['cfg']) && is_array($s['cfg']) ? $s['cfg'] : array();
$tok = htmlspecialchars($TOKEN, ENT_QUOTES);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="zh"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>棂记桥 · 控制台</title>
<style>
body{font-family:-apple-system,"PingFang SC",sans-serif;background:#f2f2f7;margin:0;padding:16px;color:#1c1c1e}
.card{background:#fff;border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
h2{font-size:15px;margin:0 0 10px;color:#8e8e93;font-weight:600}
table{width:100%;border-collapse:collapse;font-size:14px}
td{padding:6px 0;border-bottom:1px solid #f0f0f2}
input,button{font-size:15px;padding:9px 10px;border-radius:10px;border:1px solid #e5e5ea;box-sizing:border-box}
input{width:100%;margin:6px 0}
button{background:#d81e2c;color:#fff;border:0;font-weight:600;width:100%;margin-top:6px}
.ok{color:#1e9e5a}.sub{color:#8e8e93;font-size:12px}
.strike{text-decoration:line-through;color:#8e8e93}
</style></head><body>
<div class="card"><h2>手机状态</h2>
<?php if (!$st) { echo '<div class="sub">手机还没上报过（App 开发者模式里打开 App 桥 + 填令牌）。</div>'; } else { ?>
<table>
<tr><td>设备 / 版本</td><td><?php echo htmlspecialchars((string)$st['device']) . ' · ' . htmlspecialchars((string)$st['version']); ?></td></tr>
<tr><td>上次上报</td><td><?php echo htmlspecialchars((string)$st['ts_str']); ?></td></tr>
<tr><td>电量</td><td><?php echo isset($s['battery']) ? (int)$s['battery'] . '%' : '-'; ?><?php echo !empty($s['charging']) ? ' 充电中' : ''; ?></td></tr>
<tr><td>睡了吗</td><td><?php echo !empty($s['sleep_start']) ? '在睡（' . fmt($s['sleep_start']) . ' 躺下）' : '没在睡'; ?></td></tr>
<tr><td>上次一觉</td><td><?php echo isset($s['last_min']) ? (int)$s['last_min'] . ' 分钟（' . fmt($s['last_start']) . ' → ' . fmt($s['last_end']) . '）' : '无'; ?></td></tr>
<tr><td>近7次平均</td><td><?php echo isset($s['sleep_avg7']) ? (int)$s['sleep_avg7'] . ' 分钟' : '-'; ?></td></tr>
<tr><td>锁机时间 / 起床</td><td><?php echo htmlspecialchars(isset($cfg['lock_time']) ? $cfg['lock_time'] : '-') . ' / ' . htmlspecialchars(isset($cfg['wake_time']) ? $cfg['wake_time'] : '-'); ?></td></tr>
</table><?php } ?></div>

<div class="card"><h2>待办（手机点一下 = 完成）</h2>
<?php if (!$todos) echo '<div class="sub">还没有待办</div>';
foreach ($todos as $t) { echo '<div class="' . (!empty($t['done']) ? 'strike' : '') . '">' . (!empty($t['done']) ? '✓ ' : '○ ') . htmlspecialchars($t['text']) . '</div>'; } ?>
<form method="post"><input name="text" placeholder="加一条待办，例如：买护手霜">
<input type="hidden" name="act" value="todo"><button>加到手机</button></form></div>

<div class="card"><h2>发通知 / 锁机 / 改设置</h2>
<form method="post"><input name="text" placeholder="通知内容，例如：该睡了">
<input type="hidden" name="act" value="notify"><button>发通知</button></form>
<form method="post"><input name="minutes" placeholder="锁机分钟数，如 10" value="10">
<input type="hidden" name="act" value="lock"><button>立刻锁机</button></form>
<form method="post"><input name="key" placeholder="key：lock_time / wake_time / early_time / gate / hold_sec">
<input name="value" placeholder="value：22:50 / 07:00 / 04:00 / true / 10">
<input type="hidden" name="act" value="set"><button>改设置</button></form>
<div class="sub"><?php echo htmlspecialchars($msg); ?></div></div>

<div class="card"><h2>最近睡眠记录</h2>
<?php if (!$sleeps) echo '<div class="sub">暂无</div>';
foreach ($sleeps as $x) {
    echo '<div>' . fmt($x['start']) . ' → ' . fmt($x['end']) . '  ' . (int)$x['min'] . ' 分'
        . (!empty($x['broke']) ? ' (提前解锁)' : '') . (!empty($x['auto']) ? ' (自动补记)' : '') . '</div>';
} ?></div>

<div class="card"><h2>指令回执</h2>
<?php $n = 0; foreach (array_reverse($acks) as $a) { if ($n++ > 14) break; echo '<div class="sub">' . fmt($a['at']) . ' ' . htmlspecialchars($a['id']) . ' → ' . htmlspecialchars((string)$a['msg']) . (!empty($a['ok']) ? ' <span class="ok">ok</span>' : '') . '</div>'; } ?></div>

<div class="card"><h2>等待手机取走的指令</h2>
<?php $n = 0; foreach ($cmds as $c) { if (!empty($c['delivered'])) continue; if ($n++ > 9) break; echo '<div class="sub">' . htmlspecialchars($c['cmd']) . ' ' . fmt($c['at']) . '</div>'; }
if ($n === 0) echo '<div class="sub">空</div>'; ?></div>
<div class="sub">本页地址：?admin=<?php echo $tok; ?>（别外传）</div>
</body></html>
