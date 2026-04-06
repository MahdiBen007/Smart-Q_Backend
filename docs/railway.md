# Railway Deployment

This project includes a production-ready `Dockerfile` for deploying the Laravel backend to Railway.

## What this setup does

- Builds Composer dependencies in a dedicated stage.
- Builds Vite assets in a dedicated Node stage.
- Serves Laravel through Apache with `public/` as the document root.
- Binds Apache to Railway's `PORT` variable at container startup.
- Optionally runs `php artisan migrate --force` when `RUN_MIGRATIONS=true`.

## Deploy steps

1. Push this repository to GitHub.
2. In Railway, create a new project.
3. Add a `MySQL` service.
4. Add a new service from your GitHub repository.
5. Railway will detect the root `Dockerfile` and build from it automatically.
6. Generate a public domain for the app service.
7. Add the variables from `.env.railway.example` to the app service.

## Required variables

At minimum, set these values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY=<generated Laravel key>`
- `APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}`
- `DB_CONNECTION=mysql`
- `DB_URL=${{MySQL.MYSQL_URL}}`
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
