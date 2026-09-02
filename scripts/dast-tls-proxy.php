<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * A TLS terminator in front of `php -S`, for scripts/dast.sh and nothing else.
 *
 * ## Why it has to exist
 *
 * Two of the application's protections are conditional on the request having
 * arrived over HTTPS, and both are in public/index.php:
 *
 *   - the `Strict-Transport-Security` header (~l.97), and
 *   - `session.cookie_secure` (~l.155).
 *
 * scripts/e2e.sh serves plain HTTP, so a scan run against it would report
 * "HSTS header not set" and "cookie without the Secure flag". Both findings
 * would be FALSE, and both would be about code that is correct. The tempting
 * fix is an alert filter silencing the two rules — and that is exactly how a
 * report stops being read: two rules muted for a harness defect, and the day
 * one of them fires for real nobody notices. So the harness is fixed instead,
 * and both rules stay armed.
 *
 * PHP's built-in server speaks no TLS, and this harness deliberately depends on
 * nothing but `php` and `npm` — the same constraint scripts/e2e.sh lives under.
 * So the TLS half is a PHP script, exactly as the coverage prepend and the
 * router already are.
 *
 * This is TEST HARNESS CODE. Nothing here ships in a release, and no deployment
 * ever runs it.
 *
 * ## What it does to the request
 *
 * It sets `X-Forwarded-Proto: https`, which scripts/dast-https-prepend.php
 * turns into `$_SERVER['HTTPS']` on the backend so the two protections above
 * engage. Any copy of that header the CLIENT sent is stripped first: a
 * terminator that forwards a client-supplied `X-Forwarded-Proto` is itself the
 * vulnerability, and here it would additionally teach the scan that the header
 * is trustworthy when nothing in the application says it is.
 *
 * Nothing else is rewritten. `Host` in particular is left alone, so every
 * absolute URL the application builds stays reachable.
 *
 * ## Why the response is buffered instead of piped
 *
 * `php -S` sends no `Content-Length` for anything PHP generates: it answers
 * `Connection: close` and lets EOF frame the body. That is legal HTTP/1.1, and
 * it has two consequences a scan cannot live with.
 *
 * A connection that carries one response means a TLS handshake per asset, which
 * is slow enough to change behaviour rather than merely timing — the page
 * becomes interactive before its scripts have arrived. And Chromium treats a
 * response framed only by connection close as cancelled when it is a download.
 *
 * So each response is read whole, measured, and re-sent with a real
 * `Content-Length` on a reusable connection. The bytes the scanner sees are
 * still the bytes the application produced; only the framing is ours.
 *
 * Usage (scripts/dast.sh does this, never a human):
 *   php scripts/dast-tls-proxy.php --listen=127.0.0.1:8443 \
 *       --backend=127.0.0.1:8080 --cert=/path/to/server.pem
 */

const DAST_TLS_READ_CHUNK = 65536;
const DAST_TLS_HEAD_LIMIT = 262144;
const DAST_TLS_BACKEND_TIMEOUT = 120;

/**
 * A ceiling, not a target: a client that kept one connection for ever would
 * pin a forked child for ever along with it.
 */
const DAST_TLS_MAX_REQUESTS = 200;

/**
 * @param  list<string> $argv
 * @return array<string, string>
 */
function dastTlsParseArguments(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches)) {
            fwrite(STDERR, "dast-tls-proxy: unrecognised argument '{$argument}'.\n");
            exit(1);
        }
        $options[$matches[1]] = $matches[2];
    }

    foreach (['listen', 'backend', 'cert'] as $required) {
        if (($options[$required] ?? '') === '') {
            fwrite(STDERR, "dast-tls-proxy: --{$required} is required.\n");
            exit(1);
        }
    }

    return $options;
}

/**
 * Read until the buffer holds at least $minimum bytes.
 *
 * The buffer is carried by reference across calls because an HTTP connection is
 * a sequence of framed messages and reading "until the headers end" always
 * overshoots into whatever follows. Keeping the overshoot is what makes
 * keep-alive possible at all: without it the first byte of a request body — or
 * of the next request — is read and thrown away.
 *
 * @param string   $buffer carried by reference between calls
 * @param resource $stream
 */
function dastTlsFill(string &$buffer, $stream, int $minimum): bool
{
    while (strlen($buffer) < $minimum) {
        $chunk = fread($stream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $buffer .= $chunk;
    }

    return true;
}

/**
 * Read one HTTP header block, terminator included, leaving the remainder in the
 * buffer.
 *
 * Returns null when the peer closed before sending a complete head, which is
 * ordinary rather than exceptional: a browser opening a connection it never
 * uses, a keep-alive connection reaching its idle end, a scanner probing the
 * port.
 *
 * @param resource $stream
 */
function dastTlsReadHead(string &$buffer, $stream): ?string
{
    while (($position = strpos($buffer, "\r\n\r\n")) === false) {
        if (strlen($buffer) > DAST_TLS_HEAD_LIMIT) {
            return null;
        }
        $chunk = fread($stream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }

    $head = substr($buffer, 0, $position + 4);
    $buffer = substr($buffer, $position + 4);

    return $head;
}

/** Case-insensitive header lookup over a raw header block. */
function dastTlsHeader(string $head, string $name): ?string
{
    foreach (explode("\r\n", $head) as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        if (strcasecmp(trim(substr($line, 0, $colon)), $name) === 0) {
            return trim(substr($line, $colon + 1));
        }
    }

    return null;
}

/**
 * Drop every occurrence of a set of headers from a raw header block.
 *
 * @param list<string> $names
 */
function dastTlsStripHeaders(string $head, array $names): string
{
    $kept = [];
    foreach (explode("\r\n", rtrim($head, "\r\n")) as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $header = trim(substr($line, 0, $colon));
            foreach ($names as $name) {
                if (strcasecmp($header, $name) === 0) {
                    continue 2;
                }
            }
        }
        $kept[] = $line;
    }

    return implode("\r\n", $kept);
}

/**
 * Read a request body by whichever framing the request declared, verbatim.
 *
 * Chunked is handled as well as Content-Length because the admin scenario
 * uploads an Excel file, and a terminator that mishandled one framing would
 * truncate a request the scan would then report as a server error of the
 * application's making.
 *
 * @param resource $stream
 */
function dastTlsReadBody(string &$buffer, $stream, string $head): ?string
{
    $transferEncoding = dastTlsHeader($head, 'Transfer-Encoding');
    if ($transferEncoding !== null && stripos($transferEncoding, 'chunked') !== false) {
        $body = '';
        while (true) {
            while (($lineEnd = strpos($buffer, "\r\n")) === false) {
                if (!dastTlsFill($buffer, $stream, strlen($buffer) + 1)) {
                    return null;
                }
            }
            $size = (int) hexdec(trim(explode(';', substr($buffer, 0, $lineEnd))[0]));
            $needed = $lineEnd + 2 + $size + 2;
            if (!dastTlsFill($buffer, $stream, $needed)) {
                return null;
            }
            $body .= substr($buffer, 0, $needed);
            $buffer = substr($buffer, $needed);
            if ($size === 0) {
                return $body;
            }
        }
    }

    $contentLength = (int) (dastTlsHeader($head, 'Content-Length') ?? '0');
    if ($contentLength <= 0) {
        return '';
    }
    if (!dastTlsFill($buffer, $stream, $contentLength)) {
        return null;
    }
    $body = substr($buffer, 0, $contentLength);
    $buffer = substr($buffer, $contentLength);

    return $body;
}

/**
 * Send one request to `php -S` and read the whole answer back.
 *
 * A fresh backend connection per request, because the built-in server answers
 * `Connection: close` and has no keep-alive of its own — so the response is
 * simply everything it writes before EOF.
 *
 * @return array{0: string, 1: string}|null head and body, or null on failure
 */
function dastTlsExchange(string $backend, string $head, string $body): ?array
{
    $upstream = @stream_socket_client(
        'tcp://' . $backend,
        $errorNumber,
        $errorString,
        DAST_TLS_BACKEND_TIMEOUT
    );
    if ($upstream === false) {
        return null;
    }
    stream_set_timeout($upstream, DAST_TLS_BACKEND_TIMEOUT);

    if (@fwrite($upstream, $head . $body) === false) {
        fclose($upstream);
        return null;
    }

    $raw = '';
    while (!feof($upstream)) {
        $chunk = fread($upstream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    fclose($upstream);

    $position = strpos($raw, "\r\n\r\n");
    if ($position === false) {
        return null;
    }

    return [substr($raw, 0, $position + 4), substr($raw, $position + 4)];
}

/**
 * Rebuild the response head so the client gets a properly framed, reusable
 * connection. See the file docblock for why this is worth doing at all.
 */
function dastTlsReframe(string $head, int $length, bool $keepAlive, bool $omitBody): string
{
    $head = dastTlsStripHeaders($head, ['Content-Length', 'Transfer-Encoding', 'Connection', 'Keep-Alive']);
    $head .= "\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close');
    if (!$omitBody) {
        $head .= "\r\nContent-Length: {$length}";
    }

    return $head . "\r\n\r\n";
}

/**
 * Relay requests on one client connection until either side is done.
 *
 * Runs in a forked child, so a failure here costs one connection and never the
 * listener.
 *
 * @param resource $client
 */
function dastTlsHandleConnection($client, string $backend): void
{
    stream_set_timeout($client, DAST_TLS_BACKEND_TIMEOUT);
    $buffer = '';

    for ($served = 0; $served < DAST_TLS_MAX_REQUESTS; $served++) {
        $head = dastTlsReadHead($buffer, $client);
        if ($head === null) {
            return;
        }

        $requestLine = strtok($head, "\r\n");
        $method = strtoupper((string) strtok($requestLine === false ? '' : $requestLine, ' '));

        $body = dastTlsReadBody($buffer, $client, $head);
        if ($body === null) {
            return;
        }

        // The client's own copy is dropped before ours is set. A terminator
        // that forwards a client-supplied X-Forwarded-Proto is the
        // vulnerability, not the fix for one.
        $forwarded = dastTlsStripHeaders($head, ['X-Forwarded-Proto'])
            . "\r\nX-Forwarded-Proto: https\r\n\r\n";

        $response = dastTlsExchange($backend, $forwarded, $body);
        if ($response === null) {
            // The application server is gone (teardown, a crash). Answer
            // something well formed rather than a bare connection reset, so the
            // scanner records a 502 rather than an unexplained failure.
            @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            return;
        }
        [$responseHead, $responseBody] = $response;

        // A HEAD answer, a 204 and a 304 carry no body by definition, and
        // declaring a length for them would be a framing error of our own
        // making.
        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHead, $matches) === 1) {
            $status = (int) $matches[1];
        }
        $omitBody = $method === 'HEAD' || $status === 204 || $status === 304;

        $clientConnection = (string) (dastTlsHeader($head, 'Connection') ?? '');
        $keepAlive = stripos($clientConnection, 'close') === false
            && str_contains($requestLine === false ? '' : $requestLine, 'HTTP/1.1')
            // The LAST response this child serves says so, rather than the
            // connection closing silently after it. A client that reused a
            // connection it had no reason to believe was finished loses
            // whatever it had already written into it, and a browser retries a
            // lost GET but not necessarily a lost POST — which surfaces as one
            // request that simply never gets an answer.
            && $served < DAST_TLS_MAX_REQUESTS - 1;

        $out = dastTlsReframe($responseHead, strlen($responseBody), $keepAlive, $omitBody);
        if (@fwrite($client, $out) === false) {
            return;
        }
        if (!$omitBody && $responseBody !== '' && @fwrite($client, $responseBody) === false) {
            return;
        }

        if (!$keepAlive) {
            return;
        }
    }
}

$options = dastTlsParseArguments($argv);

foreach (['pcntl', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "dast-tls-proxy: the '{$extension}' PHP extension is required.\n");
        exit(1);
    }
}

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $options['cert'],
        'allow_self_signed' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        // No ALPN advertised, so a client that would otherwise negotiate HTTP/2
        // falls back to HTTP/1.1 — which is what the relay above speaks, and
        // what ZAP records most faithfully.
        'alpn_protocols' => '',
        'disable_compression' => true,
    ],
]);

// Bound as plain TCP with the TLS options attached, and the handshake deferred
// to the child below, for two reasons. A `tls://` server performs the handshake
// inside stream_socket_accept(), which blocks the accept loop on whichever
// client is slowest; and PHP's fclose() on an already-negotiated TLS stream
// performs an SSL shutdown, so the parent closing its own copy after forking
// would tear down the connection the child is still serving. Closing a plain
// TCP socket in the parent is a refcount decrement, which is what the fork
// pattern assumes.
$server = @stream_socket_server(
    'tcp://' . $options['listen'],
    $errorNumber,
    $errorString,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if ($server === false) {
    fwrite(STDERR, "dast-tls-proxy: cannot listen on {$options['listen']}: {$errorString}\n");
    exit(1);
}

// SIG_IGN on SIGCHLD has the kernel reap children itself. A scan is tens of
// thousands of connections, and a zombie per connection would exhaust the
// process table long before the run ends.
pcntl_signal(SIGCHLD, SIG_IGN);
pcntl_signal(SIGTERM, static function (): void {
    exit(0);
});
pcntl_signal(SIGINT, static function (): void {
    exit(0);
});

fwrite(STDERR, "dast-tls-proxy: listening on {$options['listen']}, forwarding to {$options['backend']}.\n");

while (true) {
    pcntl_signal_dispatch();

    // A failed accept — a client that gave up, an idle timeout — is an ordinary
    // event during a security scan, not something to stop for.
    $client = @stream_socket_accept($server, 5);
    if ($client === false) {
        continue;
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "dast-tls-proxy: fork failed, handling this connection inline.\n");
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
            dastTlsHandleConnection($client, $options['backend']);
        }
        fclose($client);
        continue;
    }

    if ($pid === 0) {
        fclose($server);
        // A failed handshake is ordinary during a security scan (a plain HTTP
        // probe against the TLS port, a client that changed its mind); it costs
        // this one connection and nothing else.
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
            dastTlsHandleConnection($client, $options['backend']);
        }
        fclose($client);
        exit(0);
    }

    fclose($client);
}
