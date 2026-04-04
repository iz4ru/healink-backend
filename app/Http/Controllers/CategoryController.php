<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function store(Request $request) {
        $cleanName = ucwords(strtolower(trim($request->name)));
        $request->merge(['name' => $cleanName]);

        $data = $request->validate(
        [
            'name' => 'required|string|max:255|unique:categories,name'
        ],
        [
            'name.required' => 'Nama kategori harus diisi.',
            'name.string' => 'Nama kategori tidak valid.',
            'name.max' => 'Nama kategori sudah mencapai batas karakter maksimal.',
            'name.unique' => 'Nama kategori ini sudah terdaftar.',
        ]);

        $user = Auth::user();

        DB::transaction(function () use ($data, $user, &$item) {
            $item = Category::create(['name' => $data['name']]);

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Tambah kategori',
                'detail' => $user->name . ' telah menambahkan kategori ' . $item->name,
            ]);
        });

        Cache::forget('categories_all');

        return response()->json(['success' => true, 'message' => 'Berhasil ditambahkan', 'data' => $item]);
    }

    public function update(Request $request, $id) {
        $cleanName = ucwords(strtolower(trim($request->name)));
        $request->merge(['name' => $cleanName]);

        $item = Category::findOrFail($id);

        $data = $request->validate(
        [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->ignore($item->id),
            ]
        ],
        [
            'name.required' => 'Nama kategori harus diisi.',
            'name.string' => 'Nama kategori tidak valid.',
            'name.max' => 'Nama kategori sudah mencapai batas karakter maksimal.',
            'name.unique' => 'Nama kategori ini sudah terdaftar.',
        ]);

        $user = Auth::user();

        $oldName = $item->name;

        DB::transaction(function () use ($id, $data, $user, $item, $oldName) {
            $item->update(['name' => $data['name']]);

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Ubah nama kategori',
                'detail' => $user->name . ' mengubah nama kategori ' . $oldName . ' menjadi ' . $item->name . ' (ID: ' . $id . ')',
            ]);
        });

        Cache::forget('categories_all');

        return response()->json(['success' => true, 'message' => 'Berhasil diperbarui']);
    }

    public function destroy($id) {
        $user = Auth::user();

        $item = Category::findOrFail($id);
        $name = $item->name;

        DB::transaction(function () use ($id, $user, $item, $name) {
            $item->delete();

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Hapus kategori',
                'detail' => $user->name . ' menghapus kategori ' . $name . ' (ID: ' . $id . ')',
            ]);
        });

        Cache::forget('categories_all');

        return response()->json(['success' => true, 'message' => 'Berhasil dihapus']);
    }
}
