# Backend (PHP + MySQL)

This directory will contain the REST API for SagipHub using PHP and MySQL.

Planned endpoints:
- POST /requests — create a help request (Turnstile verification + rate limit)
- GET /requests — list requests (paginated/bbox)
- GET /requests/{id} — fetch a single request (no PII)
- POST /requests/{id}/withdraw — withdraw using edit token

Next steps:
- Add `public/index.php` front controller (micro-framework or plain PHP)
- Add DB schema and migrations
- Add Turnstile server verify and rate limiter (Redis or MySQL-based)
- Add `.env` for DB creds and secrets

Runbook will be added once the stack is finalized.

## Running locally

Prereqs: PHP 8.1+, MySQL 8.x. Create the database using `backend/sql/schema.sql`.

1. Create a `.env` file in the project root with:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=sagiphub
DB_USER=root
DB_PASS=yourpassword
```

2. Start the PHP dev server from the repo root:

```
php -S localhost:8080 -t backend/public
```

3. Test endpoints:
- `GET /health`
- `GET /requests?per_page=20&page=1`
- `GET /requests/{public_id}`
 - `POST /requests` with JSON body
 - `POST /requests/{public_id}/withdraw` with `{ "edit_token": "..." }`

For Apache, ensure `backend/public/.htaccess` is respected or configure a vhost with `DocumentRoot` pointing to `backend/public` and route all requests to `index.php`.