<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Profile photo of the signed-in user. The browser crops and re-encodes it (which also drops EXIF
 * data such as GPS); the server only accepts real raster images within limits and serves each photo
 * to its owner alone.
 */
class ProfilePhotoController extends Controller
{
    public function show(Request $request): StreamedResponse
    {
        $path = $request->user()?->avatar_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;
        $path = $request->file('photo')->store('avatars', 'local');
        if ($path === false) {
            throw new RuntimeException('Profile photo could not be stored.');
        }
        try {
            $user->forceFill(['avatar_path' => $path])->save();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
        if ($previous !== null) {
            Storage::disk('local')->delete($previous);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto atualizada.']);

        return to_route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path !== null) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto removida.']);

        return to_route('profile.edit');
    }
}
