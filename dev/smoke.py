"""HTTP smoke test against the local site: login, admin page, AJAX scan, permissions, settings."""
import html, http.cookiejar, json, os, re, sys, urllib.error, urllib.parse, urllib.request

env = dict(l.strip().split("=", 1) for l in open(os.path.join(os.path.dirname(__file__), "..", ".env")) if "=" in l)
BASE = "http://" + env.get("WP_BIND", "127.0.0.1") + ":8088"
fails = 0


def check(label, ok):
    global fails
    print(("  ok   " if ok else "  FAIL ") + label)
    fails += 0 if ok else 1


def session(user, pw):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    op.open(BASE + "/wp-login.php")
    op.open(BASE + "/wp-login.php", urllib.parse.urlencode({"log": user, "pwd": pw, "testcookie": "1"}).encode())
    return op


def get(op, path):
    return op.open(BASE + path).read().decode()


def post(op, path, data):
    try:
        r = op.open(BASE + path, urllib.parse.urlencode(data).encode())
        return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


admin = session("admin", env["WP_ADMIN_PASSWORD"])
page = get(admin, "/wp-admin/admin.php?page=costgator")
check("admin page renders", "Scan My Store" in page and "costgator-scan" in page)
check("assets enqueued", "costgator/assets/admin.js" in page and "admin.css" in page)
nonce = re.search(r"var costGator = .*?\"nonce\":\"([a-f0-9]+)\"", page).group(1)

code, body = post(admin, "/wp-admin/admin-ajax.php", {"action": "costgator_start", "nonce": "bad"})
check("bad nonce rejected", code == 403 or body.strip() == "-1")

code, body = post(admin, "/wp-admin/admin-ajax.php", {"action": "costgator_start", "nonce": nonce})
status = json.loads(body)["data"]
steps = 0
while not status["done"] and steps < 50:
    code, body = post(admin, "/wp-admin/admin-ajax.php", {"action": "costgator_step", "nonce": nonce, "scan_id": status["scan_id"]})
    status = json.loads(body)["data"]
    steps += 1
check("AJAX scan completes (%d/%d)" % (status["processed"], status["total"]), status["done"])

page = get(admin, "/wp-admin/admin.php?page=costgator")
check("results shown", "Selling below cost" in page and "CG-TAXTRAP" in page and "save30" in page)
check("missing cost listed", "CG-NOCOST" in page)
check("no PHP notices", not re.search(r"(Warning|Notice|Deprecated|Fatal error)</b>:", page))

check("review request shown after a scan with costs", "Leave a review" in page and "Don&#039;t ask again" in page)
review_url = html.unescape(re.search(r'href="([^"]*choice=review[^"]*)"', page).group(1))
dismiss_url = html.unescape(re.search(r'href="([^"]*choice=dismiss[^"]*)"', page).group(1))
check("review button opens the WordPress.org review form", review_url.startswith(BASE + "/wp-admin/admin-post.php?") and "_wpnonce=" in review_url)
code, _ = post(admin, "/wp-admin/admin-post.php?action=costgator_review&choice=dismiss&_wpnonce=bad", {})
check("review dismiss with bad nonce rejected", code == 403)
customer_early = session("shopper", "shopperpass123")
try:
    code = customer_early.open(dismiss_url).status
except urllib.error.HTTPError as e:
    code = e.code
check("customer cannot dismiss the review request", code == 403)
page = admin.open(dismiss_url).read().decode()
check("review request gone for good after dismiss", "costgator-scan" in page and "Leave a review" not in page)

page = get(admin, "/wp-admin/admin.php?page=costgator&preflight=20&basis=current")
check("preflight renders", "With 20" in page and "below cost" in page)

settings_nonce = re.search(r'name="_wpnonce" value="([a-f0-9]+)"', page).group(1)
code, _ = post(admin, "/wp-admin/admin-post.php", {"action": "costgator_settings", "_wpnonce": settings_nonce, "threshold": "150"})
page = get(admin, "/wp-admin/admin.php?page=costgator")
check("threshold clamped to 99", 'name="threshold" min="0" max="99" step="0.1" value="99"' in page)
post(admin, "/wp-admin/admin-post.php", {"action": "costgator_settings", "_wpnonce": settings_nonce, "threshold": "10"})

customer = session("shopper", "shopperpass123")
code, body = post(customer, "/wp-admin/admin-ajax.php", {"action": "costgator_start", "nonce": nonce})
check("customer cannot start scan", code in (403, 400) or body.strip() in ("-1", "0") or '"success":false' in body)
code, body = post(customer, "/wp-admin/admin-post.php", {"action": "costgator_settings", "_wpnonce": settings_nonce, "threshold": "50"})
check("customer cannot save settings", code == 403)

sys.exit(1 if fails else 0)
