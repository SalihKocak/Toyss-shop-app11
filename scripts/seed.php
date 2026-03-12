<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ToyShop\Infrastructure\Env;
use ToyShop\Infrastructure\Mongo;

Env::load(dirname(__DIR__));
Mongo::init(Env::getRequired('MONGODB_URI'), Env::getRequired('MONGODB_DB'));

$db = Mongo::db();

echo "Dropping existing collections (products, orders, chat only; users are preserved)...\n";
foreach (['products', 'orders', 'chat_threads', 'chat_messages'] as $c) {
    try {
        $db->dropCollection($c);
    } catch (Exception $e) {
        // ignore
    }
}

echo "Creating indexes...\n";
$db->selectCollection('chat_threads')->createIndex(['status' => 1, 'lastMessageAt' => -1]);
$db->selectCollection('chat_messages')->createIndex(['threadId' => 1, 'createdAt' => 1]);
$db->selectCollection('products')->createIndex(['isActive' => 1, 'createdAt' => -1]);
$db->selectCollection('users')->createIndex(['email' => 1], ['unique' => true]);
$db->selectCollection('orders')->createIndex(['userId' => 1, 'createdAt' => -1]);

echo "Seeding admin user (upsert: existing users are not deleted)...\n";
$adminPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);
$usersColl = $db->selectCollection('users');
$existingAdmin = $usersColl->findOne(['email' => 'admin123@gmail.com']);
$adminId = $existingAdmin['_id'] ?? new \MongoDB\BSON\ObjectId();
$nowAdmin = new \MongoDB\BSON\UTCDateTime();
$usersColl->updateOne(
    ['email' => 'admin123@gmail.com'],
    [
        '$set' => [
            'role' => 'admin',
            'name' => 'Admin',
            'passwordHash' => $adminPasswordHash,
            'updatedAt' => $nowAdmin,
        ],
        '$setOnInsert' => [
            '_id' => $adminId,
            'email' => 'admin123@gmail.com',
            'createdAt' => $nowAdmin,
        ],
    ],
    ['upsert' => true]
);
$adminId = $usersColl->findOne(['email' => 'admin123@gmail.com'])['_id'];
echo "  Admin: admin123@gmail.com / admin123 (other users are preserved)\n";

echo "Seeding 8 demo users (giriş: demo1@toyshop.local ... demo8@toyshop.local / şifre: user123)...\n";
$demoPassHash = password_hash('user123', PASSWORD_DEFAULT);
$demoEmails = ['demo1@toyshop.local', 'demo2@toyshop.local', 'demo3@toyshop.local', 'demo4@toyshop.local', 'demo5@toyshop.local', 'demo6@toyshop.local', 'demo7@toyshop.local', 'demo8@toyshop.local'];
$demoUserIds = [];
foreach ($demoEmails as $idx => $email) {
    $existing = $usersColl->findOne(['email' => $email]);
    if ($existing !== null) {
        $usersColl->updateOne(['email' => $email], ['$set' => ['passwordHash' => $demoPassHash, 'name' => 'Demo Kullanıcı ' . ($idx + 1)]]);
        $demoUserIds[] = $existing['_id'];
    } else {
        $uid = new \MongoDB\BSON\ObjectId();
        $usersColl->insertOne([
            '_id' => $uid,
            'role' => 'customer',
            'name' => 'Demo Kullanıcı ' . ($idx + 1),
            'email' => $email,
            'passwordHash' => $demoPassHash,
            'createdAt' => new \MongoDB\BSON\UTCDateTime(),
        ]);
        $demoUserIds[] = $uid;
    }
}
echo "  " . count($demoUserIds) . " demo users ready.\n";

echo "Seeding products...\n";

/**
 * Ürün görselleri: public/uploads/AaGörseller veya public/uploads/product-images içine koyun.
 * Dosya adları ürün adına göre eşleşir (örn. lego-technic-yaris-arabasi.jpg).
 * Seed çalışırken bulunan görseller veritabanına (imageData) gömülür; local ve deploy'da görünür.
 */
function toyshop_normalize(string $s): string
{
    $s = trim($s);
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    $s = strtr($s, [
        'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ı' => 'i', 'ö' => 'o', 'ç' => 'c',
        'Ğ' => 'g', 'Ü' => 'u', 'Ş' => 's', 'İ' => 'i', 'Ö' => 'o', 'Ç' => 'c',
    ]);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? $s;
    return trim($s, '-');
}

/** @return array<int, array{base:string, rel:string}> */
function toyshop_collect_images(string $uploadsDir): array
{
    $candidates = [];
    $dirs = [
        $uploadsDir . DIRECTORY_SEPARATOR . 'product-images',
        $uploadsDir . DIRECTORY_SEPARATOR . 'AaGörseller',
        $uploadsDir,
    ];
    $exts = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach ($exts as $ext) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $path) {
                $file = basename($path);
                $base = pathinfo($file, PATHINFO_FILENAME);
                $rel = str_contains($dir, DIRECTORY_SEPARATOR . 'product-images')
                    ? ('product-images/' . $file)
                    : (str_contains($dir, DIRECTORY_SEPARATOR . 'AaGörseller')
                        ? ('AaGörseller/' . $file)
                        : $file);
                $candidates[] = ['base' => $base, 'rel' => $rel];
            }
        }
    }
    return $candidates;
}

$uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
$imageCandidates = toyshop_collect_images($uploadsDir);
$products = [
    [
        'name' => 'LEGO Technic Yarış Arabası',
        'brand' => 'LEGO',
        'category' => 'LEGO Technic',
        'price' => 2499.90,
        'stock' => 15,
        'description' => 'Yüksek performanslı Technic yarış arabası. Detaylı gövde, agresif tasarım ve sergilemeye uygun premium görünüm.',
        'specs' => [
            'Parça Sayısı' => 452,
            'Renkler' => 'Kırmızı / Siyah',
            'Önerilen Yaş' => '9+',
            'Boyut' => 'Yaklaşık 28 × 12 × 7 cm',
            'Malzeme' => 'ABS plastik (LEGO standardı)',
        ],
        'images' => ['AaGörseller/lego-technic-yaris-arabasi.jpg'],
        'isActive' => true,
        'isFeatured' => true,
    ],
    [
        'name' => 'LEGO Technic Arazi Aracı',
        'brand' => 'LEGO',
        'category' => 'LEGO Technic',
        'price' => 1899.90,
        'stock' => 18,
        'description' => 'Zorlu parkurlar için 4x4 Technic arazi aracı. Yüksek yerden yükseklik, sağlam şasi ve sergilemelik görünüm.',
        'specs' => [
            'Parça Sayısı' => 612,
            'Renkler' => 'Turuncu / Siyah',
            'Önerilen Yaş' => '9+',
            'Boyut' => 'Yaklaşık 31 × 14 × 13 cm',
            'Öne Çıkan' => 'Arazi lastikleri, off-road tasarım',
        ],
        'images' => ['AaGörseller/lego-technic-arazi-araci.jpg'],
        'isActive' => true,
        'isFeatured' => true,
    ],
    [
        'name' => 'Aksiyon Figür Seti',
        'brand' => 'Collectible',
        'category' => 'Figür',
        'price' => 799.90,
        'stock' => 30,
        'description' => 'Koleksiyon severler için 6’lı aksiyon figür seti. Sergilemeye uygun, detaylı boya ve güçlü duruş.',
        'specs' => [
            'Set İçeriği' => '6 adet figür',
            'Figür Boyu' => 'Yaklaşık 12–15 cm',
            'Renkler' => 'Çok renkli',
            'Kullanım' => 'Koleksiyon / Sergileme',
            'Bakım' => 'Kuru bez ile silin',
        ],
        'images' => ['AaGörseller/aksiyon-figur-seti.jpg'],
        'isActive' => true,
        'isFeatured' => true,
    ],
    ['name' => 'Koleksiyon Robot Figürü', 'brand' => 'Collectible', 'category' => 'Koleksiyon', 'price' => 199.99, 'stock' => 10, 'description' => 'Sınırlı seri robot figürü.', 'images' => ['product-images/koleksiyon-robot-figuru.jpg'], 'isActive' => true],
    ['name' => 'LEGO City Havaalanı', 'brand' => 'LEGO', 'category' => 'LEGO', 'price' => 429.99, 'stock' => 8, 'description' => 'City serisi havaalanı seti.', 'images' => ['product-images/lego-city-havaalani.jpg'], 'isActive' => true],
    ['name' => 'Mini Figür Koleksiyon Kutusu', 'brand' => 'LEGO', 'category' => 'Koleksiyon', 'price' => 79.99, 'stock' => 50, 'description' => 'Rastgele 6 adet mini figür.', 'images' => ['product-images/mini-figur-koleksiyon-kutusu.jpg'], 'isActive' => true],
];

// Otomatik resim eslestirme (varsa images[] alanini gunceller)
if ($imageCandidates !== []) {
    foreach ($products as &$p) {
        $pKey = toyshop_normalize((string) ($p['name'] ?? ''));
        $best = null;
        foreach ($imageCandidates as $c) {
            $cKey = toyshop_normalize($c['base']);
            if ($cKey === $pKey) { $best = $c['rel']; break; }
            if ($best === null && ($cKey !== '' && (str_contains($cKey, $pKey) || str_contains($pKey, $cKey)))) {
                $best = $c['rel'];
            }
        }
        if ($best !== null) {
            $p['images'] = [$best];
        }
    }
    unset($p);
}
$baseMs = (int) (microtime(true) * 1000);
$productIds = [];
$productsColl = $db->selectCollection('products');
$projectRoot = dirname(__DIR__);
foreach ($products as $i => $p) {
    $ts = new \MongoDB\BSON\UTCDateTime($baseMs + $i);
    $p['createdAt'] = $ts;
    $p['updatedAt'] = $ts;
    // Mevcut görsel dosyalarını veritabanına (imageData) göm; böylece local ve deploy'da görünür
    $imageData = [];
    foreach ($p['images'] ?? [] as $relPath) {
        $relPath = str_replace('\\', '/', trim((string) $relPath));
        if ($relPath === '' || preg_match('#\.\.#', $relPath)) {
            continue;
        }
        $candidates = [
            $projectRoot . '/public/uploads/' . $relPath,
            $projectRoot . '/public/uploads/' . basename($relPath),
            $projectRoot . '/src/public/uploads/' . $relPath,
            $projectRoot . '/src/public/uploads/' . basename($relPath),
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $raw = @file_get_contents($path);
                if ($raw !== false) {
                    $imageData[basename($relPath)] = base64_encode($raw);
                    break;
                }
            }
        }
    }
    if ($imageData !== []) {
        $p['imageData'] = $imageData;
    }
    $res = $productsColl->insertOne($p);
    $productIds[] = ['id' => $res->getInsertedId(), 'name' => $p['name'], 'price' => (float)($p['price'] ?? 0)];
}
echo "  " . count($products) . " products inserted.\n";

echo "Seeding 12 orders...\n";
$ordersColl = $db->selectCollection('orders');
$orderStatuses = ['created', 'created', 'paid', 'paid', 'paid', 'shipped', 'shipped', 'shipped', 'shipped', 'cancelled', 'cancelled', 'paid'];
for ($i = 0; $i < 12; $i++) {
    $userIdx = $i % count($demoUserIds);
    $prodIdx = $i % count($productIds);
    $p = $productIds[$prodIdx];
    $qty = ($i % 3) + 1;
    $items = [
        ['productId' => $p['id']->__toString(), 'nameSnapshot' => $p['name'], 'priceSnapshot' => $p['price'], 'qty' => $qty],
    ];
    if (count($productIds) > 1 && $i % 2 === 0) {
        $p2 = $productIds[($prodIdx + 1) % count($productIds)];
        $qty2 = 1;
        $items[] = ['productId' => $p2['id']->__toString(), 'nameSnapshot' => $p2['name'], 'priceSnapshot' => $p2['price'], 'qty' => $qty2];
    }
    $total = 0.0;
    foreach ($items as $it) {
        $total += $it['priceSnapshot'] * $it['qty'];
    }
    $orderTs = new \MongoDB\BSON\UTCDateTime($baseMs + 500 + $i * 100);
    $ordersColl->insertOne([
        'userId' => $demoUserIds[$userIdx],
        'items' => $items,
        'total' => $total,
        'status' => $orderStatuses[$i],
        'createdAt' => $orderTs,
    ]);
}
echo "  12 orders inserted.\n";

$threadsColl = $db->selectCollection('chat_threads');
$messagesColl = $db->selectCollection('chat_messages');
$now = new \MongoDB\BSON\UTCDateTime($baseMs + count($products) + 100);

// 1) Misafir örnek thread
$threadId = new \MongoDB\BSON\ObjectId();
$threadsColl->insertOne([
    '_id' => $threadId,
    'customerId' => null,
    'guestToken' => 'seed_guest_token_001',
    'subject' => 'Örnek destek talebi (misafir)',
    'status' => 'open',
    'assignedAdminId' => null,
    'lastMessageAt' => $now,
    'createdAt' => $now,
]);
$tidStr = $threadId->__toString();
$messagesColl->insertMany([
    ['threadId' => $tidStr, 'senderRole' => 'customer', 'senderId' => null, 'text' => 'Merhaba, siparişim ne zaman kargoya verilecek?', 'createdAt' => $now],
    ['threadId' => $tidStr, 'senderRole' => 'admin', 'senderId' => $adminId, 'text' => 'Merhaba, siparişiniz yarın kargoya verilecektir.', 'createdAt' => $now],
    ['threadId' => $tidStr, 'senderRole' => 'customer', 'senderId' => null, 'text' => 'Teşekkürler!', 'createdAt' => $now],
]);

// 2) Demo kullanıcılar için 3 destek sohbeti
$demoChats = [
    ['subject' => 'Sipariş iptali', 'status' => 'closed', 'msgs' => [
        ['customer', null, 'Siparişimi iptal etmek istiyorum.'],
        ['admin', '$adminId', 'Sipariş numaranızı paylaşır mısınız?'],
        ['customer', null, '699f... ile biten sipariş.'],
        ['admin', '$adminId', 'İptal işleminiz tamamlandı.'],
    ]],
    ['subject' => 'Kargo takibi', 'status' => 'open', 'msgs' => [
        ['customer', null, 'Siparişim kargoya verildi mi?'],
        ['admin', '$adminId', 'Evet, bugün kargoya verildi. Takip no: 123456789.'],
    ]],
    ['subject' => 'Ürün değişimi', 'status' => 'open', 'msgs' => [
        ['customer', null, 'Yanlış ürün geldi, değişim yapabilir miyim?'],
        ['admin', '$adminId', 'Tabii, destek talebiniz kaydedildi. En kısa sürede dönüş yapacağız.'],
    ]],
];
foreach ($demoChats as $ci => $chat) {
    $custId = $demoUserIds[$ci % count($demoUserIds)];
    $tId = new \MongoDB\BSON\ObjectId();
    $tNow = new \MongoDB\BSON\UTCDateTime($baseMs + 600 + $ci * 50);
    $threadsColl->insertOne([
        '_id' => $tId,
        'customerId' => $custId,
        'guestToken' => null,
        'subject' => $chat['subject'],
        'status' => $chat['status'],
        'assignedAdminId' => $adminId,
        'lastMessageAt' => $tNow,
        'createdAt' => $tNow,
    ]);
    $tStr = $tId->__toString();
    foreach ($chat['msgs'] as $mi => $m) {
        $msgTs = new \MongoDB\BSON\UTCDateTime($baseMs + 600 + $ci * 50 + $mi);
        $senderId = ($m[1] === '$adminId') ? $adminId : null;
        $messagesColl->insertOne([
            'threadId' => $tStr,
            'senderRole' => $m[0],
            'senderId' => $senderId,
            'text' => $m[2],
            'createdAt' => $msgTs,
        ]);
    }
}
echo "  4 chat threads (1 guest + 3 demo user) + messages inserted.\n";

echo "Seed completed.\n";
