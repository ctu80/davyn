<?php
declare(strict_types=1);

function apiHeaders(): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function apiJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    apiHeaders();
    echo json_encode($data);
    exit;
}

function apiError(string $message, int $status): never
{
    apiJson(['error' => $message], $status);
}

function apiMethodGuard(string ...$allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        apiError('Method not allowed', 405);
    }
}
