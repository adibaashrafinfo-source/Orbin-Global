<?php

use App\Http\Controllers\VerifyController;
use Illuminate\Support\Facades\Route;

// Password reset links point at the SPA route; the SPA posts the token to /api/v1/auth/reset-password.
Route::get('/reset-password/{token}', fn () => redirect('/login?reset='.urlencode(request()->route('token')).'&email='.urlencode((string) request()->query('email'))))
    ->name('password.reset');

// FR-906: public invoice check behind the QR code; shows no personal data.
Route::get('/verify/{token}', VerifyController::class)->middleware('throttle:60,1')->name('verify');

// SPA shell: the built React app lives in public/index.html; every non-API, non-file route serves it.
Route::get('/{any?}', function () {
    $index = public_path('index.html');
    abort_unless(is_file($index), 404);

    return response()->file($index, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
})->where('any', '^(?!api|sanctum|verify|files|uploads|assets|install|up).*$');
