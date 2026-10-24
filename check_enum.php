<?php
require __DIR__.'/vendor/autoload.php';
try {
    $e = new \App\Enums\VideoRenderStatus;
    echo "Loaded OK\n";
} catch (\Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
try {
    $e = new \App\Enums\VideoExportStatus;
    echo "Export OK\n";
} catch (\Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
try {
    $m = new \App\Models\VideoExport;
    echo "Model OK\n";
} catch (\Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
