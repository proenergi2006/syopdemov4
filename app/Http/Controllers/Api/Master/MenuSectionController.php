<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Kelola section sidebar
|--------------------------------------------------------------------------
| Section adalah judul kelompok di sidebar -- Finance, Procurement, dan
| seterusnya. Seluruhnya data, dan inilah pintunya bagi Super Admin.
|
| MENUMPANG IZIN auth_menu.*
|
| Section tidak punya permission sendiri, dan itu disengaja. Ia bagian dari
| penataan menu, bukan modul tersendiri: siapa yang boleh menata menu sudah
| pasti boleh menata pengelompokannya. Permission baru hanya akan menambah
| satu lagi yang harus diberikan satu per satu ke tiap role, untuk hak yang
| sebenarnya sudah dimiliki.
|--------------------------------------------------------------------------
*/
class MenuSectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.view',
            message: 'Anda tidak memiliki akses melihat section menu.',
        );

        $sections = MenuSection::query()
            ->withCount('menus')
            ->orderBy('order_no')
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'order_no', 'is_active']);

        return response()->json([
            'success' => true,
            'message' => 'Data section menu berhasil dimuat.',
            'data' => $sections,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.create',
            message: 'Anda tidak memiliki akses membuat section menu.',
        );

        $validated = $this->validatePayload($request);

        $section = MenuSection::create([
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
            'order_no' => (int) ($validated['order_no'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Section menu berhasil dibuat.',
            'data' => $section,
        ], 201);
    }

    public function update(Request $request, MenuSection $menuSection): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.update',
            message: 'Anda tidak memiliki akses mengubah section menu.',
        );

        $validated = $this->validatePayload($request, $menuSection);

        $menuSection->update([
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
            'order_no' => (int) ($validated['order_no'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Section menu berhasil diperbarui.',
            'data' => $menuSection->fresh(),
        ]);
    }

    /**
     * Membubarkan sebuah section.
     *
     * Menunya TIDAK ikut terhapus -- yang dibubarkan kelompoknya, bukan
     * isinya. Seluruh menu anggotanya dikembalikan ke bagian tanpa section,
     * yaitu deret teratas sidebar, dan Super Admin tinggal memindahkannya.
     *
     * Dilepas lebih dulu secara eksplisit, tidak diserahkan ke nullOnDelete
     * milik foreign key: section ini dihapus secara lunak, dan penghapusan
     * lunak tidak pernah menyentuh baris menunya. Tanpa pelepasan ini,
     * menu-menunya akan menunjuk section yang sudah tidak ada.
     */
    public function destroy(Request $request, MenuSection $menuSection): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'auth_menu.delete',
            message: 'Anda tidak memiliki akses menghapus section menu.',
        );

        $dilepas = Menu::query()
            ->where('menu_section_id', $menuSection->id)
            ->update(['menu_section_id' => null]);

        $menuSection->delete();

        return response()->json([
            'success' => true,

            'message' => $dilepas > 0
                ? "Section dibubarkan. {$dilepas} menu dikembalikan ke bagian tanpa section."
                : 'Section berhasil dibubarkan.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?MenuSection $section = null): array
    {
        return $request->validate([
            /*
            | Nama wajib unik supaya tidak ada dua judul kembar di sidebar --
            | pembacanya tidak punya cara membedakan yang mana isi yang mana.
            | Yang sudah dibubarkan tidak ikut dihitung: namanya boleh dipakai
            | ulang.
            */
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('menu_sections', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($section?->id),
            ],

            'icon' => ['nullable', 'string', 'max:100'],
            'order_no' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** Disamakan dengan MenuController: 403, bukan 422. */
    private function ensurePermission(
        Request $request,
        string $permission,
        string $message,
    ): void {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            abort(403, $message);
        }
    }
}
