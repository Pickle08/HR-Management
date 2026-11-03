<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRegions extends Command
{
    protected $signature = 'regions:import
        {file : Path ke file CSV wilayah}
        {--allow-partial : Izinkan file kecil (hanya untuk uji)}';

    protected $description = 'Impor data wilayah Indonesia (provinsi sampai desa/kelurahan) dari CSV ke database lokal';

    private const MIN_PROVINCES = 30;

    // 300 baris x 3 kolom = 900 parameter, aman untuk batas parameter SQLite lama.
    private const CHUNK = 300;

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File tidak ditemukan atau tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $first = fgets($handle);

        if ($first === false) {
            fclose($handle);
            $this->error('File kosong.');

            return self::FAILURE;
        }

        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $map = $this->columnMap(str_getcsv($first, $delimiter, '"', ''));

        if ($map === null) {
            fclose($handle);
            $this->error('Kolom CSV tidak dikenali. Dibutuhkan: KODE/NAMA PROVINSI, KABUPATEN, KECAMATAN, serta KODE DESA dan NAMA KELURAHAN/DESA.');

            return self::FAILURE;
        }

        $provinces = $regencies = $districts = $villages = [];
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $get = fn (string $key) => trim((string) ($row[$map[$key]] ?? ''));
            $name = fn (string $key) => mb_substr(preg_replace('/\s+/', ' ', $get($key)), 0, 150);

            $p = $this->code($get('province_code'), 2);
            $r = $this->code($get('regency_code'), 4);
            $d = $this->code($get('district_code'), 6);
            $v = $this->code($get('village_code'), 10);

            $names = [$name('province_name'), $name('regency_name'), $name('district_name'), $name('village_name')];

            // Baris yang rusak atau tidak konsisten dilewati, bukan diimpor setengah-setengah.
            if (
                in_array(null, [$p, $r, $d, $v], true)
                || in_array('', $names, true)
                || ! str_starts_with($r, $p.'.')
                || ! str_starts_with($d, $r.'.')
                || ! str_starts_with($v, $d.'.')
            ) {
                $skipped++;

                continue;
            }

            $provinces[$p] = ['code' => $p, 'name' => $names[0]];
            $regencies[$r] = ['code' => $r, 'province_code' => $p, 'name' => $names[1]];
            $districts[$d] = ['code' => $d, 'regency_code' => $r, 'name' => $names[2]];
            $villages[$v] = ['code' => $v, 'district_code' => $d, 'name' => $names[3]];
        }

        fclose($handle);

        if (count($provinces) < self::MIN_PROVINCES && ! $this->option('allow-partial')) {
            $this->error(sprintf(
                'File hanya berisi %d provinsi, jauh di bawah yang wajar (%d+). Impor dibatalkan, tidak ada data yang diubah.',
                count($provinces),
                self::MIN_PROVINCES,
            ));

            return self::FAILURE;
        }

        // Semua atau tidak sama sekali. Yang sudah ada di database tidak dihapus.
        DB::transaction(function () use ($provinces, $regencies, $districts, $villages) {
            $this->upsert('provinces', $provinces, ['name']);
            $this->upsert('regencies', $regencies, ['province_code', 'name']);
            $this->upsert('districts', $districts, ['regency_code', 'name']);
            $this->upsert('villages', $villages, ['district_code', 'name']);
        });

        $this->table(['Tingkat', 'Jumlah di file'], [
            ['Provinsi', count($provinces)],
            ['Kabupaten/Kota', count($regencies)],
            ['Kecamatan', count($districts)],
            ['Desa/Kelurahan', count($villages)],
        ]);

        if ($skipped > 0) {
            $this->warn("{$skipped} baris dilewati karena kode atau nama tidak valid.");
        }

        return self::SUCCESS;
    }

    /** @return array<string, int>|null */
    private function columnMap(array $header): ?array
    {
        $normalized = array_map(
            fn ($h) => strtoupper(preg_replace('/\s+/', ' ', trim((string) $h))),
            $header,
        );

        $find = function (array $prefixes) use ($normalized) {
            foreach ($normalized as $index => $name) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        return $index;
                    }
                }
            }

            return null;
        };

        $map = [
            'province_code' => $find(['KODE PROVINSI']),
            'province_name' => $find(['NAMA PROVINSI']),
            'regency_code' => $find(['KODE KABUPATEN']),
            'regency_name' => $find(['NAMA KABUPATEN']),
            'district_code' => $find(['KODE KECAMATAN']),
            'district_name' => $find(['NAMA KECAMATAN']),
            'village_code' => $find(['KODE DESA', 'KODE KELURAHAN']),
            'village_name' => $find(['NAMA KELURAHAN', 'NAMA DESA']),
        ];

        return in_array(null, $map, true) ? null : $map;
    }

    /** Terima kode dengan atau tanpa titik, keluarkan selalu berbentuk 32 / 32.73 / 32.73.01 / 32.73.01.1002. */
    private function code(string $value, int $digits): ?string
    {
        $only = preg_replace('/\D/', '', $value);

        if (strlen($only) !== $digits) {
            return null;
        }

        return match ($digits) {
            2 => $only,
            4 => substr($only, 0, 2).'.'.substr($only, 2, 2),
            6 => substr($only, 0, 2).'.'.substr($only, 2, 2).'.'.substr($only, 4, 2),
            10 => substr($only, 0, 2).'.'.substr($only, 2, 2).'.'.substr($only, 4, 2).'.'.substr($only, 6, 4),
        };
    }

    private function upsert(string $table, array $rows, array $update): void
    {
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, ['code'], $update);
        }
    }
}
