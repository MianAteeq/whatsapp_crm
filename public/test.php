<?php
echo "PHP_BINARY: " . PHP_BINARY . "\n";
echo "extension_dir: " . ini_get('extension_dir') . "\n";
echo "Loaded INI: " . php_ini_loaded_file() . "\n";
echo "PDO: " . (extension_loaded('pdo') ? 'yes' : 'no') . "\n";
echo "PDO_SQLITE: " . (extension_loaded('pdo_sqlite') ? 'yes' : 'no') . "\n";
echo "SQLITE3: " . (extension_loaded('sqlite3') ? 'yes' : 'no') . "\n";
