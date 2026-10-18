"""Adds products with HTML/JS payloads in name and SKU, scans over HTTP and checks the page escapes them."""
import json, re, subprocess, sys

__file__ = __file__  # noqa
sys.path.insert(0, "dev")
src = open("dev/perf-http.py").read().split('dt, page = timed("/wp-admin/admin.php?page=costgator")')[0]
exec(src)

WP = ["docker", "compose", "run", "--rm", "-T", "cli", "wp"]
pid = subprocess.run(WP + ["eval", r'''
$p = new WC_Product_Simple();
$p->set_name( '<img src=x onerror=alert(1)>Evil " onmouseover="alert(3)' );
$p->set_sku( '"><script>alert(2)</script>' );
$p->set_regular_price( '100' );
$id = $p->save();
update_post_meta( $id, '_wc_cog_cost', '5000' );
echo $id;'''], capture_output=True, text=True).stdout.strip().splitlines()[-1]

page = timed("/wp-admin/admin.php?page=costgator")[1]
nonce = re.search(r"var costGator = .*?\"nonce\":\"([a-f0-9]+)\"", page).group(1)
st = json.loads(timed("/wp-admin/admin-ajax.php", {"action": "costgator_start", "nonce": nonce})[1])["data"]
while not st["done"]:
    st = json.loads(timed("/wp-admin/admin-ajax.php", {"action": "costgator_step", "nonce": nonce, "scan_id": st["scan_id"]})[1])["data"]

fails = 0
for path in ["/wp-admin/admin.php?page=costgator", "/wp-admin/admin.php?page=costgator&preflight=10&basis=current"]:
    page = timed(path)[1]
    bad = [s for s in ["<img src=x", "<script>alert(2)", 'onmouseover="alert'] if s in page]
    listed = "Evil" in page
    print(("  ok   " if listed and not bad else "  FAIL ") + "%s: payload product listed=%s, raw payloads=%s" % (path.split("?")[1], listed, bad or "none"))
    fails += 0 if listed and not bad else 1

subprocess.run(WP + ["post", "delete", pid, "--force"], capture_output=True)
sys.exit(1 if fails else 0)
