<?php

declare(strict_types=1);

/**
 * Ürün görseli URL'i: /image/{productId}/{index}
 * Production'da tam URL kullanılır (proxy/redirect sorunlarını önlemek için).
 */
function product_image_url(string $productId, int $index, string $base): string
{
    $path = '/image/' . htmlspecialchars($productId, ENT_QUOTES, 'UTF-8') . '/' . (int) $index;
    $env = \ToyShop\Infrastructure\Env::get('APP_ENV');
    if ($env === 'production') {
        $appUrl = rtrim((string) \ToyShop\Infrastructure\Env::get('APP_URL', ''), '/');
        return $appUrl !== '' ? $appUrl . $path : $base . $path;
    }
    return $base . $path;
}
