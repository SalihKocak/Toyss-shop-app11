<?php

declare(strict_types=1);

// PHP built-in server with router: let the server serve static files (assets, uploads)
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path && preg_match('#^/(assets|uploads)/#', $path)) {
    $file = __DIR__ . $path;
    if (is_file($file)) {
        return false;
    }
}

use ToyShop\Infrastructure\Env;
use ToyShop\Infrastructure\Logger;
use ToyShop\Infrastructure\Mongo;
use ToyShop\Infrastructure\Response;
use ToyShop\Middleware\AdminMiddleware;
use ToyShop\Middleware\AuthMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Helpers.php';

$basePath = dirname(__DIR__);
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', $basePath);
}
Env::load($basePath);
Logger::setLogPath($basePath . '/storage/logs/app.log');

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = $path ?: '/';
$path = rtrim($path, '/') ?: '/';
// Alt dizinden deploy edildiyse (örn. APP_URL path içeriyorsa) route path'ı buna göre ayarla
$basePathFromUrl = parse_url(Env::get('APP_URL', ''), PHP_URL_PATH);
if ($basePathFromUrl !== null && $basePathFromUrl !== '' && $basePathFromUrl !== '/' && str_starts_with($path, $basePathFromUrl)) {
    $path = substr($path, strlen($basePathFromUrl)) ?: '/';
}

if ($path === '/healthz') {
    $status = 200;
    $payload = [
        'ok' => true,
        'service' => 'toyshop',
        'timestamp' => gmdate('c'),
    ];

    try {
        Mongo::init(Env::getRequired('MONGODB_URI'), Env::getRequired('MONGODB_DB'));
        $ping = Mongo::db()->command(['ping' => 1])->toArray();
        $payload['mongo'] = isset($ping[0]->ok) && (float) $ping[0]->ok === 1.0 ? 'up' : 'unknown';
    } catch (Throwable $e) {
        $status = 503;
        $payload['ok'] = false;
        $payload['mongo'] = 'down';
        $payload['error'] = 'MongoDB baglantisi kurulamadi.';
        Logger::error('Health check failed', ['message' => $e->getMessage()]);
    }

    Response::json($payload, $status);
    exit;
}

try {
    Mongo::init(Env::getRequired('MONGODB_URI'), Env::getRequired('MONGODB_DB'));
} catch (Throwable $e) {
    Logger::error('MongoDB init failed', ['message' => $e->getMessage()]);
    if (Env::get('APP_ENV') === 'local') {
        throw $e;
    }
    Response::jsonError('CONFIG', 'Veritabani baglantisi kurulamadi.', 500);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = $basePath . '/storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0755, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    session_name(Env::get('SESSION_NAME', 'toyshop_session'));
    $isSecure = (Env::get('APP_ENV') === 'production')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isSecure || Env::get('APP_ENV') === 'production') {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

$authService = new \ToyShop\Services\AuthService();
$productService = new \ToyShop\Services\ProductService();
$chatService = new \ToyShop\Services\ChatService();
$orderService = new \ToyShop\Services\OrderService();

$authController = new \ToyShop\Controllers\AuthController($authService);
$productController = new \ToyShop\Controllers\ProductController($productService);
$imageController = new \ToyShop\Controllers\ImageController($productService);
$cartController = new \ToyShop\Controllers\CartController($productService);
$orderController = new \ToyShop\Controllers\OrderController($orderService, $productService);
$chatController = new \ToyShop\Controllers\ChatController($chatService);
$profileController = new \ToyShop\Controllers\ProfileController($orderService, $productService);

$adminAuthController = new \ToyShop\Controllers\Admin\AdminAuthController($authService);
$adminDashboardController = new \ToyShop\Controllers\Admin\AdminDashboardController($orderService);
$adminProductController = new \ToyShop\Controllers\Admin\AdminProductController($productService);
$adminOrderController = new \ToyShop\Controllers\Admin\AdminOrderController($orderService);
$adminChatController = new \ToyShop\Controllers\Admin\AdminChatController($chatService);
$adminUsersController = new \ToyShop\Controllers\Admin\AdminUsersController();
$adminLogsController = new \ToyShop\Controllers\Admin\AdminLogsController($basePath);

try {
    if ($path === '/') {
        $featuredProducts = $productService->listFeatured(3);
        require $basePath . '/src/Views/home.php';
        exit;
    }

    if ($path === '/products') {
        $productController->index();
        exit;
    }

    if (preg_match('#^/product/([a-f0-9]{24})$#', $path, $m)) {
        $productController->show($m[1]);
        exit;
    }

    if (preg_match('#^/image/([a-f0-9]{24})/(\d+)$#', $path, $m)) {
        $imageController->serve($m[1], (int) $m[2]);
        exit;
    }

    if ($path === '/cart') {
        $cartController->index();
        exit;
    }

    if ($path === '/cart/add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cartController->add();
        exit;
    }

    if ($path === '/cart/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cartController->update();
        exit;
    }

    if ($path === '/checkout') {
        $orderController->checkout();
        exit;
    }

    if ($path === '/checkout/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $orderController->create();
        exit;
    }

    if ($path === '/support') {
        $user = AuthMiddleware::requireLogin();
        if ($user === null) {
            $base = rtrim(parse_url(Env::get('APP_URL', ''), PHP_URL_PATH) ?: '', '/') ?: '';
            require $basePath . '/src/Views/support/guest.php';
            exit;
        }
        require $basePath . '/src/Views/support/index.php';
        exit;
    }

    if ($path === '/chat/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatController->start();
        exit;
    }

    if ($path === '/chat/poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $chatController->poll();
        exit;
    }

    if ($path === '/chat/send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatController->send();
        exit;
    }

    if ($path === '/chat/my-threads' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $chatController->myThreads();
        exit;
    }

    if ($path === '/chat/thread' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $chatController->thread();
        exit;
    }

    if ($path === '/chat/close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatController->close();
        exit;
    }

    if ($path === '/login') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $authController->login();
        } else {
            $authController->showLogin();
        }
        exit;
    }

    if ($path === '/register') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $authController->register();
        } else {
            $authController->showRegister();
        }
        exit;
    }

    if ($path === '/logout') {
        $authController->logout();
        exit;
    }

    if ($path === '/profile') {
        $profileController->index();
        exit;
    }

    if (str_starts_with($path, '/admin')) {
        if ($path === '/admin/login') {
            Response::redirect(rtrim(Env::get('APP_URL', ''), '/') . '/login');
            exit;
        }

        if ($path === '/admin/logout') {
            $adminAuthController->logout();
            exit;
        }

        $admin = AdminMiddleware::requireAdmin();
        if ($admin === null) {
            Response::redirect(rtrim(Env::get('APP_URL', ''), '/') . '/login');
            exit;
        }

        if ($path === '/admin/dashboard') {
            $adminDashboardController->index();
            exit;
        }

        if ($path === '/admin/products') {
            $adminProductController->index();
            exit;
        }

        if ($path === '/admin/products/create') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $adminProductController->create();
            } else {
                $adminProductController->createForm();
            }
            exit;
        }

        if (preg_match('#^/admin/products/([a-f0-9]{24})/edit$#', $path, $m)) {
            $adminProductController->editForm($m[1]);
            exit;
        }

        if (preg_match('#^/admin/products/([a-f0-9]{24})/update$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $adminProductController->update($m[1]);
            exit;
        }

        if (preg_match('#^/admin/products/([a-f0-9]{24})/delete$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $adminProductController->delete($m[1]);
            exit;
        }

        if ($path === '/admin/orders') {
            $adminOrderController->index();
            exit;
        }

        if (preg_match('#^/admin/orders/([a-f0-9]{24})/status$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $adminOrderController->updateStatus($m[1]);
            exit;
        }

        if ($path === '/admin/chats') {
            $adminChatController->index();
            exit;
        }

        if (preg_match('#^/admin/chats/([a-f0-9]{24})/poll$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $adminChatController->poll($m[1]);
            exit;
        }

        if (preg_match('#^/admin/chats/([a-f0-9]{24})$#', $path, $m)) {
            $adminChatController->show($m[1]);
            exit;
        }

        if (preg_match('#^/admin/chats/([a-f0-9]{24})/send$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $adminChatController->send($m[1]);
            exit;
        }

        if (preg_match('#^/admin/chats/([a-f0-9]{24})/close$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $adminChatController->close($m[1]);
            exit;
        }

        if ($path === '/admin/users') {
            $adminUsersController->index();
            exit;
        }

        if ($path === '/admin/logs') {
            $adminLogsController->index();
            exit;
        }
    }

    http_response_code(404);
    require $basePath . '/src/Views/errors/404.php';
} catch (Throwable $e) {
    Logger::error('Request error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    if (Env::get('APP_ENV') === 'local') {
        throw $e;
    }
    header('Content-Type: application/json; charset=utf-8');
    Response::jsonError('SERVER', 'Bir hata olustu.', 500);
}
