<?php

return [
    'images_disk' => env('INVENTORY_IMAGES_DISK', 'inventory-images'),
    'images_max_upload_kilobytes' => 20 * 1024,
    'images_max_decoded_pixels' => 40_000_000,
];
