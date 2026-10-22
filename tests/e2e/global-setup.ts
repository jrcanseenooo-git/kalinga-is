import { execFileSync } from 'node:child_process';
import { closeSync, existsSync, openSync } from 'node:fs';
import path from 'node:path';

export default function globalSetup() {
    if (process.env.PLAYWRIGHT_BASE_URL) return;

    const database = path.resolve('database/e2e.sqlite');
    if (!existsSync(database)) closeSync(openSync(database, 'w'));

    const env = {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        MFA_ENFORCEMENT: 'false',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'file',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array',
    };

    execFileSync('php', ['artisan', 'config:clear', '--no-interaction'], {
        cwd: process.cwd(),
        stdio: 'inherit',
        env,
    });

    execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction'], {
        cwd: process.cwd(),
        stdio: 'inherit',
        env,
    });
}
