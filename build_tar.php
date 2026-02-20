<?php
try {
    if (file_exists('moneymotion.tar')) {
        unlink('moneymotion.tar');
    }
    $phar = new PharData('moneymotion.tar');
    $phar->buildFromDirectory('applications/moneymotion');
    echo "Archive created successfully\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
