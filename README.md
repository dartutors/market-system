# Market Signal System

## Local setup (XAMPP)

1. Put the project in: `C:\xampp\htdocs\market-system`
2. Create database: `market_signal`
3. Copy `config/.env.example` to `config/.env`
4. Update `config/.env`
5. Run: `composer install`
6. Run migrations: `php database/migrate.php`
7. Open: `http://localhost/market-system/public`

## Scheduled scan (local)

Use Windows Task Scheduler:

`C:\xampp\php\php.exe C:\xampp\htdocs\market-system\cron\market_scan.php`

## Scheduled jobs (production)

Live document root: `/home/sites/15b/f/fa1906912d/public_html/market/public` → project root is
`/home/sites/15b/f/fa1906912d/public_html/market` (one level up from `public/`, same layout as local).

Six cron scripts exist under `cron/`. Add each as a separate Cron Jobs entry in the
hosting control panel.

**Command template:**
```
PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/SCRIPT.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1
```
Replace `PHP_BIN` with this host's PHP 8.2 CLI binary — the control panel's Cron Jobs
page auto-fills the correct versioned path when you select PHP 8.2 from its version
picker (commonly something like `/usr/bin/php82` or `/opt/php82/bin/php` on this class
of host, but confirm rather than assume — a wrong version can silently change behavior
without erroring). Verify with `php -v` over SSH if available.

| Script | Suggested schedule | Cron expression | Full command |
|---|---|---|---|
| `cron/market_scan.php` | Every 5 minutes | `*/5 * * * *` | `PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/market_scan.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1` |
| `cron/consensus_refresh.php` | Every 30 minutes (2 AI calls/market per run — don't shorten without checking API cost) | `*/30 * * * *` | `PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/consensus_refresh.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1` |
| `cron/calendar_refresh.php` | Every 6 hours | `0 */6 * * *` | `PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/calendar_refresh.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1` |
| `cron/market_stream.php` | Every 2 minutes, single-poll mode (never pass `--watch` here — that loops forever and cron would stack up overlapping long-lived processes) | `*/2 * * * *` | `PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/market_stream.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1` |
| `cron/weight_optimizer.php` | Once daily, off-peak | `0 2 * * *` | `PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/weight_optimizer.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1` |
| `cron/maintenance.php` | Once daily, staggered after weight_optimizer | `0 3 * * *` | `PHP_BIN /home/sites/15b/f/fa1906912d/public_html/market/cron/maintenance.php >> /home/sites/15b/f/fa1906912d/public_html/market/storage/logs/cron_stderr.log 2>&1` |

Every script already uses its own non-blocking lock file (`storage/cache/*.lock`), so an overrun run is skipped rather than stacked — safe to paste these in even if a previous run is still finishing.

## Diagnosing cron / system health

`https://market.dartutors.com/reports/system-health` (admin login required) —
also exportable as CSV/Excel/PDF via `?export=csv|excel|pdf` — reports live status for:
Database, AI APIs, News, Market Data, Calendar, Backtesting, Vision, and **Cron**
(last-run freshness for all six scripts above, either from a DB table where one exists
(`scan_runs` for `market_scan`) or the script's own log file `mtime` otherwise). A cron
job that stops running shows up here as a stale/missing entry within one cycle — check
this page first before digging through the host's own cron logs.
