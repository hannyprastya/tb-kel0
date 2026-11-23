{{-- Kartu Kasus 0 di Dasbor (dipanggil dashboard.blade.php). --}}
@if (\App\Models\Kasus\Petugas::adalah(auth()->user()))
    <a href="{{ route('kasus.buku.kelola') }}" class="block bg-white shadow-sm sm:rounded-lg p-6 hover:bg-gray-50">
        <h3 class="font-semibold">Sirkulasi perpustakaan</h3>
        <p class="text-sm text-gray-600">Tambah buku, unggah sampul/scan, catat pinjam dan kembali.</p>
    </a>
@else
    <a href="{{ route('kasus.buku.katalog') }}" class="block bg-white shadow-sm sm:rounded-lg p-6 hover:bg-gray-50">
        <h3 class="font-semibold">Katalog perpustakaan</h3>
        <p class="text-sm text-gray-600">Cari buku dan buka scan e-dokumen. Sirkulasi hanya untuk petugas (php artisan kasus:petugas EMAIL).</p>
    </a>
@endif
