// tests/k6/uji.js (P10 slide 25): kriteria lulus Tabel 1 = thresholds.
// Halaman yang diuji: katalog /buku (halaman paling sering dibuka saat
// minggu ujian). RUTE bisa diganti, mis. -e RUTE=/ untuk buku tamu.
import http from "k6/http";
import { check, sleep } from "k6";
const RUTE = __ENV.RUTE || "/buku";
export const options = {
  vus: 10, duration: "1m",
  thresholds: {
    http_req_duration: ["p(95)<1000"],   // Tabel 1: p95 < 1 detik
    http_req_failed: ["rate<0.01"],      // Tabel 1: error < 1%
  },
};
export default function () {
  const r = http.get(__ENV.URL + RUTE);
  check(r, { "status 200": (x) => x.status === 200 });
  sleep(1);
}
