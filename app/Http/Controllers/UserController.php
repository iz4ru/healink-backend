<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $currentUser = Auth::user();
            $query = User::query();

            if ($currentUser->role === 'admin') {
                // Admin hanya boleh melihat kasir
                $query->where('role', 'cashier');
            } elseif ($currentUser->role === 'owner') {
                // Owner boleh melihat semua pengguna, kecuali dirinya sendiri
                $query->where('id', '!=', $currentUser->id);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak. Anda tidak memiliki izin.'
                ], 403);
            }

            // FITUR SEARCH
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            }

            // FITUR SORT
            $sort = $request->query('sort', 'az');
            switch ($sort) {
                case 'za':
                    $query->orderBy('name', 'desc');
                    break;
                case 'newest':
                    $query->orderBy('created_at', 'desc');
                    break;
                case 'oldest':
                    $query->orderBy('created_at', 'asc');
                    break;
                default:
                    $query->orderBy('name', 'asc');
                    break;
            }

            $users = $query->paginate(8);

            $users->through(function ($user) {
                return [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'username' => $user->username,
                    'role'     => $user->role,
                    'is_active'=> $user->is_active,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $users
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $currentUser = Auth::user();

        if (!$currentUser || !in_array($currentUser->role, ['admin', 'owner'])) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        // 1 & 2. Validasi dimasukkan ke variabel $data dengan pesan kustom
        $rules = [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'phone'    => 'nullable|string|max:20',
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:6',
        ];

        if ($currentUser->role === 'owner') {
            $rules['role'] = 'required|in:admin,cashier';
        }

        $data = $request->validate($rules, [
            'name.required'     => 'Nama pengguna wajib diisi.',
            'email.required'    => 'Email pengguna wajib diisi.',
            'email.unique'      => 'Email sudah terdaftar.',
            'email.email'       => 'Email yang dimasukkan tidak valid.',
            'username.required' => 'Username wajib diisi.',
            'username.unique'   => 'Username ini sudah terpakai.',
            'password.required' => 'Password wajib diisi.',
            'password.min'      => 'Password minimal harus 6 karakter.',
        ]);

        $roleToAssign = $currentUser->role === 'owner' ? ($data['role'] ?? 'cashier') : 'cashier';

        if ($roleToAssign === 'admin') {
            $maxAdminLimit = 2;
            if (User::where('role', 'admin')->count() >= $maxAdminLimit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal.',
                    'errors'  => ['role' => ["Maksimal $maxAdminLimit Admin tercapai."]]
                ], 422);
            }
        }

        // 3. Mulai Transaksi Database
        DB::beginTransaction();
        try {
            $newUser = User::create([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? null,
                'username'  => $data['username'],
                'password'  => Hash::make($data['password']),
                'role'      => $roleToAssign,
                'is_active' => true,
            ]);

            // 4. Catat Log Aktivitas (Snapshot)
            Log::create([
                'user_id'  => $currentUser->id,
                'activity' => 'Tambah pengguna',
                'detail'   => $currentUser->name . ' menambahkan pengguna baru bernama ' . $newUser->name . ' dengan peran ' . $newUser->role,
            ]);

            DB::commit(); // Simpan permanen ke database

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil dibuat.',
                'data'    => $newUser
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack(); // Batalkan semua aksi di atas jika error
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'message' => 'Berhasil mengambil data pengguna',
            'data'    => $user
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $currentUser = Auth::user();

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'username' => ['required', 'string', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
        ], [
            'name.required'     => 'Nama pengguna wajib diisi.',
            'email.required'    => 'Email wajib diisi.',
            'email.unique'      => 'Email ini sudah dipakai oleh akun lain.',
            'email.email'       => 'Email yang dimasukkan tidak valid.',
            'username.required' => 'Username wajib diisi.',
            'username.unique'   => 'Username ini sudah dipakai oleh akun lain.',
            'password.min'      => 'Password minimal 6 karakter.',
        ]);

        DB::beginTransaction();
        try {
            $oldName = $user->name;

            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->username = $data['username'];

            if (!empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            Log::create([
                'user_id'  => $currentUser->id,
                'activity' => 'Ubah data pengguna',
                'detail'   => $currentUser->name . ' memperbarui data pengguna ' . $oldName . ' (ID: ' . $user->id . ')',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Data pengguna berhasil diperbarui',
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal memperbarui: ' . $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $currentUser = Auth::user();
        
        $data = $request->validate([
            'is_active' => 'required|boolean'
        ], [
            'is_active.required' => 'Status aktif wajib dikirim.',
            'is_active.boolean'  => 'Format status tidak valid.',
        ]);

        DB::beginTransaction();
        try {
            $user->is_active = $data['is_active'];
            $user->save();

            $statusText = $user->is_active ? 'mengaktifkan' : 'menonaktifkan';

            Log::create([
                'user_id'  => $currentUser->id,
                'activity' => 'Ubah status pengguna',
                'detail'   => $currentUser->name . " $statusText akun " . $user->name . ' (ID: ' . $user->id . ')',
            ]);

            DB::commit();

            return response()->json([
                'message'   => 'Status pengguna berhasil diperbarui',
                'is_active' => $user->is_active
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal merubah status: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $currentUser = Auth::user();
        
        DB::beginTransaction();
        try {
            $name = $user->name;
            $user->delete();

            Log::create([
                'user_id'  => $currentUser->id,
                'activity' => 'Hapus pengguna',
                'detail'   => $currentUser->name . ' telah menghapus akun ' . $name . ' secara permanen.',
            ]);

            DB::commit();

            return response()->json(['message' => 'Pengguna berhasil dihapus'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menghapus pengguna: ' . $e->getMessage()], 500);
        }
    }

    public function logs(Request $request, $id)
    {
        try {
            $query = Log::where('user_id', $id);

            if ($request->filled('search')) {
                $search = strtolower(trim($request->search));
                $query->where(function($q) use ($search) {
                    $q->whereRaw('LOWER(activity) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(detail) LIKE ?', ["%{$search}%"]);
                });
            }

            $sort = $request->query('sort', 'newest');
            if ($sort === 'oldest') {
                $query->orderBy('created_at', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $perPage = $request->query('per_page', 10);
            $logs = $query->paginate($perPage);

            $logs->through(function($log) {
                $dateStr = Carbon::parse($log->created_at)
                                ->locale('id')
                                ->translatedFormat('l d/m/y, H:i');

                return [
                    'id'       => $log->id,
                    'date'     => ucfirst($dateStr),
                    'activity' => $log->activity,
                    'detail'   => $log->detail
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengambil log aktivitas',
                'data'    => $logs // Lempar langsung objek paginator-nya!
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        $currentUser = Auth::user();

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'email', Rule::unique('users')->ignore($currentUser->id)],
            'username' => ['required', 'string', Rule::unique('users')->ignore($currentUser->id)],
            'password' => 'nullable|string|min:6',
        ], [
            'name.required'     => 'Nama pengguna wajib diisi.',
            'email.required'    => 'Email wajib diisi.',
            'email.unique'      => 'Email ini sudah dipakai oleh akun lain.',
            'email.email'       => 'Email yang dimasukkan tidak valid.',
            'username.required' => 'Username wajib diisi.',
            'username.unique'   => 'Username ini sudah dipakai oleh akun lain.',
            'password.min'      => 'Password minimal 6 karakter.',
        ]);

        DB::beginTransaction();
        try {
            $currentUser->name = $data['name'];
            $currentUser->email = $data['email'];
            $currentUser->username = $data['username'];

            if (!empty($data['password'])) {
                $currentUser->password = Hash::make($data['password']);
            }

            $currentUser->save();

            Log::create([
                'user_id'  => $currentUser->id,
                'activity' => 'Pembaruan profil mandiri',
                'detail'   => $currentUser->name . ' memperbarui data profilnya',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Profil berhasil diperbarui',
                'data'    => $currentUser
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Gagal memperbarui profil: ' . $e->getMessage()
            ], 500);
        }
    }

    public function myLogs(Request $request)
    {
        try {
            $currentUser = Auth::user();
            $query = Log::where('user_id', $currentUser->id);

            if ($request->filled('search')) {
                $search = strtolower(trim($request->search));
                $query->where(function($q) use ($search) {
                    $q->whereRaw('LOWER(activity) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(detail) LIKE ?', ["%{$search}%"]);
                });
            }

            $sort = $request->query('sort', 'newest');
            if ($sort === 'oldest') {
                $query->orderBy('created_at', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $perPage = $request->query('per_page', 10);
            $logs = $query->paginate($perPage);

            $logs->through(function($log) {
                $dateStr = Carbon::parse($log->created_at)
                                ->locale('id')
                                ->translatedFormat('l d/m/y, H:i');

                return [
                    'id'       => $log->id,
                    'date'     => ucfirst($dateStr),
                    'activity' => $log->activity,
                    'detail'   => $log->detail
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengambil log aktivitas',
                'data'    => $logs // Lempar langsung objek paginator-nya!
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}