<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'KODE PROVINSI,NAMA PROVINSI,KODE KABUPATEN,NAMA KABUPATEN,KODE KECAMATAN,NAMA KECAMATAN,KODE DESA,NAMA KELURAHAN/DESA/DESA ADAT';

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function makeUser(Role $role): User
    {
        return User::forceCreate([
            'name' => $role->value,
            'email' => "{$role->value}@t.test",
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function csv(array $lines, ?string $header = self::HEADER): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wilayah-test-'.uniqid().'.csv';

        file_put_contents($path, implode("\n", array_filter([$header, ...$lines], fn ($l) => $l !== null))."\n");

        $this->files[] = $path;

        return $path;
    }

    private function seedRegions(): void
    {
        DB::table('provinces')->insert([
            ['code' => '35', 'name' => 'Jawa Timur'],
            ['code' => '32', 'name' => 'Jawa Barat'],
        ]);

        DB::table('regencies')->insert([
            ['code' => '32.73', 'province_code' => '32', 'name' => 'Kota Bandung'],
            ['code' => '32.01', 'province_code' => '32', 'name' => 'Kabupaten Bogor'],
            ['code' => '35.77', 'province_code' => '35', 'name' => 'Kota Madiun'],
        ]);

        DB::table('districts')->insert([
            ['code' => '32.73.01', 'regency_code' => '32.73', 'name' => 'Sukasari'],
            ['code' => '32.73.02', 'regency_code' => '32.73', 'name' => 'Coblong'],
            ['code' => '35.77.01', 'regency_code' => '35.77', 'name' => 'Manguharjo'],
        ]);

        DB::table('villages')->insert([
            ['code' => '32.73.01.1002', 'district_code' => '32.73.01', 'name' => 'Gegerkalong'],
            ['code' => '32.73.01.1001', 'district_code' => '32.73.01', 'name' => 'Isola'],
            ['code' => '35.77.01.1001', 'district_code' => '35.77.01', 'name' => 'Winongo'],
        ]);
    }

    private function counts(): array
    {
        return [
            'provinces' => DB::table('provinces')->count(),
            'regencies' => DB::table('regencies')->count(),
            'districts' => DB::table('districts')->count(),
            'villages' => DB::table('villages')->count(),
        ];
    }

    public function test_requires_login_and_every_role_can_read_with_private_cache(): void
    {
        $this->seedRegions();

        $this->getJson('/api/regions/provinces')->assertUnauthorized();

        foreach (Role::cases() as $role) {
            $res = $this->actAs($this->makeUser($role))->getJson('/api/regions/provinces')->assertOk();

            $cacheControl = (string) $res->headers->get('Cache-Control');

            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringNotContainsString('public', $cacheControl);
        }
    }

    public function test_cascading_lists_are_scoped_to_their_parent(): void
    {
        $this->seedRegions();
        $hr = $this->makeUser(Role::Hr);

        $names = fn (string $url) => collect($this->actAs($hr)->getJson($url)->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        $this->assertSame(['Jawa Barat', 'Jawa Timur'], $names('/api/regions/provinces'));
        $this->assertSame(['Kabupaten Bogor', 'Kota Bandung'], $names('/api/regions/regencies?province_code=32'));
        $this->assertSame(['Kota Madiun'], $names('/api/regions/regencies?province_code=35'));
        $this->assertSame(['Coblong', 'Sukasari'], $names('/api/regions/districts?regency_code=32.73'));
        $this->assertSame(['Gegerkalong', 'Isola'], $names('/api/regions/villages?district_code=32.73.01'));
        $this->assertSame(['Winongo'], $names('/api/regions/villages?district_code=35.77.01'));

        $this->actAs($hr)->getJson('/api/regions/provinces')
            ->assertJsonPath('data.0', ['code' => '32', 'name' => 'Jawa Barat']);
    }

    public function test_parent_code_is_required_and_must_exist(): void
    {
        $this->seedRegions();
        $hr = $this->makeUser(Role::Hr);

        $cases = [
            '/api/regions/regencies' => 'province_code',
            '/api/regions/districts' => 'regency_code',
            '/api/regions/villages' => 'district_code',
            '/api/regions/regencies?province_code=99' => 'province_code',
            '/api/regions/districts?regency_code=32.99' => 'regency_code',
            '/api/regions/villages?district_code=abc' => 'district_code',
            '/api/regions/villages?district_code[]=32.73.01' => 'district_code',
        ];

        foreach ($cases as $url => $field) {
            $this->actAs($hr)->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->actAs($hr)->getJson('/api/regions/countries')->assertNotFound();
    }

    public function test_import_loads_normalizes_codes_and_is_idempotent(): void
    {
        $path = $this->csv([
            '32,Jawa Barat,32.73,Kota Bandung,32.73.01,Sukasari,32.73.01.1002,Gegerkalong',
            '32,Jawa Barat,32.73,Kota Bandung,32.73.01,Sukasari,32.73.01.1003,Sukarasa',
            '35,Jawa Timur,3577,Kota Madiun,357701,Manguharjo,3577011001,Winongo',
            // Kode desa salah panjang: dilewati.
            '32,Jawa Barat,32.73,Kota Bandung,32.73.01,Sukasari,32.73.01.99,Pendek',
            // Kabupaten tidak berada di bawah provinsinya: dilewati, tidak menimpa data benar.
            '32,Jawa Barat,35.77,Kota Madiun,35.77.01,Manguharjo,35.77.01.1001,Salah',
        ]);

        $this->artisan('regions:import', ['file' => $path, '--allow-partial' => true])->assertExitCode(0);

        $this->assertSame(
            ['provinces' => 2, 'regencies' => 2, 'districts' => 2, 'villages' => 3],
            $this->counts()
        );

        $this->assertSame('Winongo', DB::table('villages')->where('code', '35.77.01.1001')->value('name'));
        $this->assertSame('35.77.01', DB::table('villages')->where('code', '35.77.01.1001')->value('district_code'));
        $this->assertSame('Kota Madiun', DB::table('regencies')->where('code', '35.77')->value('name'));

        // Impor ulang dengan satu nama berubah: tidak menggandakan, nama diperbarui.
        $again = $this->csv([
            '32,Jawa Barat,32.73,Kota Bandung,32.73.01,Sukasari,32.73.01.1002,Gegerkalong Baru',
            '35,Jawa Timur,3577,Kota Madiun,357701,Manguharjo,3577011001,Winongo',
        ]);

        $this->artisan('regions:import', ['file' => $again, '--allow-partial' => true])->assertExitCode(0);

        $this->assertSame(
            ['provinces' => 2, 'regencies' => 2, 'districts' => 2, 'villages' => 3],
            $this->counts()
        );
        $this->assertSame('Gegerkalong Baru', DB::table('villages')->where('code', '32.73.01.1002')->value('name'));
    }

    public function test_import_refuses_bad_input_and_partial_files(): void
    {
        $this->artisan('regions:import', ['file' => '/tidak/ada/wilayah.csv'])->assertExitCode(1);

        $wrongHeader = $this->csv(['32,Jawa Barat'], 'A,B');
        $this->artisan('regions:import', ['file' => $wrongHeader, '--allow-partial' => true])->assertExitCode(1);

        // File kecil tanpa --allow-partial ditolak, supaya file salah tidak diimpor diam-diam.
        $small = $this->csv([
            '32,Jawa Barat,32.73,Kota Bandung,32.73.01,Sukasari,32.73.01.1002,Gegerkalong',
        ]);
        $this->artisan('regions:import', ['file' => $small])->assertExitCode(1);

        $this->assertSame(
            ['provinces' => 0, 'regencies' => 0, 'districts' => 0, 'villages' => 0],
            $this->counts()
        );
    }
}
