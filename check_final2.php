<?php
require __DIR__.'/vendor/autoload.php';
$p = new PDO('sqlite:database/database.sqlite');
foreach (['news','video_renders','youtube_publishing_states'] as $t) {
    echo $t.'='.$p->query('SELECT count(*) FROM '.$t)->fetchColumn().' ';
}
echo "\n";
