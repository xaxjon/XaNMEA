<?php
declare(strict_types=1);

/**
 * sse.php: Server-Sent Events bridge to the daemon control socket streams.
 *
 *   ?stream=tail&iface=all|name   -> TAIL [iface]
 *   ?stream=state                 -> STREAM
 *
 * Each JSON line from the daemon becomes one SSE `data:` frame. A `:ping`
 * comment is sent on 15s idle so proxies keep the connection alive and
 * client disconnects get detected. Exits when the client goes away.
 */

require __DIR__ . '/lib/common.php';

requireLogin(null, true);

// Release the session lock BEFORE streaming: the default file handler
// holds an exclusive lock for the whole request, which would block every
// other same-session page/API call (ping, stats, navigation) for as long
// as this stream lives.
session_write_close();

$stream = (string)($_GET['stream'] ?? '');
if ($stream === 'tail') {
    $iface = trim((string)($_GET['iface'] ?? 'all'));
    if ($iface === '' || strcasecmp($iface, 'all') === 0) {
        $cmd = 'TAIL';
    } elseif (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $iface)) {
        $cmd = 'TAIL ' . $iface;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad iface name']);
        exit;
    }
} elseif ($stream === 'state') {
    $cmd = 'STREAM';
} else {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'unknown stream']);
    exit;
}

// No output buffering, no proxy buffering, no time limit.
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 'off');
@set_time_limit(0);
while (ob_get_level() > 0 && @ob_end_clean()) {
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

[$fd, $err] = xan_client()->openStream($cmd);
if ($fd === null) {
    echo "event: error\ndata: " . json_encode(['error' => $err]) . "\n\n";
    flush();
    exit;
}

// Blocking reads with a 15s timeout -> idle ping cadence.
stream_set_blocking($fd, true);
stream_set_timeout($fd, 15);

echo ": connected\n\n";
flush();

while (!connection_aborted()) {
    $line = @fgets($fd);
    if ($line === false) {
        $meta = stream_get_meta_data($fd);
        if (!empty($meta['timed_out'])) {
            echo ": ping\n\n";
            flush();
            continue;
        }
        break; // daemon closed the stream
    }
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    echo 'data: ' . $line . "\n\n";
    flush();
}

fclose($fd);
