import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';

/**
 * Substitute the Pest executable to test target selection and failure propagation
 * without running mutation testing for every CI script test.
 */
function runGate(context, environment = {}) {
    const root = mkdtempSync(join(tmpdir(), 'http-basics-mutation-gate-test-'));
    context.after(() => rmSync(root, { recursive: true, force: true }));

    for (const directory of ['src/Console', 'src/Contracts', 'scripts', 'vendor/bin']) {
        mkdirSync(join(root, directory), { recursive: true });
    }

    writeFileSync(join(root, 'src/Console/DeployCheckCommand.php'), '<?php');
    writeFileSync(join(root, 'src/Contracts/StoreContract.php'), '<?php');
    copyFileSync(new URL('../../scripts/mutate-per-class.sh', import.meta.url), join(root, 'scripts/mutate-per-class.sh'));
    writeFileSync(join(root, 'vendor/bin/pest'), [
        '#!/usr/bin/env bash',
        'case "$*" in',
        '  *--path=src/Console/DeployCheckCommand.php*) printf "Score: %s%%\\n" "${FAKE_SCORE:-98.00}"; exit "${FAKE_EXIT_CODE:-0}" ;;',
        '  *--path=src/Contracts/StoreContract.php*) printf "No mutations created.\\n" ;;',
        '  *) exit 2 ;;',
        'esac',
    ].join('\n'), { mode: 0o755 });

    return spawnSync('bash', ['scripts/mutate-per-class.sh'], {
        cwd: root,
        encoding: 'utf8',
        env: { ...process.env, MUTATE_SCOPE: 'all', MUTATE_SHARD: '1', MUTATE_SHARDS: '1', ...environment },
    });
}

test('discovers executable classes and reports files with no mutations', (context) => {
    const result = runGate(context);

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /src\/Console\/DeployCheckCommand\.php \| 98\.00%/);
    assert.match(result.stdout, /src\/Contracts\/StoreContract\.php \| 0 mutants/);
});

test('limits a PR run to changed files and propagates a failing score', (context) => {
    const result = runGate(context, {
        MUTATE_SCOPE: 'changed',
        MUTATE_CLASSES: '["src/Console/DeployCheckCommand.php"]',
        FAKE_SCORE: '94.00',
        FAKE_EXIT_CODE: '1',
    });

    assert.equal(result.status, 1);
    assert.match(result.stdout, /src\/Console\/DeployCheckCommand\.php \| 94\.00%/);
    assert.doesNotMatch(result.stdout, /StoreContract/);
});
