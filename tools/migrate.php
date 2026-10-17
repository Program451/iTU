<?php
// Additive setup for a new database or an existing installation. Never drops data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/public/database.php';
require dirname(__DIR__).'/public/schema.php';
try {
    $pdo = appDatabase();
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    appSchema($pdo);
    echo "Database schema is ready. Existing records preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Schema setup failed: ".$error->getMessage()."\n");
    exit(1);
}
