const url = process.env.WP_URL || 'http://localhost:8888';
const deadline = Date.now() + 60_000;
while (Date.now() < deadline) {
  try {
    const res = await fetch(url + '/wp-login.php');
    if (res.ok || res.status === 200) { console.log('WP is up'); process.exit(0); }
  } catch { /* not ready */ }
  await new Promise((r) => setTimeout(r, 1500));
}
console.error('WP did not come up in time');
process.exit(1);
