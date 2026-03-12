<?php

declare(strict_types=1);

namespace ToyShop\Controllers;

use ToyShop\Infrastructure\Env;
use ToyShop\Services\ProductService;

/**
 * Ürün görselini veritabanı (imageData) veya dosyadan sunar.
 * Deploy'da dosya olmasa bile DB'deki base64 ile görsel gösterilir.
 */
final class ImageController
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function serve(string $productId, int $index): void
    {
        try {
            if ($productId === '' || strlen($productId) !== 24 || !ctype_xdigit($productId)) {
                $this->servePlaceholder();
                return;
            }
            $product = $this->productService->getById($productId, true);
            if ($product === null) {
                $this->servePlaceholder();
                return;
            }
            $images = $product['images'] ?? [];
            if ($images instanceof \Traversable) {
                $images = iterator_to_array($images);
            }
            if (!isset($images[$index]) || $images[$index] === '') {
                $this->servePlaceholder();
                return;
            }
            $storedPath = $images[$index];
            if (is_array($storedPath)) {
                $storedPath = (string) ($storedPath['name'] ?? $storedPath[0] ?? '');
            }
            $storedPath = trim((string) $storedPath);
            if ($storedPath === '') {
                $this->servePlaceholder();
                return;
            }
            $filename = basename($storedPath);
            if ($filename === '' || $filename === '.') {
                $this->servePlaceholder();
                return;
            }
            // 1) Veritabanındaki imageData (yeni yüklemeler; key = dosya adı)
            $imageData = $product['imageData'] ?? [];
            if ($imageData instanceof \Traversable) {
                $imageData = iterator_to_array($imageData);
            }
            if (isset($imageData[$filename]) && $imageData[$filename] !== '') {
                $raw = @base64_decode((string) $imageData[$filename], true);
                if ($raw !== false && $raw !== '') {
                    $this->outputImage($raw, $filename);
                    return;
                }
            }
            // 2) Dosyadan: önce tam yol (seed: AaGörseller/xxx.jpg), sonra sadece dosya adı
            if (!defined('PROJECT_ROOT')) {
                $this->servePlaceholder();
                return;
            }
            $uploadsRoot = PROJECT_ROOT . '/public/uploads';
            $safeRelative = str_replace('\\', '/', $storedPath);
            if (preg_match('#\.\.#', $safeRelative)) {
                $safeRelative = $filename;
            }
            $legacyRoot = PROJECT_ROOT . '/src/public/uploads';
            $candidates = [
                $uploadsRoot . '/' . $safeRelative,
                $uploadsRoot . '/' . $filename,
                $legacyRoot . '/' . $safeRelative,
                $legacyRoot . '/' . $filename,
            ];
            foreach ($candidates as $path) {
                if (@is_file($path)) {
                    $raw = @file_get_contents($path);
                    if ($raw !== false) {
                        $this->outputImage($raw, $filename);
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            \ToyShop\Infrastructure\Logger::error('Image serve error', ['message' => $e->getMessage(), 'productId' => $productId]);
        }
        $this->servePlaceholder();
    }

    private function outputImage(string $raw, string $filename): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $type = $types[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=86400');
        echo $raw;
    }

    /** Placeholder'ı redirect yerine doğrudan sunar (img etiketleri için daha güvenilir). */
    private function servePlaceholder(): void
    {
        $path = defined('PROJECT_ROOT') ? (PROJECT_ROOT . '/public/assets/placeholder.svg') : '';
        if ($path !== '' && @is_file($path)) {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=3600');
            echo @file_get_contents($path);
            return;
        }
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-cache');
        echo '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"><rect fill="#eee" width="200" height="200"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#999" font-size="14">Görsel yok</text></svg>';
    }
}
