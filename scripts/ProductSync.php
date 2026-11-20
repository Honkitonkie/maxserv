
<?php
use MaxServ\Core\Database\Connection;
use MaxServ\App\Products\ProductRepository;

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Initialize database
$connection = new Connection(
    getenv('DB_HOST') ?: 'db',
    getenv('DB_USER') ?: '',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_DATABASE') ?: ''
);


// 1. Initialize database
$db = new ProductRepository($connection);
$pdo = $db->getConnection();

echo "Database connected and tables checked!\n";

// 2. Fetch products from DummyJSON
$url = 'https://dummyjson.com/products?limit=100';

$response = file_get_contents($url);

if ($response === false) {
    die("Could not fetch products from API. Database unchanged.\n");
}

// 3. Decode and validate JSON
$data = json_decode($response, true);

if (
    !is_array($data) ||
    !isset($data['products']) ||
    !is_array($data['products']) ||
    count($data['products']) < 100
) {
    die("API returned an incomplete or invalid response. Database unchanged.\n");
}

$products = $data['products'];

// Validate that every product has a valid ID
foreach ($products as $product) {
    if (!isset($product['id']) || !is_numeric($product['id'])) {
        die("Invalid product data received. Database unchanged.\n");
    }
}

echo "Fetched " . count($products) . " products.\n";

// 4. Prepare INSERT / UPDATE statement
$sql = "
    INSERT INTO products (
        id,
        title,
        description,
        category,
        price,
        discount_percentage,
        rating,
        stock,
        brand,
        sku,
        thumbnail,
        tags,
        images,
        raw_json
    )
    VALUES (
        :id,
        :title,
        :description,
        :category,
        :price,
        :discount_percentage,
        :rating,
        :stock,
        :brand,
        :sku,
        :thumbnail,
        :tags,
        :images,
        :raw_json
    ) AS incoming

    ON DUPLICATE KEY UPDATE
        title = IF(NOT (products.title <=> incoming.title), incoming.title, products.title),
        description = IF(NOT (products.description <=> incoming.description), incoming.description, products.description),
        category = IF(NOT (products.category <=> incoming.category), incoming.category, products.category),
        price = IF(NOT (products.price <=> incoming.price), incoming.price, products.price),
        discount_percentage = IF(NOT (products.discount_percentage <=> incoming.discount_percentage), incoming.discount_percentage, products.discount_percentage),
        rating = IF(NOT (products.rating <=> incoming.rating), incoming.rating, products.rating),
        stock = IF(NOT (products.stock <=> incoming.stock), incoming.stock, products.stock),
        brand = IF(NOT (products.brand <=> incoming.brand), incoming.brand, products.brand),
        sku = IF(NOT (products.sku <=> incoming.sku), incoming.sku, products.sku),
        thumbnail = IF(NOT (products.thumbnail <=> incoming.thumbnail), incoming.thumbnail, products.thumbnail),
        tags = IF(NOT (products.tags <=> incoming.tags), incoming.tags, products.tags),
        images = IF(NOT (products.images <=> incoming.images), incoming.images, products.images),
        raw_json = IF(NOT (products.raw_json <=> incoming.raw_json), incoming.raw_json, products.raw_json)
";

$stmt = $pdo->prepare($sql);

// 5. Synchronise database
try {

    $pdo->beginTransaction();

    $inserted = 0;
    $updated = 0;
    $unchanged = 0;
    $deleted = 0;

    // Keep track of every product ID returned by the API
    $apiProductIds = [];

    foreach ($products as $product) {

        $apiProductIds[] = (int) $product['id'];

        $stmt->execute([
            ':id' => $product['id'],
            ':title' => $product['title'],
            ':description' => $product['description'],
            ':category' => $product['category'],
            ':price' => $product['price'],
            ':discount_percentage' => $product['discountPercentage'],
            ':rating' => $product['rating'],
            ':stock' => $product['stock'],
            ':brand' => $product['brand'] ?? null,
            ':sku' => $product['sku'],
            ':thumbnail' => $product['thumbnail'],
            ':tags' => json_encode($product['tags'] ?? []),
            ':images' => json_encode($product['images'] ?? []),
            ':raw_json' => json_encode($product)
        ]);

        $affectedRows = $stmt->rowCount();

        if ($affectedRows === 1) {
            $inserted++;
            echo "Inserted: " . $product['title'] . "\n";
        } elseif ($affectedRows === 2) {
            $updated++;
            echo "Updated: " . $product['title'] . "\n";
        } else {
            $unchanged++;
            echo "Unchanged: " . $product['title'] . "\n";
        }
    }

    // 6. Delete products no longer present in the API
    // Only delete after all API products have been processed.

    if (!empty($apiProductIds)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($apiProductIds), '?')
        );

        $deleteSql = "
            DELETE FROM products
            WHERE id NOT IN ($placeholders)
        ";

        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute($apiProductIds);

        $deleted = $deleteStmt->rowCount();
    }

    // 7. Commit transaction
    $pdo->commit();

    echo "\n========== SYNC SUMMARY ==========\n";
    echo "Inserted:   " . $inserted . "\n";
    echo "Updated:    " . $updated . "\n";
    echo "Unchanged:  " . $unchanged . "\n";
    echo "Deleted:    " . $deleted . "\n";
    echo "Total API products: " . count($products) . "\n";
    echo "==================================\n";

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die("Synchronization failed: " . $e->getMessage() . "\n");
}