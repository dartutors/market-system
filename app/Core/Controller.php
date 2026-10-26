<?php
declare(strict_types=1);
namespace App\Core;

abstract class Controller {
    protected function view(string $view, array $data = []): void {
        $data['view'] = $view;
        extract($data, EXTR_SKIP);
        require app_path('Views/layouts/main.php');
    }

    protected function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        // Every JSON endpoint in this app is either live data (market
        // overview) or a mutation (watchlist toggle, news explain) — none
        // should ever be served from the browser's HTTP cache. Caught live:
        // the watchlist star showed a stale "pinned" state after a toggle
        // because Chrome cached the prior response with no cache directive
        // telling it not to.
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
