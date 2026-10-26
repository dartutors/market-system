<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

final class WatchlistItem
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM watchlist_items ORDER BY created_at ASC');
        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string> Symbols currently pinned — cheap membership-check shape for view rendering. */
    public static function pinnedSymbols(): array
    {
        $stmt = Database::connection()->query('SELECT symbol FROM watchlist_items');
        return array_column($stmt->fetchAll() ?: [], 'symbol');
    }

    public static function add(string $symbol, string $marketLabel, string $category, ?int $createdBy): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO watchlist_items (symbol, market_label, category, created_by, created_at)
             VALUES (:symbol, :market_label, :category, :created_by, NOW())'
        );
        $stmt->execute([
            'symbol'       => $symbol,
            'market_label' => $marketLabel,
            'category'     => $category,
            'created_by'   => $createdBy,
        ]);
    }

    public static function removeBySymbol(string $symbol): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM watchlist_items WHERE symbol = :symbol');
        $stmt->execute(['symbol' => $symbol]);
    }

    public static function isWatched(string $symbol): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM watchlist_items WHERE symbol = :symbol LIMIT 1');
        $stmt->execute(['symbol' => $symbol]);
        return (bool) $stmt->fetch();
    }
}
