#!/usr/bin/env python3
"""Real PHP-FPM + persistent worker -> OTLP -> Collector -> VictoriaMetrics smoke test."""
import concurrent.futures
import collections
import json
import os
from pathlib import Path
import re
import select
import shutil
import socket
import struct
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parent
PHP = os.getenv('PHP_BIN', shutil.which('php') or 'php')
FPM = os.getenv('PHP_FPM_BIN', shutil.which('php-fpm') or 'php-fpm')
PREFIX = 'prototype-' + uuid.uuid4().hex[:10]
ENV = dict(os.environ, MODERNORM_INSTANCE_ID=PREFIX)
COMPOSE = ['docker', 'compose', '-f', str(ROOT / 'compose.yaml')]


def fetch(url):
    with urllib.request.urlopen(url, timeout=3) as response:
        return response.read().decode()


def wait(check, seconds=25):
    deadline = time.monotonic() + seconds
    last = None
    while time.monotonic() < deadline:
        try:
            last = check()
            if last:
                return last
        except (OSError, ValueError, AssertionError) as error:
            last = repr(error)
        time.sleep(0.2)
    raise AssertionError(f'Timed out waiting for condition: {last!r}')


def metric_samples():
    samples = []
    for line in fetch('http://127.0.0.1:18889/metrics').splitlines():
        match = re.match(r'([a-zA-Z_:][a-zA-Z0-9_:]*)\{(.*)\} ([^ ]+)', line)
        if not match:
            continue
        labels = dict(re.findall(r'(\w+)="([^"\\]*)"', match[2]))
        if labels.get('service_instance_id', '').startswith(PREFIX + ':'):
            samples.append((match[1], labels, float(match[3])))
    return samples


def total(samples, name, **labels):
    return sum(value for metric, attrs, value in samples if metric == name and all(attrs.get(k) == v for k, v in labels.items()))


def verify_phase(results):
    expected = collections.Counter()
    for result in results:
        assert result['observerErrors'] == 0, result
        expected[result['pid']] += result['sql']
    def complete():
        samples = metric_samples()
        actual = collections.Counter()
        for name, attrs, value in samples:
            if name == 'modernorm_operations_total' and attrs.get('operation') == 'sql':
                actual[int(attrs['service_instance_id'].rsplit(':', 1)[1])] += value
        return samples if actual == expected else None
    samples = wait(complete)
    sql = sum(r['sql'] for r in results)
    errors = sum(r['sqlErrors'] for r in results)
    assert total(samples, 'modernorm_operations_total', operation='sql', outcome='error') == errors
    assert total(samples, 'modernorm_operations_total', operation='bulk') == sum(r['bulk'] for r in results)
    assert total(samples, 'modernorm_operations_total', operation='flush') == sum(r['flush'] for r in results)
    assert total(samples, 'modernorm_operations_total', operation='batch') == sum(r['batch'] for r in results)
    assert total(samples, 'modernorm_operation_duration_seconds_count', operation='sql') == sql
    assert total(samples, 'modernorm_bulk_rows_total', row_kind='affected') == 72 * len(results)
    query = 'sum(modernorm_operations_total{service_instance_id=~"' + PREFIX + ':.*",operation="sql"})'
    def stored():
        data = json.loads(fetch('http://127.0.0.1:18428/api/v1/query?' + urllib.parse.urlencode({'query': query, 'nocache': '1', 'latency_offset': '1ms'})))
        values = data['data']['result']
        return bool(values) and float(values[0]['value'][1]) == sql
    wait(stored, seconds=45)
    for operation in ('hit', 'miss', 'put', 'invalidation'):
        expected_l2 = sum(r['l2'][operation] for r in results)
        assert expected_l2 > 0, (operation, results)
        assert total(samples, 'modernorm_l2_operations_total', operation=operation, entity='product') == expected_l2
    l2_query = 'sum(modernorm_l2_operations_total{service_instance_id=~"' + PREFIX + ':.*"})'
    def l2_stored():
        data = json.loads(fetch('http://127.0.0.1:18428/api/v1/query?' + urllib.parse.urlencode({'query': l2_query, 'nocache': '1', 'latency_offset': '1ms'})))
        values = data['data']['result']
        return bool(values) and float(values[0]['value'][1]) == sum(sum(r['l2'].values()) for r in results)
    wait(l2_stored, seconds=45)
    for name, attrs, _ in samples:
        if name == 'modernorm_l2_operations_total':
            assert attrs.get('entity') == 'product', attrs
        else:
            assert 'entity' not in attrs, (name, attrs)
    forbidden = {'sql', 'entity_class', 'association', 'request_id', 'job_id', 'exception'}
    assert all(not forbidden.intersection(attrs) for _, attrs, _ in samples)
    print(json.dumps({'scopes': len(results), 'sql': sql, 'sql_errors': errors, 'bulk': 3 * len(results), 'writers': len(expected), 'collector_and_victoriametrics': 'matched'}), flush=True)


def record(kind, body):
    padding = (-len(body)) % 8
    return struct.pack('!BBHHBB', 1, kind, 1, len(body), padding, 0) + body + b'\0' * padding


def encode_length(size):
    return bytes([size]) if size < 128 else struct.pack('!I', size | 0x80000000)


def read_exact(stream, size):
    data = b''
    while len(data) < size:
        chunk = stream.recv(size - len(data))
        if not chunk:
            raise RuntimeError('Unexpected FastCGI EOF')
        data += chunk
    return data


def fpm_request(port):
    params = {'SCRIPT_FILENAME': str(ROOT / 'public/index.php'), 'SCRIPT_NAME': '/index.php', 'REQUEST_METHOD': 'GET', 'REQUEST_URI': '/workload', 'QUERY_STRING': '', 'SERVER_PROTOCOL': 'HTTP/1.1', 'SERVER_NAME': 'localhost', 'SERVER_PORT': '80', 'REMOTE_ADDR': '127.0.0.1', 'GATEWAY_INTERFACE': 'CGI/1.1', 'HTTP_HOST': 'localhost'}
    body = b''
    for key, value in params.items():
        key, value = key.encode(), value.encode()
        body += encode_length(len(key)) + encode_length(len(value)) + key + value
    output, errors = b'', b''
    with socket.create_connection(('127.0.0.1', port), timeout=15) as stream:
        stream.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, body) + record(4, b'') + record(5, b''))
        while True:
            _, kind, _, length, padding, _ = struct.unpack('!BBHHBB', read_exact(stream, 8))
            body = read_exact(stream, length)
            if padding:
                read_exact(stream, padding)
            if kind == 6:
                output += body
            if kind == 7:
                errors += body
            if kind == 3:
                break
    headers, body = output.split(b'\r\n\r\n', 1)
    assert b'Status: 500' not in headers, (body.decode(), errors.decode())
    return json.loads(body)


def worker_job(process):
    process.stdin.write('job\n')
    process.stdin.flush()
    assert select.select([process.stdout], [], [], 15)[0], 'Worker response timed out'
    line = process.stdout.readline()
    assert line, 'Worker exited; inspect worker.log'
    return json.loads(line)


def main():
    assert os.getenv('MODERNORM_TEST_URL'), 'Set MODERNORM_TEST_URL to a dedicated PostgreSQL test database'
    wait(lambda: json.loads(fetch('http://127.0.0.1:13000/api/health'))['database'] == 'ok')
    wait(lambda: fetch('http://127.0.0.1:18889/metrics') is not None)
    runtime = Path(tempfile.mkdtemp(prefix='modernorm-otel-verify-'))
    ENV['MODERNORM_OTEL_CACHE_DIR'] = str(runtime / 'cache')
    worker = fpm = None
    collector_stopped = False
    with (runtime / 'worker.log').open('w') as worker_log, (runtime / 'fpm.log').open('w') as fpm_log:
        try:
            worker = subprocess.Popen([PHP, str(ROOT / 'worker.php'), '--interactive'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=worker_log, text=True, env=ENV)
            before = [worker_job(worker) for _ in range(3)]
            assert all(r['exported'] for r in before)
            assert len({r['pid'] for r in before}) == 1
            assert len({r['sql'] for r in before}) == 1, 'Request statistics were not reset between jobs'
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            config = runtime / 'php-fpm.conf'
            config.write_text(f'[global]\ndaemonize = no\nerror_log = {runtime}/fpm-errors.log\n[prototype]\nlisten = 127.0.0.1:{port}\nlisten.allowed_clients = 127.0.0.1\nuser = {os.getenv("USER", "nobody")}\npm = static\npm.max_children = 4\nclear_env = no\ncatch_workers_output = yes\n')
            fpm = subprocess.Popen([FPM, '-F', '-y', str(config)], stdout=fpm_log, stderr=fpm_log, env=ENV)
            def ready():
                with socket.create_connection(('127.0.0.1', port), timeout=0.2):
                    return True
            wait(ready)
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                http_before = list(pool.map(lambda _: fpm_request(port), range(8)))
            assert len({r['pid'] for r in http_before}) == 4, http_before
            before += http_before
            verify_phase(before)
            # Outage is explicit: delivery is best effort and not replayed on recovery.
            subprocess.run(COMPOSE + ['stop', 'collector'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            collector_stopped = True
            started = time.monotonic()
            missed = worker_job(worker)
            assert missed['exported'] is False and missed['sql'] > 0
            elapsed = time.monotonic() - started
            assert elapsed < 2, elapsed
            print(json.dumps({'collector_unavailable': 'application continued', 'exported': False, 'seconds': round(elapsed, 3)}), flush=True)
            subprocess.run(COMPOSE + ['start', 'collector'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            collector_stopped = False
            wait(lambda: fetch('http://127.0.0.1:18889/metrics') is not None)
            after = [worker_job(worker) for _ in range(2)]
            assert all(r['exported'] for r in after)
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
                http_after = list(pool.map(lambda _: fpm_request(port), range(4)))
            assert {r['pid'] for r in http_after} == {r['pid'] for r in http_before}, 'Expected the same four FPM writers after recovery'
            after += http_after
            verify_phase(after)
            worker.stdin.write('quit\n'); worker.stdin.flush(); worker.wait(timeout=10)
            # Shutdown must not double count the last already-flushed job.
            verify_phase(after)
            data = json.loads(fetch('http://127.0.0.1:13000/api/dashboards/uid/modernorm-operations'))
            assert data['dashboard']['title'] == 'ModernORM Operations'
            assert len(data['dashboard']['panels']) == 11
            print('PASS: real FPM concurrency, persistent jobs, resets, bounded outage, Collector restart, OTLP/VM totals, histograms, global L2 counters and Grafana provisioning.', flush=True)
        finally:
            if worker is not None and worker.poll() is None:
                worker.terminate(); worker.wait(timeout=10)
            if fpm is not None and fpm.poll() is None:
                fpm.terminate(); fpm.wait(timeout=10)
            if collector_stopped:
                subprocess.run(COMPOSE + ['start', 'collector'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            print(f'Local process logs: {runtime}', flush=True)


if __name__ == '__main__':
    main()
