<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    /** A real PNG without GD (absent in the container): one gray row repeated. */
    private function png(int $size): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat("\0".str_repeat("\x80", $size), $size);
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 0, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($rows)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('foto.png', $bytes);
    }

    public function test_the_owner_uploads_replaces_sees_and_removes_the_photo(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.photo.update'), ['photo' => $this->png(128)])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $first = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($first);
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('auth.user.avatar', $user->fresh()->avatar)->missing('auth.user.avatar_path'));
        $this->get(route('profile.photo.show'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        $this->post(route('profile.photo.update'), ['photo' => $this->png(96)])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($first);

        $this->delete(route('profile.photo.destroy'))->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));
        $this->get(route('profile.photo.show'))->assertNotFound();
    }

    public function test_only_real_raster_images_within_limits_are_accepted(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('foto.png', 'not an image')])->assertSessionHasErrors('photo');
        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertSessionHasErrors('photo');
        $this->post(route('profile.photo.update'), ['photo' => $this->png(32)])->assertSessionHasErrors('photo');
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_each_photo_is_served_only_to_its_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $this->actingAs($owner)->post(route('profile.photo.update'), ['photo' => $this->png(128)]);

        $this->actingAs(User::factory()->create())->get(route('profile.photo.show'))->assertNotFound();
        auth()->logout();
        $this->get(route('profile.photo.show'))->assertRedirect(route('login'));
    }
}
