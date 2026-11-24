<?php
/**
 * Minimal PSR-4 autoloader for the three vendored libraries (no Composer needed on XAMPP).
 *   opis/json-schema  (Apache-2.0)  JSON Schema 2020-12 validator
 *   opis/string       (Apache-2.0)  dependency of json-schema
 *   opis/uri          (Apache-2.0)  dependency of json-schema
 * Licences are kept in each package folder.
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'Opis\\JsonSchema\\' => __DIR__ . '/opis/json-schema/src/',
        'Opis\\String\\'     => __DIR__ . '/opis/string/src/',
        'Opis\\Uri\\'        => __DIR__ . '/opis/uri/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
