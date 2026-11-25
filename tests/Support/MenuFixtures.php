<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class MenuFixtures
{
    public static function category(TestCase $test, string $base, string $name = 'Breakfast', bool $visible = true): string
    {
        return $test->postJson($base.'/categories', [
            'name' => ['en' => $name, 'ar' => 'الإفطار'], 'isVisible' => $visible,
        ])->assertCreated()->json('data.id');
    }

    public static function product(TestCase $test, string $base, string $category, array $changes = []): string
    {
        return $test->postJson($base.'/products', $changes + [
            'name' => ['en' => 'Fresh beans', 'ar' => 'فول طازج'],
            'categoryId' => $category, 'priceAmount' => '17.5', 'isVisible' => true,
        ])->assertCreated()->json('data.id');
    }

    public static function publish(TestCase $test, string $base): string
    {
        return $test->postJson($base.'/publication', [
            'expectedDraftRevision' => $test->getJson($base.'/menu-settings')->assertOk()->json('data.draftRevision'),
        ])->assertOk()->json('data.publicationRevision');
    }

    public static function upload(TestCase $test, string $base, UploadedFile $file)
    {
        return $test->post($base.'/media', ['file' => $file], [
            'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=fixture-boundary',
        ]);
    }

    public static function image(string $format = 'png'): string
    {
        $image = imagecreatetruecolor(640, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 80, 40));
        ob_start();
        try {
            match ($format) {
                'jpeg' => imagejpeg($image),
                'webp' => imagewebp($image),
                default => imagepng($image),
            };

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
