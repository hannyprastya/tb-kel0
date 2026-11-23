{{-- GET /buku/kelola (grup web, login + petugas): koleksi, pinjam, kembali. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Sirkulasi perpustakaan</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="rounded bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
            @endif
            <p class="text-sm text-gray-600">{{ $ringkasan['judul'] }} judul · {{ $ringkasan['tersedia'] }}/{{ $ringkasan['eksemplar'] }} eksemplar di rak ·
                {{ $ringkasan['dipinjam'] }} dipinjam · {{ $ringkasan['terlambat'] }} terlambat</p>

            <div class="grid gap-6 md:grid-cols-2">
                <form method="POST" action="{{ route('kasus.buku.pinjam') }}" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    @csrf
                    <h3 class="font-semibold">Pinjam</h3>
                    <x-text-input name="buku" :value="old('buku')" placeholder="no. buku" required class="w-full" />
                    <x-input-error :messages="$errors->get('buku')" />
                    <x-text-input name="anggota" :value="old('anggota')" placeholder="no. anggota, mis. A-0001" required class="w-full" />
                    <x-input-error :messages="$errors->get('anggota')" />
                    <x-text-input name="hari" type="number" min="1" max="14" :value="old('hari', 7)" class="w-24" /> hari
                    <x-primary-button>Catat pinjam</x-primary-button>
                </form>
                <form method="POST" action="{{ route('kasus.buku.kembali') }}" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    @csrf
                    <h3 class="font-semibold">Kembali</h3>
                    <x-text-input name="pinjam" placeholder="no. peminjaman" required class="w-full" />
                    <x-input-error :messages="$errors->get('pinjam')" />
                    <x-primary-button>Catat kembali</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr><th class="p-3">No.</th><th class="p-3">Buku</th><th class="p-3">Anggota</th><th class="p-3">Jatuh tempo</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($aktif as $p)
                            <tr class="{{ $p->terlambat() ? 'bg-red-50' : '' }}">
                                <td class="p-3">{{ $p->id }}</td>
                                <td class="p-3">{{ $p->buku->kode }} · {{ $p->buku->judul }}</td>
                                <td class="p-3">{{ $p->no_anggota }}</td>
                                <td class="p-3">{{ $p->jatuh_tempo->format('d-m-Y') }}{{ $p->terlambat() ? ' (terlambat)' : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-3 text-gray-500">Tidak ada peminjaman aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('kasus.buku.simpan') }}" enctype="multipart/form-data"
                  class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 md:grid-cols-2">
                @csrf
                <h3 class="font-semibold md:col-span-2">Tambah buku</h3>
                <div>
                    <x-text-input name="kode" :value="old('kode')" placeholder="kode, mis. B-0004" required class="w-full" />
                    <x-input-error :messages="$errors->get('kode')" />
                </div>
                <div>
                    <x-text-input name="stok" type="number" min="1" max="999" :value="old('stok', 1)" required class="w-24" /> eksemplar
                    <x-input-error :messages="$errors->get('stok')" />
                </div>
                <div class="md:col-span-2">
                    <x-text-input name="judul" :value="old('judul')" placeholder="judul" required class="w-full" />
                    <x-input-error :messages="$errors->get('judul')" />
                </div>
                <div>
                    <x-text-input name="penulis" :value="old('penulis')" placeholder="penulis" required class="w-full" />
                    <x-input-error :messages="$errors->get('penulis')" />
                </div>
                <div>
                    <x-text-input name="tahun" type="number" :value="old('tahun')" placeholder="tahun" class="w-32" />
                    <x-input-error :messages="$errors->get('tahun')" />
                </div>
                <div>
                    <x-input-label for="sampul" value="Sampul (JPG/PNG/WebP, maks. 1 MB)" />
                    <input id="sampul" name="sampul" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block text-sm">
                    <x-input-error :messages="$errors->get('sampul')" />
                </div>
                <div>
                    <x-input-label for="scan" value="Scan e-dokumen (PDF, maks. 5 MB)" />
                    <input id="scan" name="scan" type="file" accept=".pdf,application/pdf" class="mt-1 block text-sm">
                    <x-input-error :messages="$errors->get('scan')" />
                </div>
                <div class="md:col-span-2"><x-primary-button>Simpan buku</x-primary-button></div>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr><th class="p-3">No. buku</th><th class="p-3">Kode</th><th class="p-3">Judul</th><th class="p-3">Tersedia</th><th class="p-3">Berkas</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($buku as $b)
                            <tr>
                                <td class="p-3">{{ $b->id }}</td>
                                <td class="p-3 font-mono">{{ $b->kode }}</td>
                                <td class="p-3"><a href="{{ route('kasus.buku.rincian', $b->id) }}" class="underline">{{ $b->judul }}</a></td>
                                <td class="p-3">{{ $b->tersedia }}/{{ $b->stok }}</td>
                                <td class="p-3">{{ $b->sampul_path ? 'sampul' : '' }} {{ $b->scan_path ? 'scan' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
