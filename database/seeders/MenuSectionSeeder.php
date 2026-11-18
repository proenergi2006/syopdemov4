<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuSection;
use Illuminate\Database\Seeder;

/*
|--------------------------------------------------------------------------
| Section awal untuk sidebar
|--------------------------------------------------------------------------
| Lima kelompok, dan penempatan menu induk yang sudah ada ke dalamnya.
|
| INI HANYA ISIAN AWAL, BUKAN ATURAN.
|
| Seluruhnya data: Super Admin bisa mengganti namanya, menambah section baru,
| memindahkan menu antar section, atau membubarkan salah satunya lewat layar
| Menu Management. Seeder ini cuma memberi titik berangkat supaya sidebar
| tidak perlu ditata dari nol.
|
| DASHBOARD SENGAJA TIDAK DIBERI SECTION. Ia digambar di paling atas, sebelum
| heading pertama -- tempat yang memang lazim untuknya.
|
| MENU DICARI LEWAT NAMA, dan yang tidak ketemu dilewati tanpa membatalkan
| apa pun. Nama menu bisa saja sudah diubah seseorang; itu bukan alasan untuk
| menggagalkan penempatan menu-menu lainnya.
|
| AMAN DIJALANKAN BERULANG. Section yang sudah ada diperbarui, bukan
| digandakan, dan penempatan menu hanya ditulis bila memang berubah.
|--------------------------------------------------------------------------
*/
class MenuSectionSeeder extends Seeder
{
    /**
     * Section beserta isinya.
     *
     * Kuncinya nama section; isinya nama menu induk yang masuk ke dalamnya.
     *
     * @var array<string, array{icon: string, menus: array<int, string>}>
     */
    private const SECTIONS = [
        'Finance' => [
            'icon' => 'mdi-cash-multiple',
            'menus' => ['Pengajuan Dana'],
        ],

        'Procurement' => [
            'icon' => 'mdi-cart-outline',
            'menus' => ['Non Stock', 'Stock'],
        ],

        'General Affair' => [
            'icon' => 'mdi-airplane-takeoff',
            'menus' => ['Perjalanan Dinas'],
        ],

        'Master Data' => [
            'icon' => 'mdi-database-outline',
            'menus' => ['Master'],
        ],

        'Sistem' => [
            'icon' => 'mdi-cog-outline',
            'menus' => ['Auth', 'Monitoring'],
        ],
    ];

    public function run(): void
    {
        $urutan = 10;

        foreach (self::SECTIONS as $nama => $isi) {
            $section = MenuSection::withTrashed()->firstWhere('name', $nama);

            if ($section) {
                $section->restore();

                $section->fill([
                    'icon' => $isi['icon'],
                    'order_no' => $urutan,
                ])->save();

                $this->command?->info("Section \"{$nama}\" diperbarui.");
            } else {
                $section = MenuSection::create([
                    'name' => $nama,
                    'icon' => $isi['icon'],
                    'order_no' => $urutan,
                    'is_active' => true,
                ]);

                $this->command?->info("Section \"{$nama}\" dibuat.");
            }

            $this->tempatkanMenu($section, $isi['menus']);

            $urutan += 10;
        }

        $tanpaSection = Menu::query()
            ->whereNull('parent_id')
            ->whereNull('menu_section_id')
            ->pluck('name');

        $this->command?->info(
            'Menu induk tanpa section: '
            . ($tanpaSection->isEmpty() ? '-' : $tanpaSection->implode(', ')),
        );
    }

    /**
     * @param  array<int, string>  $namaMenu
     */
    private function tempatkanMenu(MenuSection $section, array $namaMenu): void
    {
        foreach ($namaMenu as $nama) {
            $menu = Menu::query()
                ->whereNull('parent_id')
                ->where('name', $nama)
                ->first();

            if (!$menu) {
                $this->command?->warn("  menu induk \"{$nama}\" tidak ditemukan, dilewati.");

                continue;
            }

            if ((int) $menu->menu_section_id === (int) $section->id) {
                continue;
            }

            $menu->menu_section_id = $section->id;
            $menu->save();

            $this->command?->info("  \"{$nama}\" masuk ke section \"{$section->name}\".");
        }
    }
}
