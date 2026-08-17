# Contributing

Thanks for considering a contribution to ClipLocal.

## Development setup

1. Install Docker with Docker Compose.
2. Copy `.env.example` to `.env`.
3. Run `docker compose up --build -d`.
4. Open <http://localhost:8080>.

Keep test media in `media/`. That directory is ignored except for its
placeholder file, so local media cannot be committed accidentally.

## Before submitting a change

Run the syntax and unit checks:

```sh
docker compose build
docker compose run --rm --no-deps cliplocal sh -lc \
  "find /app -name '*.php' -print0 | xargs -0 -n1 php -l && php /app/tests/unit.php"
```

Please keep changes focused, document behavior changes, and add or update tests
when practical. Network tests must remain opt-in and must not use account
cookies, credentials, proxies, or access-restriction bypasses.

## Reporting security issues

Do not open a public issue for a suspected vulnerability. Follow the private
reporting instructions in [SECURITY.md](SECURITY.md).
