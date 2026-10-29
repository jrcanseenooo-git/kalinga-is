import { mkdtempSync, closeSync, openSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawn, execFileSync } from 'node:child_process';

// New synthetic database each run; never reset an existing database or clear app configuration.
const directory = mkdtempSync(path.join(tmpdir(), 'kalinga-foundation-'));
const database = path.join(directory, 'synthetic.sqlite');
closeSync(openSync(database, 'wx'));
const views = path.join(directory, 'views');
mkdirSync(views);
const env = {
    ...process.env,
    APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8017',
    APP_CONFIG_CACHE: path.join(directory, 'config.php'),
    VIEW_COMPILED_PATH: views,
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_COOKIE: 'kalinga_foundation_session',
    QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', MFA_ENFORCEMENT: 'false',
    UAT_ENABLED: 'true',
};
execFileSync('php', ['artisan', 'migrate', '--seed', '--force', '--no-interaction'], { env, stdio: 'pipe' });
const fixtureOutput = execFileSync('php', ['tests/e2e/foundation-fixtures.php'], { env, stdio: 'pipe', encoding: 'utf8' });
if (fixtureOutput.trim() !== 'KALINGA_SYNTHETIC_FIXTURES_READY') throw new Error('Synthetic fixtures did not confirm complete preparation.');
const serverLog = openSync(path.join(directory, 'server.log'), 'wx');
const server = spawn('php', ['-S', '127.0.0.1:8017', '-t', 'public', 'tests/e2e/foundation-server.php'], { env, stdio: ['ignore', 'ignore', serverLog] });
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => { server.kill(); });
server.on('exit', code => { process.exitCode = code ?? 0; });
