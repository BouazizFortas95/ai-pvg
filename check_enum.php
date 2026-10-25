<?php

use App\Enums\VideoExportStatus;
use App\Enums\VideoRenderStatus;
use App\Models\VideoExport;

require __DIR__.'/vendor/autoload.php';
try {
    $e = new VideoRenderStatus;
    echo "Loaded OK\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
try {
    $e = new VideoExportStatus;
    echo "Export OK\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
try {
    $m = new VideoExport;
    echo "Model OK\n";
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage()."\n";
}
