<?php

namespace Database\Seeders;

use App\Actions\UploadItemImage;
use App\Models\Item;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class ItemImageSeeder extends Seeder
{
    public function run(UploadItemImage $upload): void
    {
        $item = Item::query()->with('household.users')->first();
        $uploader = $item?->household?->users?->first();

        if ($item === null || $uploader === null || $item->images()->exists()) {
            return;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'inventory-item-image-');
        if ($temporaryPath === false) {
            return;
        }

        $image = imagecreatetruecolor(900, 600);
        $background = imagecolorallocate($image, 238, 232, 218);
        $object = imagecolorallocate($image, 74, 105, 91);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 240, 110, 660, 490, $object);
        imagepng($image, $temporaryPath);
        imagedestroy($image);

        try {
            $upload->upload(
                $item->household,
                $item,
                $uploader,
                new UploadedFile($temporaryPath, 'sample-item.png', 'image/png', UPLOAD_ERR_OK, true),
                'Sample inventory photo',
            );
        } finally {
            @unlink($temporaryPath);
        }
    }
}
