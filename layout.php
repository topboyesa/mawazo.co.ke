<?php
// Shared top and bottom of the log-in and sign-up pages.
// Same look and the same light/dark behavior as the main page.

function page_head(string $title): void
{ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - AI SLOP</title>
    <meta name="htmx-config" content='{"historyCacheSize": 0, "allowEval": false}'>
    <style>
        :root { --bg: #fff; --fg: #000; --line: #ccc; --hover: #f2f2f2; }
        :root.dark { --bg: #121212; --fg: #e8e8e8; --line: #444; --hover: #2a2a2a; color-scheme: dark; }
        body { background: var(--bg); color: var(--fg); }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.4/dist/htmx.min.js"></script>
    <script src="smooth.js"></script>
    <script src="format.js"></script>
    <script>
        (function () {
            var mq = window.matchMedia("(prefers-color-scheme: dark)");
            function apply() {
                var saved = null;
                try { saved = localStorage.getItem("theme"); } catch (e) {}
                var hour = new Date().getHours();
                var night = hour >= 19 || hour < 6;
                document.documentElement.classList.toggle("dark", saved ? saved === "dark" : (night || mq.matches));
            }
            function scheduleNext() {
                var now = new Date(), next = new Date(now), h = now.getHours();
                next.setMinutes(0, 0, 0);
                if (h < 6) next.setHours(6);
                else if (h < 19) next.setHours(19);
                else { next.setDate(next.getDate() + 1); next.setHours(6); }
                setTimeout(function () { apply(); scheduleNext(); }, next - now);
            }
            apply();
            scheduleNext();
            mq.addEventListener("change", apply);
            document.addEventListener("visibilitychange", apply);
        })();
    </script>
</head>
<body hx-boost="true" hx-swap="innerHTML transition:true">
<style>
        :root { --bg: #fff; --fg: #000; --line: #ccc; }
        :root.dark { --bg: #121212; --fg: #e8e8e8; --line: #444; color-scheme: dark; }
        body { background: var(--bg); color: var(--fg); }
        :root.dark a { color: #8ab4f8; }
        h2 { text-align: center; }
        form.auth { max-width: 360px; margin: 0 auto 1.5rem; padding: 0 12px; }
        form.auth label { display: block; margin-top: 12px; }
        form.auth input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg); font: inherit; }
        form.auth button { margin-top: 16px; width: 100%; padding: 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--fg); color: var(--bg); font: inherit; cursor: pointer; }
        ul.errors { max-width: 360px; margin: 0 auto 1rem; color: #c0392b; }
        :root.dark ul.errors { color: #ff7b72; }
        .pw { position: relative; }
        form.auth .pw input { padding-right: 42px; }
        form.auth .pw-toggle { position: absolute; top: 50%; right: 8px; transform: translateY(-50%); display: flex; width: auto; margin: 0; padding: 4px; border: none; border-radius: 50%; background: none; color: inherit; opacity: 0.7; cursor: pointer; }
        form.auth .pw-toggle:hover { opacity: 1; }
</style>
<h2><?= e($title) ?></h2>

<p style="text-align: center;"><a href="index.html">&larr; back to main page</a></p>

<?php }

function page_foot(): void
{ ?>
<script>
    // An eye button inside every password box: click to show the password, click again to hide it.
    (function () {
        var open = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
        var shut = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path><line x1="2" y1="2" x2="22" y2="22"></line></svg>';
        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            var wrap = document.createElement("div");
            wrap.className = "pw";
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            var btn = document.createElement("button");
            btn.type = "button";
            btn.className = "pw-toggle";
            btn.setAttribute("aria-label", "show password");
            btn.innerHTML = open;
            btn.addEventListener("click", function () {
                var show = input.type === "password";
                input.type = show ? "text" : "password";
                btn.innerHTML = show ? shut : open;
                btn.setAttribute("aria-label", show ? "hide password" : "show password");
            });
            wrap.appendChild(btn);
        });
    })();
</script>
</body>
</html>
<?php }
