<?php
declare(strict_types=1);
namespace App\Core;

use App\Config\Database as DatabaseConfig;
use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            // PDO's MySQL driver does not auto-reconnect a dropped TCP
            // connection — a handle that was fine a minute ago silently
            // fatals the next query if the server restarted, hit
            // wait_timeout, or the network blipped. Longest-running request
            // in this app is MarketOverviewController's ~250s instrument
            // loop, which calls Database::connection() fresh for each
            // instrument's provider-log write — exactly the shape where a
            // mid-request drop would otherwise take down everything
            // computed so far. getAttribute() is a real round trip (cheap:
            // DB_HOST is localhost/127.0.0.1 in every environment this app
            // runs in) that throws if the socket is actually dead, letting
            // us discard and reconnect instead of handing back a corpse.
            try {
                self::$pdo->getAttribute(PDO::ATTR_SERVER_INFO);
                return self::$pdo;
            } catch (PDOException $e) {
                error_log('[Database] Cached connection was stale, reconnecting: ' . $e->getMessage());
                self::$pdo = null;
            }
        }

        try {
            self::$pdo = new PDO(
                DatabaseConfig::dsn(),
                (string) env('DB_USERNAME', 'root'),
                (string) env('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // Log the real error internally; never expose credentials or DB details to the browser
            error_log('[Database] Connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Service temporarily unavailable. Please try again later.');
        }

        return self::$pdo;
    }
}
