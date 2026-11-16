// Smooth page changes. htmx turns every link and form on the site into a quick background request,
// so the page changes without a full reload. If anything about it ever fails, the site falls back
// to ordinary page loads, and everything still works.
(function () {
    // A smooth page change that fails does the same thing as a normal click.
    function fallback(e) {
        var path = e.detail && e.detail.pathInfo && e.detail.pathInfo.requestPath;
        if (path) window.location.href = path;
    }
    document.addEventListener("htmx:responseError", fallback);
    document.addEventListener("htmx:sendError", fallback);

    // Links and forms that scripts add after the page loads (menu items, @tags) need switching on too.
    var timer = null;
    function watch() {
        new MutationObserver(function () {
            clearTimeout(timer);
            timer = setTimeout(function () { if (window.htmx) window.htmx.process(document.body); }, 50);
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.body) watch(); else document.addEventListener("DOMContentLoaded", watch);
})();
