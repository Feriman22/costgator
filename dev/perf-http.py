"""Real HTTP timings: full AJAX scan as the browser runs it, then page and preflight render times."""
import http.cookiejar, json, os, re, time, urllib.parse, urllib.request

env = dict(l.strip().split("=", 1) for l in open(os.path.join(os.path.dirname(__file__), "..", ".env")) if "=" in l)
BASE = "http://" + env.get("WP_BIND", "127.0.0.1") + ":8088"
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
op.open(BASE + "/wp-login.php")
op.open(BASE + "/wp-login.php", urllib.parse.urlencode({"log": "admin", "pwd": env["WP_ADMIN_PASSWORD"], "testcookie": "1"}).encode())


def timed(path, data=None):
    t = time.time()
    r = op.open(BASE + path, urllib.parse.urlencode(data).encode() if data else None, timeout=600)
    body = r.read().decode()
    return time.time() - t, body


dt, page = timed("/wp-admin/admin.php?page=costgator")
nonce = re.search(r"var costGator = .*?\"nonce\":\"([a-f0-9]+)\"", page).group(1)
t0 = time.time()
dt, body = timed("/wp-admin/admin-ajax.php", {"action": "costgator_start", "nonce": nonce})
status = json.loads(body)["data"]
steps = []
while not status["done"]:
    dt, body = timed("/wp-admin/admin-ajax.php", {"action": "costgator_step", "nonce": nonce, "scan_id": status["scan_id"]})
    payload = json.loads(body)
    if not payload.get("success"):
        raise SystemExit("step failed: " + body[:300])
    status = payload["data"]
    steps.append(dt)
total = time.time() - t0
steps.sort()
print("AJAX scan: %.1fs total, %d steps, median %.2fs, p95 %.2fs, max %.2fs" % (total, len(steps), steps[len(steps) // 2], steps[int(len(steps) * 0.95)], steps[-1]))
dt, page = timed("/wp-admin/admin.php?page=costgator")
print("results page: %.2fs, %.0f KB HTML, PHP warnings: %s" % (dt, len(page) / 1024, bool(re.search(r"(Warning|Notice|Fatal error)</b>:", page))))
dt, page = timed("/wp-admin/admin.php?page=costgator&preflight=25&basis=regular")
print("preflight page: %.2fs" % dt)
