<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function store(Request $request) {
        $cleanName = ucwords(strtolower(trim($request->name)));
        $request->merge(['name' => $cleanName]);

        $data = $request->validate(
        [
            'name' => 'required|string|max:255|unique:units,name'
        ],
        [
            'name.required' => 'Nama jenis satuan harus diisi.',
            'name.string' => 'Nama jenis satuan tidak valid.',
            'name.max' => 'Nama jenis satuan sudah mencapai batas karakter maksimal.',
            'name.unique' => 'Nama jenis satuan ini sudah terdaftar.',
        ]);

        $user = Auth::user();

        DB::transaction(function () use ($data, $user, &$item) {
            $item = Unit::create(['name' => $data['name']]);

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Tambah jenis satuan',
                'detail' => $user->name . ' telah menambahkan jenis satuan ' . $item->name,
            ]);
        });

        Cache::forget('units_all');

        return response()->json(['success' => true, 'message' => 'Berhasil ditambahkan', 'data' => $item]);
    }

    public function update(Request $request, $id) {
        $cleanName = ucwords(strtolower(trim($request->name)));
        $request->merge(['name' => $cleanName]);

        $item = Unit::findOrFail($id);

        $data = $request->validate(
        [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'name')->ignore($item->id),
            ]
        ],
        [
            'name.required' => 'Nama jenis satuan harus diisi.',
            'name.string' => 'Nama jenis satuan tidak valid.',
            'name.max' => 'Nama jenis satuan sudah mencapai batas karakter maksimal.',
            'name.unique' => 'Nama jenis satuan ini sudah terdaftar.',
        ]);

        $user = Auth::user();

        $oldName = $item->name;

        DB::transaction(function () use ($id, $data, $user, $item, $oldName) {
            $item->update(['name' => $data['name']]);

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Ubah nama jenis satuan',
                'detail' => $user->name . ' mengubah nama jenis satuan ' . $oldName . ' menjadi ' . $item->name . ' (ID: ' . $id . ')',
            ]);
        });

        Cache::forget('units_all');

        return response()->json(['success' => true, 'message' => 'Berhasil diperbarui']);
    }

    public function destroy($id) {
        $user = Auth::user();

        $item = Unit::findOrFail($id);
        $name = $item->name;

        DB::transaction(function () use ($id, $user, $item, $name) {
            $item->delete();

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Hapus jenis satuan',
                'detail' => $user->name . ' menghapus jenis satuan ' . $name . ' (ID: ' . $id . ')',
            ]);
        });

        Cache::forget('units_all');

        return response()->json(['success' => true, 'message' => 'Berhasil dihapus']);
    }
}
