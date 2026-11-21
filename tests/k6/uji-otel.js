// tests/k6/uji-otel.js (P11 slide 7): 20 VU, 1 menit, 20% ke /lambat
import http from "k6/http";
import { check, sleep } from "k6";
export const options = { vus: 20, duration: "1m" };
export default function () {
  const rute = Math.random() < 0.2 ? "/lambat" : "/";
  const r = http.get(__ENV.URL + rute);
  check(r, { "status 200": (x) => x.status === 200 });
  sleep(1);
}
