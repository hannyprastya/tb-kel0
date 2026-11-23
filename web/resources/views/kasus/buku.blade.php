{{-- GET /buku/{id} (grup web, login): rincian, sampul, tautan scan 5 menit; petugas: ganti berkas. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $b->judul }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="rounded bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 flex gap-6">
                @if ($b->sampul_path)
                    <img src="{{ route('kasus.buku.sampul', $b->id) }}" alt="sampul {{ $b->kode }}" class="h-48 w-32 rounded object-cover">
                @endif
                <dl class="grid grid-cols-[8rem_1fr] gap-y-1 text-sm">
                    <dt class="text-gray-500">Kode</dt><dd class="font-mono">{{ $b->kode }}</dd>
                    <dt class="text-gray-500">Penulis</dt><dd>{{ $b->penulis }}</dd>
                    <dt class="text-gray-500">Tahun</dt><dd>{{ $b->tahun ?? '-' }}</dd>
                    <dt class="text-gray-500">Tersedia</dt><dd>{{ $b->tersedia }} dari {{ $b->stok }} eksemplar</dd>
                    <dt class="text-gray-500">E-dokumen</dt>
                    <dd>
                        @if ($tautanScan)
                            <a href="{{ $tautanScan }}" class="text-indigo-600 underline">{{ $b->scan_nama ?? $b->kode.'.pdf' }}</a>
                            <span class="text-gray-500">(tautan berlaku {{ $menit }} menit)</span>
                        @else
                            -
                        @endif
                    </dd>
                </dl>
            </div>

            @if ($petugas)
                <form method="POST" action="{{ route('kasus.buku.berkas', $b->id) }}" enctype="multipart/form-data"
                      class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    @csrf
                    <h3 class="font-semibold">Ganti sampul / scan</h3>
                    <div>
                        <x-input-label for="sampul" value="Sampul (JPG/PNG/WebP, maks. 1 MB)" />
                        <input id="sampul" name="sampul" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block text-sm">
                        <x-input-error :messages="$errors->get('sampul')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="scan" value="Scan e-dokumen (PDF, maks. 5 MB)" />
                        <input id="scan" name="scan" type="file" accept=".pdf,application/pdf" class="mt-1 block text-sm">
                        <x-input-error :messages="$errors->get('scan')" class="mt-1" />
                    </div>
                    <x-primary-button>Unggah</x-primary-button>
                </form>

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold">Sedang dipinjam</h3>
                    <ul class="mt-2 text-sm divide-y divide-gray-200">
                        @forelse ($pinjamAktif as $p)
                            <li class="py-1">no. {{ $p->id }} · anggota {{ $p->no_anggota }} · jatuh tempo {{ $p->jatuh_tempo->format('d-m-Y') }}</li>
                        @empty
                            <li class="py-1 text-gray-500">Tidak ada.</li>
                        @endforelse
                    </ul>
                </div>
            @endif

            <a href="{{ route('kasus.buku.katalog') }}" class="text-sm underline">Kembali ke katalog</a>
        </div>
    </div>
</x-app-layout>
