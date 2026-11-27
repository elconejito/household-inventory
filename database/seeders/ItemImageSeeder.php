<?php

namespace Database\Seeders;

use App\Actions\UploadItemImage;
use App\Models\Household;
use App\Models\Item;
use App\Models\User;
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

        $this->createSampleImage($upload, $item, $uploader);
    }

    public function seedDemoImage(Household $household, User $owner): void
    {
        $paper = $household->items()->where('name', 'Toilet Paper')->first();

        if ($paper !== null && ! $paper->images()->withTrashed()->exists()) {
            $this->createSampleImage(app(UploadItemImage::class), $paper, $owner);
        }
    }

    private function createSampleImage(UploadItemImage $upload, Item $item, User $uploader): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'inventory-item-image-');
        if ($temporaryPath === false) {
            return;
        }

        $image = imagecreatetruecolor(900, 600);
        $background = imagecolorallocate($image, 238, 232, 218);
        $object = imagecolorallocate($image, 74, 105, 91);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 240, 110, 660, 490, $object);
        imagestring($image, 5, 260, 280, 'STORAGE LABEL', $background);
        imagestring($image, 5, 260, 310, substr($item->name, 0, 40), $background);
        imagepng($image, $temporaryPath);
        imagedestroy($image);

        try {
            $upload->upload(
                $item->household,
                $item,
                $uploader,
                new UploadedFile($temporaryPath, 'sample-item.png', 'image/png', UPLOAD_ERR_OK, true),
                'Storage label',
            );
        } finally {
            @unlink($temporaryPath);
        }
    }
}
