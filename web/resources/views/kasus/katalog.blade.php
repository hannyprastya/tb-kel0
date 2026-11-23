{{--
    GET /buku?q= (grup publik): katalog untuk semua pengunjung. Tanpa sesi,
    tanpa @csrf, tanpa nomor anggota; sama untuk semua orang, jadi boleh
    di-cache Nginx/CDN paling lama 60 detik. Formulir cari memakai GET.
--}}
<x-publik-layout judul="Katalog" :statis="true">
    <h1 class="text-2xl font-semibold">Katalog {{ config('app.pemilik') }}</h1>
    <p class="text-sm text-gray-500">dibuat {{ now()->format('H:i:s') }} oleh server {{ gethostname() }} · jumlah tersedia bisa tertinggal paling lama 1 menit</p>

    <form method="GET" action="{{ route('kasus.buku.katalog') }}" class="mt-6 flex gap-2">
        <input name="q" value="{{ $q }}" maxlength="50" placeholder="judul, penulis, atau kode"
               class="flex-1 rounded-md border-gray-300 shadow-sm">
        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white">Cari</button>
    </form>

    <table class="mt-6 w-full rounded bg-white text-sm shadow-sm">
        <thead class="text-left text-gray-500">
            <tr><th class="p-3"></th><th class="p-3">Kode</th><th class="p-3">Judul</th><th class="p-3">Penulis</th><th class="p-3">Tersedia</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse ($buku as $b)
                <tr>
                    <td class="p-3 w-14">
                        @if ($b->sampul_path)
                            <img src="{{ route('kasus.buku.sampul', $b->id) }}" alt="sampul {{ $b->kode }}" loading="lazy" class="h-14 w-10 rounded object-cover">
                        @endif
                    </td>
                    <td class="p-3 font-mono">{{ $b->kode }}</td>
                    <td class="p-3"><a href="{{ route('kasus.buku.rincian', $b->id) }}" class="underline">{{ $b->judul }}</a>@if ($b->tahun) <span class="text-gray-500">({{ $b->tahun }})</span>@endif</td>
                    <td class="p-3">{{ $b->penulis }}</td>
                    <td class="p-3 {{ $b->tersedia === 0 ? 'text-red-700' : '' }}">{{ $b->tersedia }}/{{ $b->stok }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-3 text-gray-500">Tidak ada buku yang cocok.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="mt-4 text-sm text-gray-600">Rincian buku dan scan e-dokumen hanya untuk anggota yang masuk. Peminjaman dicatat petugas di meja sirkulasi.</p>
</x-publik-layout>
