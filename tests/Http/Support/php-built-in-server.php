<?php

declare(strict_types=1);

/**
 * Shared php -S helpers for real-HTTP Pest tests (group http-server).
 *
 * Prefers setsid + array-form proc_open so tearDown can kill the process group.
 */

/**
 * @param  array<string, string>  $extraEnvironment
 * @return resource
 */
function startPhpBuiltInServer(
    int $port,
    string $server,
    string $root,
    string $trustProxies,
    array $extraEnvironment = [],
) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir()."/http-basics-{$port}.log", 'w'],
        2 => ['file', sys_get_temp_dir()."/http-basics-{$port}.err", 'w'],
    ];

    $setsid = null;
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
        if (is_executable($candidate)) {
            $setsid = $candidate;
            break;
        }
    }

    $environment = [];
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $environment[$key] = $value;
        }
    }
    $environment['HTTP_BASICS_TRUST_PROXIES'] = $trustProxies;
    foreach ($extraEnvironment as $key => $value) {
        $environment[$key] = $value;
    }

    if ($setsid !== null) {
        $command = [$setsid, PHP_BINARY, '-S', "127.0.0.1:{$port}", $server];
        $proc = proc_open($command, $descriptors, $pipes, $root, $environment);
    } else {
        // exec replaces the shell so proc_terminate hits php -S, not sh.
        $exported = '';
        foreach ($extraEnvironment as $key => $value) {
            $exported .= sprintf(' %s=%s', $key, escapeshellarg($value));
        }
        $command = sprintf(
            'exec env HTTP_BASICS_TRUST_PROXIES=%s%s %s -S 127.0.0.1:%d %s',
            escapeshellarg($trustProxies),
            $exported,
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($server),
        );
        $proc = proc_open($command, $descriptors, $pipes, $root);
    }

    if (! is_resource($proc)) {
        throw new RuntimeException("Failed to start php -S on {$port}");
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);

            return $proc;
        }
        usleep(100_000);
    }

    stopPhpBuiltInServer($proc);

    throw new RuntimeException("php -S on {$port} did not become ready");
}

/**
 * @param  resource  $proc
 */
function stopPhpBuiltInServer($proc): void
{
    if (! is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);
    $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;

    if ($pid > 0 && function_exists('posix_kill')) {
        // Negative PID = process group when started under setsid; also kill the PID.
        @posix_kill(-$pid, SIGTERM);
        @posix_kill($pid, SIGTERM);
        usleep(50_000);
        @posix_kill(-$pid, SIGKILL);
        @posix_kill($pid, SIGKILL);
    }

    proc_terminate($proc);
    proc_close($proc);
}

/**
 * @param  array<string, string>  $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function realHttpRequest(
    int $port,
    string $path,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl failed: '.curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $parts = explode("\r\n\r\n", $raw, 2);
    $headerBlob = $parts[0];
    $responseBody = $parts[1] ?? '';
    $parsed = [];
    foreach (explode("\r\n", $headerBlob) as $line) {
        if (str_contains($line, ':')) {
            [$n, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($n))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $responseBody];
}

/**
 * Fire two HTTP requests in parallel via curl_multi.
 *
 * @param  array{port: int, path: string, method?: string, headers?: array<string, string>, body?: string|null}  $first
 * @param  array{port: int, path: string, method?: string, headers?: array<string, string>, body?: string|null}  $second
 * @return array{0: array{status: int, headers: array<string, string>, body: string}, 1: array{status: int, headers: array<string, string>, body: string}}
 */
function realHttpRequestPool(array $first, array $second): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ([$first, $second] as $index => $spec) {
        $ch = curl_init("http://127.0.0.1:{$spec['port']}{$spec['path']}");
        $headerLines = [];
        foreach ($spec['headers'] ?? [] as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $spec['method'] ?? 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if (array_key_exists('body', $spec) && $spec['body'] !== null) {
            $options[CURLOPT_POSTFIELDS] = $spec['body'];
        }
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($multi, $ch);
        $handles[$index] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $ch) {
        $raw = curl_multi_getcontent($ch);
        if ($raw === false || $raw === null) {
            throw new RuntimeException('curl_multi failed: '.curl_error($ch));
        }
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $parts = explode("\r\n\r\n", $raw, 2);
        $headerBlob = $parts[0];
        $responseBody = $parts[1] ?? '';
        $parsed = [];
        foreach (explode("\r\n", $headerBlob) as $line) {
            if (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($n))] = trim($v);
            }
        }
        $results[$index] = ['status' => $httpStatus, 'headers' => $parsed, 'body' => $responseBody];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return [$results[0], $results[1]];
}
