<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Toast;
use App\Core\Website\SiteBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The logo, the logo for dark backgrounds and the favicon an operator uploads for the website and the app
 * (docs/WEBSITE.md). Raster images only; removing one brings back the built-in image.
 */
final class BrandController
{
    public function store(Request $request, string $asset, SiteBrand $brand, AuditLogger $audit): RedirectResponse
    {
        abort_unless(in_array($asset, SiteBrand::ASSETS, true), 404);

        $input = $request->validate([
            'file' => $asset === 'favicon'
                ? ['required', 'file', 'mimes:png,ico', 'max:512']
                : ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_height=32,max_width=4000,max_height=2000'],
        ]);

        /** @var UploadedFile $upload */
        $upload = $input['file'];

        // A favicon is shown in a square: a PNG one must be square and large enough to stay sharp.
        if ($asset === 'favicon' && $upload->guessExtension() === 'png') {
            [$width, $height] = getimagesize((string) $upload->getRealPath()) ?: [0, 0];

            if ($width !== $height || $width < 32) {
                throw ValidationException::withMessages(['file' => __('Use a square image of at least 32 by 32 pixels.')]);
            }
        }

        $file = $brand->store($asset, $upload);
        $audit->record('platform.website.brand_updated', null, ['asset' => $asset, 'file' => $file]);

        Toast::success(__('Image saved.'));

        return back();
    }

    public function destroy(string $asset, SiteBrand $brand, AuditLogger $audit): RedirectResponse
    {
        abort_unless(in_array($asset, SiteBrand::ASSETS, true), 404);

        if ($brand->file($asset) !== null) {
            $brand->remove($asset);
            $audit->record('platform.website.brand_removed', null, ['asset' => $asset]);
        }

        Toast::success(__('The built-in image is back.'));

        return back();
    }
}
