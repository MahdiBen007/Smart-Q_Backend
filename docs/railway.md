# Railway Deployment

This project is configured for the standard Railway Laravel deployment flow using Railpack, not Docker.

## What this setup does

- Lets Railway auto-detect Laravel and run it via its PHP/Laravel runtime.
- Runs `php artisan migrate --force` and `php artisan optimize:clear` as a pre-deploy command through `railway.json` (no automatic `db:seed`, so production data is not reset on each deploy).
- To load the CNAS demo dataset once, run in a Railway shell: `php artisan db:seed --class="Database\Seeders\CnasOnlySeeder" --force` (with `SEED_CNAS_ONLY=true` if you use `DatabaseSeeder` routing).
- Uses `/up` as the healthcheck path.

## Deploy steps

1. Push this repository to GitHub.
2. In Railway, create a new project.
3. Add a `MySQL` service.
4. Add a new service from your GitHub repository.
5. Railway will use Railpack because there is no `Dockerfile` in the repository root.
6. Generate a public domain for the app service.
7. Add the variables from `.env.railway.example` to the app service.
8. Redeploy the app service.

## Required variables

At minimum, set these values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `PHP 8.2` compatibility via `composer.json`
- `APP_KEY=<generated Laravel key>`
- `APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}`
- `DB_CONNECTION=mysql`
- `DB_URL=${{MySQL.MYSQL_URL}}`
- `DB_HOST=${{MySQL.MYSQLHOST}}`
- `DB_PORT=${{MySQL.MYSQLPORT}}`
- `DB_DATABASE=${{MySQL.MYSQLDATABASE}}`
- `DB_USERNAME=${{MySQL.MYSQLUSER}}`
- `DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}`
- `JWT_SECRET=<random secret>`
- `FRONTEND_URL=<your frontend origin>`
- `LOG_CHANNEL=stderr`

## Generate secrets

Use these commands locally:

```bash
php artisan key:generate --show
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use the first output for `APP_KEY` and the second one for `JWT_SECRET`.

## Important notes

- `QUEUE_CONNECTION` is set to `sync` in the Railway example because this repository does not currently include the database queue tables.
- Uploaded avatars are currently stored on the local filesystem. On Railway, container files are ephemeral, so uploaded files can be lost after a redeploy or restart.
- If you need persistent uploads, move avatar storage to S3-compatible object storage before relying on uploads in production.
