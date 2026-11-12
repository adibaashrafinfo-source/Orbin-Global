<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanyProfileRequest;
use App\Http\Requests\Settings\UploadBrandImageRequest;
use App\Http\Resources\CompanyProfileResource;
use App\Models\CompanyProfile;
use App\Services\UploadService;
use Illuminate\Support\Facades\DB;

/** FR-104 Company profile (singleton) with logo and signature upload. */
class CompanyProfileController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    public function show(): CompanyProfileResource
    {
        return new CompanyProfileResource(CompanyProfile::current());
    }

    public function update(UpdateCompanyProfileRequest $request): CompanyProfileResource
    {
        $profile = DB::transaction(function () use ($request): CompanyProfile {
            $profile = CompanyProfile::query()->lockForUpdate()->firstOrFail();
            $profile->fill($request->validated())->save();

            return $profile;
        });

        return new CompanyProfileResource($profile);
    }

    public function uploadLogo(UploadBrandImageRequest $request): CompanyProfileResource
    {
        $stored = $this->uploads->storeBrandImage($request->file('file'), 'logo');
        $profile = CompanyProfile::current();
        $previous = $profile->logo_storage_path;
        $profile->forceFill(['logo_url' => $stored['url'], 'logo_storage_path' => $stored['path']])->save();
        if ($previous && str_starts_with($previous, 'uploads/')) {
            $this->uploads->deletePublic($previous);
        }

        return new CompanyProfileResource($profile);
    }

    public function uploadSignature(UploadBrandImageRequest $request): CompanyProfileResource
    {
        $stored = $this->uploads->storeBrandImage($request->file('file'), 'signature');
        $profile = CompanyProfile::current();
        $previous = $profile->signature_url;
        $profile->forceFill(['signature_url' => $stored['url']])->save();
        if ($previous && str_starts_with($previous, '/uploads/')) {
            $this->uploads->deletePublic($previous);
        }

        return new CompanyProfileResource($profile);
    }
}
