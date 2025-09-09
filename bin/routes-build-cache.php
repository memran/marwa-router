#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Marwa\Router\RouterFactory;

$factory = new RouterFactory();
$factory->registerFromDirectories([__DIR__ . '/../examples/controllers']); // adjust
$registry = var_export($factory->routes(), true);

@mkdir(__DIR__ . '/../var/cache', 0777, true);
file_put_contents(__DIR__ . '/../var/cache/routes.php', "<?php\nreturn {$registry};\n");
echo "Wrote var/cache/routes.php\n";
