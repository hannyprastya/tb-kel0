{{-- Menu Kasus 0 (dipanggil layouts/navigation.blade.php, $gaya = desktop/ponsel). --}}
@php($petugas = \App\Models\Kasus\Petugas::adalah(auth()->user()))
@if (($gaya ?? 'desktop') === 'ponsel')
    <x-responsive-nav-link :href="route('kasus.buku.katalog')" :active="request()->routeIs('kasus.buku.katalog')">Katalog</x-responsive-nav-link>
    @if ($petugas)
        <x-responsive-nav-link :href="route('kasus.buku.kelola')" :active="request()->routeIs('kasus.buku.kelola')">Sirkulasi</x-responsive-nav-link>
    @endif
@else
    <x-nav-link :href="route('kasus.buku.katalog')" :active="request()->routeIs('kasus.buku.katalog')">Katalog</x-nav-link>
    @if ($petugas)
        <x-nav-link :href="route('kasus.buku.kelola')" :active="request()->routeIs('kasus.buku.kelola')">Sirkulasi</x-nav-link>
    @endif
@endif
