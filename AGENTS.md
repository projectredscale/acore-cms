# ACore CMS: Agent Guide

WordPress + WooCommerce CMS for AzerothCore. Docker Compose, PHP plugin (Doctrine ORM), SOAP integration.

## Project layout

| Path | Purpose |
|---|---|
| `src/acore-wp-plugin/` | Main WP plugin (PHP + Doctrine ORM) |
| `src/acore-wp-plugin/src/Manager/` | DB managers + SOAP services |
| `src/acore-wp-plugin/src/Hooks/WooCommerce/` | Shop product SKU handlers |
| `src/acore-wp-plugin/src/Components/` | WP admin panels |
| `conf/` | Nginx, PHP, SSL configs |
| `data/docker/` | Dockerfiles |
| `apps/` | DB export/import tooling |

## Agent configuration

- Skill: `.agents/skills/acore-cms/SKILL.md` (SKU catalog, SOAP, Docker, DB wiring; authoritative)
- Prompts: `.agents/prompts/acore-cms-*.prompt.md`

## Key conventions

- **Plugin source of truth**: `src/acore-wp-plugin/` is canonical and bind-mounted into the container
  at `/var/www/html/wp-content/plugins/acore-wp-plugins` (edits are live). `srv/` is an export dump
  (`npm run docker:srv:export`), not the runtime. Never edit `srv/`.
- **PHP namespace**: `ACore\` under `src/acore-wp-plugin/src/`
- **Singleton**: `ACoreServices::I()` for DB + SOAP access
- **WooCommerce hooks**: one handler class per SKU type, extends `WpClass`
- **Docker**: npm scripts, not raw docker commands
- **SOAP is the write path**: never write to AzerothCore DBs directly from CMS code
- **Read-only DB access**: querying acore_auth/characters/world is fine; mutations go through SOAP
- **No em-dash**: same cross-cutting rule as the rest of redscale

## DB wiring + Doctrine facts

- Auth DB = `acore_auth` @ malt MySQL `127.0.0.1:3341` (acore/acore); from the WP container: `host.docker.internal:3341`.
- Runtime container `acore-cms-php-1` (PHP 8.3); plugin bind-mounted at `/var/www/html/wp-content/plugins/acore-wp-plugins/`.
- `acore_db_auth_*` WP options are unset by default, so `ACoreServices::I()` can't reach the auth DB until configured (deployment gap, not a code bug).
- Latent bug in `Manager/Auth/AuthManager.php`: `getAccountRepo`/`getAccountBannedRepo`/`getAccountAccessRepo` use `AccountEntity::class` etc. without `use` imports, so they resolve to the wrong `ACore\Manager\Auth\*` namespace and throw a Doctrine `MappingException`. No callers in src; latent.
- Latent bug 2: `AuthManager::__construct` sets `entityPath = realpath(__DIR__."/../Entity/")`, which resolves to the nonexistent `src/Manager/Entity`. Fix: `realpath(__DIR__."/Entity/")`.
- Local PHP lacks `pdo_mysql` (only pdo_sqlite), so DB-backed repo paths run only in the container.
- `account_coins`/`account_coins_log` are owned by `mod-redscale-shop` db-auth SQL and created via dbimport. The CMS does not create them; it only verifies via read-only `CoinsPurchase::coinsTablesExist()`. `coins_NNN` SKUs credit `account_coins` on `woocommerce_payment_complete`.
- Validating Doctrine: standalone `createAnnotationMetadataConfiguration()` fails ("not a valid entity") on PHP 8.3. Validate via the real runtime: `docker exec acore-cms-php-1 wp eval --allow-root '...'` with `AuthManager` + `host.docker.internal:3341`.

## Common tasks

| Task | Approach |
|---|---|
| Add shop item | Virtual product, set SKU per catalog, optional 3D viewer |
| Add new SKU type | New handler class in Hooks/WooCommerce/, require in WooCommerce.php |
| Debug SOAP | Check SOAP button in ACore Settings admin panel |
| Debug DB | Check credentials in ACore Settings; verify MySQL user host permissions |
| Add admin panel | New component class in Components/, require in boot.php |
