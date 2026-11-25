# Digital Menu API

Laravel 13 API on PHP 8.4 with PostgreSQL, Redis, and dashboard Bearer authentication. Phase 2 provides identity and account recovery, restaurant lifecycle, tenant memberships and ownership transfer, invitations, and separate platform administration. Phase 3 adds bilingual category and product drafts, private image uploads, branding, versioned publishing, a public menu API, and PNG QR downloads.

## Local development

Copy `.env.example` to `.env` if it is missing. Use `composer install` followed by `php artisan key:generate` on a PHP 8.4 host, or use the container command `docker compose run --rm --no-deps --build api php artisan key:generate --show` and put the returned value in `APP_KEY`. Run `docker compose up -d --build` to start the API, queue worker, scheduler, Nginx, PostgreSQL and Redis, then run `docker compose exec --user www-data api php artisan migrate`.

The gateway listens on loopback port 8000. `GET /api/health` checks the Laravel process. `GET /api/ready` checks PostgreSQL and Redis and returns `503` while either dependency is unavailable.

With `APP_ENV=local`, Laravel accepts direct browser requests from Angular at `http://localhost:4200` and `http://127.0.0.1:4200`. Use `CORS_ALLOWED_ORIGINS` with comma-separated exact origins when using a different development address. Keep `APP_URL` set to the dashboard address for email and QR links.

On a host with PHP 8.4, Composer and the PostgreSQL PDO extension installed, create a separate PostgreSQL database named `emenu_test` and give the test role ownership. Pest rebuilds this schema. Set `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for the test connection as needed. Use `composer lint`, `composer analyse`, and `composer test` to run the backend checks. The live dependency smoke test runs in GitHub Actions with PostgreSQL and Redis services.

## Account and invitation email

Set `APP_URL` to the frontend origin. Run `php artisan migrate` before using the updated dashboard. Sign in via `POST /api/v1/auth/login` and send the returned token as `Authorization: Bearer <accessToken>` on protected calls. PostgreSQL stores token digests. Production uses HTTPS and a private Redis instance for rate limiting, cache and queues.

To receive mail locally or in production, configure `MAIL_MAILER=smtp` with your SMTP settings and sender address, then recreate the API and queue worker with that configuration. Tests use the PHP in-memory `array` mailer. The worker delivers verification, recovery and invitation links to frontend routes. Keep application keys the same across the API and worker so the worker can decrypt queued jobs.

## Menu publishing and media

The local Compose services share the private storage volume at `storage/app/private`. For native PHP, enable GD with JPEG, PNG and WebP support and provide at least 256 MiB of PHP memory for processing. Set an 8 MiB PHP file limit and a 9 MiB multipart body limit. Run the Redis queue worker and Laravel scheduler alongside the API.

For shared object storage, set `MENU_MEDIA_DISK=s3`, use the configured AWS bucket and credentials, and keep the bucket private. Set `AWS_BUCKET_OWNER_ENFORCED=false` for providers that require the private canned ACL. Configure expiration for orphaned objects in the `menu/quarantine/` prefix after a period longer than expected queue outages. The scheduler retries queued processing and unfinished cleanup every five minutes.

Publishing requires a visible product in a visible category. A published menu is available through `GET /api/v1/public/menus/{slug}`, optionally with `?lang=en` or `?lang=ar`. Downloadable QR codes target the configured frontend origin at `/menu/{slug}`. The Angular SSR app serves that browser page from the same public origin.
