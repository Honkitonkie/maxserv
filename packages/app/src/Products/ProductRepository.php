<?php

declare(strict_types=1);

namespace MaxServ\App\Products;

use MaxServ\Core\Database\Connection;
use PDO;

class ProductRepository
{
    private PDO $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection->getConnection();
        $this->createTables();
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function getProducts(string $sort = 'title_asc'): array
    {
        $sortOptions = [
            'title_asc'   => 'title ASC',
            'title_desc'  => 'title DESC',
            'price_asc'  => 'price ASC',
            'price_desc' => 'price DESC',
            'id_asc'     => 'id ASC',
            'id_desc'    => 'id DESC',
        ];
        $orderBy = $sortOptions[$sort] ?? 'title ASC';
        $sql = "SELECT * FROM products ORDER BY $orderBy";
        $stmt = $this->connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductById(int $id): ?array
    {
        $stmt = $this->connection->prepare(
            "SELECT * FROM products WHERE id = :id"
        );

        $stmt->execute(['id' => $id]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        return $product ?: null;
    }

    public function createTables(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS products (
                id INT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                category VARCHAR(100),
                price DECIMAL(10,2),
                discount_percentage DECIMAL(5,2),
                rating DECIMAL(3,2),
                stock INT,
                brand VARCHAR(150),
                sku VARCHAR(100),
                thumbnail TEXT,
                tags JSON,
                images JSON,
                raw_json LONGTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            )
        ";

        $this->connection->exec($sql);
    }
}
