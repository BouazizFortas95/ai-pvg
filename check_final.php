<?php

require __DIR__.'/vendor/autoload.php';
$p = new PDO('sqlite:database/database.sqlite');
foreach (['youtube_publishing_states', 'video_exports', 'video_renders', 'news'] as $t) {
    echo $t.'='.$p->query('SELECT count(*) FROM '.$t)->fetchColumn().' ';
}
echo "\n";
