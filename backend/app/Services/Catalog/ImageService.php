<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\UploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-side image pipeline with PHP GD (spec 4.4): JPG/PNG/WebP up to 8 MB, resized to a 2000 px long edge,
 * 400 px thumbnail, random file names under public/uploads/products/<yyyy>/<mm>, at most 8 images per product or variant.
 */
class ImageService
{
    public const MAX_PER_SCOPE = 8;

    private const LONG_EDGE = 2000;

    private const THUMB_EDGE = 400;

    private const MAX_PIXELS = 50_000_000;

    public function __construct(private readonly UploadService $uploads) {}

    public function add(Product $product, ?ProductVariant $variant, UploadedFile $file, string $label = 'other', ?string $alt = null): ProductImage
    {
        $mime = $this->uploads->sniffMime($file);
        if ($mime === null || $file->getSize() === false || $file->getSize() > 8 * 1024 * 1024) {
            throw new ApiException('UPLOAD_INVALID', 422, '', ['file' => ['errors.UPLOAD_INVALID']]);
        }
        if (! in_array($label, ProductImage::LABELS, true)) {
            $label = 'other';
        }
        $scope = ProductImage::query()->where('product_id', $product->id)->where('variant_id', $variant?->id);
        if ($scope->count() >= self::MAX_PER_SCOPE) {
            throw new ApiException('IMAGE_LIMIT', 409, '', [], ['max' => self::MAX_PER_SCOPE]);
        }

        $path = (string) $file->getRealPath();
        $size = @getimagesize($path);
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new ApiException('UPLOAD_INVALID', 422, '', ['file' => ['errors.UPLOAD_INVALID']]);
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            throw new ApiException('UPLOAD_INVALID', 422, '', ['file' => ['errors.UPLOAD_INVALID']]);
        }
        $src = $this->orient($src, $mime, $path);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];

        $dir = 'uploads/products/'.now()->format('Y').'/'.now()->format('m');
        $abs = public_path($dir);
        if (! is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
        $name = Str::lower(Str::random(24));
        $main = $this->resizeTo($src, self::LONG_EDGE);
        $thumb = $this->resizeTo($src, self::THUMB_EDGE);
        $this->write($main, $mime, $abs.DIRECTORY_SEPARATOR.$name.'.'.$ext);
        $this->write($thumb, $mime, $abs.DIRECTORY_SEPARATOR.$name.'_t.'.$ext);
        foreach ([$src, $main, $thumb] as $im) {
            imagedestroy($im);
        }

        return DB::transaction(function () use ($product, $variant, $dir, $name, $ext, $label, $alt): ProductImage {
            $next = ((int) ProductImage::query()->where('product_id', $product->id)->max('sort_order')) + 10;
            $image = ProductImage::query()->create([
                'product_id' => $product->id, 'variant_id' => $variant?->id, 'storage_path' => "{$dir}/{$name}.{$ext}",
                'url' => "/{$dir}/{$name}.{$ext}", 'thumb_url' => "/{$dir}/{$name}_t.{$ext}", 'label' => $label, 'alt_text' => $alt,
                'sort_order' => $next, 'is_primary' => false,
            ]);
            if (! ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->exists()) {
                $this->setPrimary($image);
            }

            return $image->refresh();
        });
    }

    public function setPrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            ProductImage::query()->where('product_id', $image->product_id)->where('is_primary', true)->get()->each(fn ($i) => $i->update(['is_primary' => false]));
            $image->update(['is_primary' => true]);
            Product::query()->whereKey($image->product_id)->update(['primary_image_url' => $image->thumb_url ?? $image->url]);
        });
    }

    /** @param array<int, int> $orderedIds */
    public function reorder(Product $product, array $orderedIds): void
    {
        DB::transaction(function () use ($product, $orderedIds): void {
            foreach (array_values($orderedIds) as $i => $id) {
                ProductImage::query()->where('product_id', $product->id)->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $wasPrimary = $image->is_primary;
            $productId = $image->product_id;
            $this->uploads->deletePublic($image->storage_path);
            $this->uploads->deletePublic($image->thumb_url);
            $image->delete();
            if ($wasPrimary) {
                $next = ProductImage::query()->where('product_id', $productId)->orderBy('sort_order')->first();
                if ($next) {
                    $this->setPrimary($next);
                } else {
                    Product::query()->whereKey($productId)->update(['primary_image_url' => null]);
                }
            }
        });
    }

    /** @param \GdImage $img */
    private function orient($img, string $mime, string $path)
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($angle !== 0) {
            $rot = imagerotate($img, $angle, 0);
            if ($rot !== false) {
                imagedestroy($img);

                return $rot;
            }
        }

        return $img;
    }

    /**
     * @param  \GdImage  $img
     * @return \GdImage
     */
    private function resizeTo($img, int $longEdge)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $longEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, (int) imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    /** @param \GdImage $img */
    private function write($img, string $mime, string $file): void
    {
        match ($mime) {
            'image/jpeg' => $this->writeJpeg($img, $file),
            'image/png' => imagepng($img, $file, 6),
            default => imagewebp($img, $file, 82),
        };
        @chmod($file, 0644);
    }

    /** @param \GdImage $img */
    private function writeJpeg($img, string $file): void
    {
        // JPEG has no alpha: flatten onto white before writing.
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imagejpeg($flat, $file, 85);
        imagedestroy($flat);
    }
}
