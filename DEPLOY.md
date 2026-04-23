# Docker Deploy

This project can run on a VPS with Docker Compose.

## Start the stack

Set your TMDB API key in `.env.docker` before starting:

```bash
TMDB_API_KEY="your_tmdb_api_key"
```

If you deploy with git, create `.env.docker` on the VPS first, because it is intentionally gitignored.

```bash
docker compose up -d --build
```

The app will be available on `http://YOUR_SERVER_IP:8000`.

## Services

- `app`: Apache + PHP 8.4
- `mysql`: MySQL 8.4 with automatic schema initialization

## Useful commands

```bash
docker compose logs -f
docker compose ps
docker compose down
```

## Notes

- Uploaded files are stored in the `uploads_data` volume.
- MySQL data is stored in the `mysql_data` volume.
- The database schema is initialized from `docker/mysql/init/01-schema.sql`.
- If you do not want MySQL exposed on the VPS, remove the `ports` line from the `mysql` service in `docker-compose.yml`.
