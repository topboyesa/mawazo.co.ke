// Turns a post's plain text into formatted content, safely.
//
// Members type simple symbols: **bold**, *italic*, [link text](https://address), `code`,
// "- " for a bullet list, "1. " for a numbered list, "> " for a quote, "## " for a heading,
// and three backticks around a block of code. The post is saved exactly as typed.
//
// Everything is built with createElement and textContent. Nothing the member types is ever
// treated as HTML, so a post can't carry a script or break the page.
(function () {
    if (window.renderPost) return;   // already loaded
    var css =
        ".fmt p { margin: 0 0 1em; }" +
        ".fmt h3 { margin: 1.2em 0 0.4em; text-align: left; }" +
        ".fmt ul, .fmt ol { margin: 0 0 1em; padding-left: 1.5em; }" +
        ".fmt blockquote { margin: 0 0 1em; padding: 4px 0 4px 14px; border-left: 3px solid var(--line, #ccc); opacity: 0.9; }" +
        ".fmt pre { margin: 0 0 1em; padding: 12px; overflow-x: auto; border: 1px solid var(--line, #ccc); border-radius: 8px; }" +
        ".fmt code { font-family: ui-monospace, Consolas, monospace; font-size: 0.92em; background: rgba(127, 127, 127, 0.18); padding: 1px 4px; border-radius: 4px; }" +
        ".fmt pre code { background: none; padding: 0; }" +
        ".fmt a { text-decoration: underline; }" +
        ":root.dark .fmt a { color: #8ab4f8; }";
    var style = document.createElement("style");
    style.textContent = css;
    document.head.appendChild(style);

    function safeUrl(url) {
        try {
            var u = new URL(url);
            return u.protocol === "http:" || u.protocol === "https:";
        } catch (e) {
            return false;
        }
    }

    // `code`, **bold**, *italic* and [link](https://...) inside one line of text.
    var inlineRe = /`([^`\n]+)`|\*\*([^*\n]+?)\*\*|\*([^*\s][^*\n]*?)\*|\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g;

    function inline(text, parent) {
        var re = new RegExp(inlineRe.source, "g");
        var last = 0, m;
        while ((m = re.exec(text)) !== null) {
            if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
            var el;
            if (m[1] !== undefined) {
                el = document.createElement("code");
                el.textContent = m[1];
            } else if (m[2] !== undefined) {
                el = document.createElement("strong");
                el.textContent = m[2];
            } else if (m[3] !== undefined) {
                el = document.createElement("em");
                el.textContent = m[3];
            } else if (safeUrl(m[5])) {
                el = document.createElement("a");
                el.textContent = m[4];
                el.href = m[5];
                el.target = "_blank";
                el.rel = "noopener noreferrer nofollow";
            } else {
                el = document.createTextNode(m[0]);   // not a web address we trust: show it as plain text
            }
            parent.appendChild(el);
            last = re.lastIndex;
        }
        if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
    }

    function lines(parent, list) {
        list.forEach(function (line, k) {
            if (k) parent.appendChild(document.createElement("br"));
            inline(line, parent);
        });
    }

    // Shows what changed between two pieces of text, word by word: new words are marked with <ins>, removed ones with <del>.
    window.renderDiff = function (a, b, parent) {
        function tokens(s) { return String(s).split(/(\s+)/).filter(function (t) { return t !== ""; }); }
        var x = tokens(a), y = tokens(b), start = 0, i, j, k;
        while (start < x.length && start < y.length && x[start] === y[start]) start++;
        var endX = x.length, endY = y.length;
        while (endX > start && endY > start && x[endX - 1] === y[endY - 1]) { endX--; endY--; }
        var n = endX - start, m = endY - start;
        if (n * m > 25000000) { parent.appendChild(document.createTextNode(String(b))); return; }   // too big to compare
        var w = m + 1, t = new Uint16Array((n + 1) * w);
        for (i = n - 1; i >= 0; i--)
            for (j = m - 1; j >= 0; j--)
                t[i * w + j] = x[start + i] === y[start + j] ? t[(i + 1) * w + j + 1] + 1 : Math.max(t[(i + 1) * w + j], t[i * w + j + 1]);
        function put(kind, text) {
            if (kind === "=") { parent.appendChild(document.createTextNode(text)); return; }
            var el = document.createElement(kind === "-" ? "del" : "ins");
            el.textContent = text;
            parent.appendChild(el);
        }
        for (k = 0; k < start; k++) put("=", x[k]);
        i = 0; j = 0;
        while (i < n && j < m) {
            if (x[start + i] === y[start + j]) { put("=", x[start + i]); i++; j++; }
            else if (t[(i + 1) * w + j] >= t[i * w + j + 1]) { put("-", x[start + i]); i++; }
            else { put("+", y[start + j]); j++; }
        }
        while (i < n) { put("-", x[start + i]); i++; }
        while (j < m) { put("+", y[start + j]); j++; }
        for (k = endX; k < x.length; k++) put("=", x[k]);
    };

    window.renderPost = function (text) {
        var frag = document.createDocumentFragment();
        var rows = String(text).replace(/\r\n?/g, "\n").split("\n");
        var buf = [];
        var i = 0;

        function flush() {
            if (!buf.length) return;
            var p = document.createElement("p");
            lines(p, buf);
            frag.appendChild(p);
            buf = [];
        }

        while (i < rows.length) {
            var row = rows[i], m;

            if (/^```/.test(row)) {                       // a block of code
                flush();
                var code = [];
                i++;
                while (i < rows.length && !/^```/.test(rows[i])) { code.push(rows[i]); i++; }
                i++;
                var pre = document.createElement("pre");
                var c = document.createElement("code");
                c.textContent = code.join("\n");
                pre.appendChild(c);
                frag.appendChild(pre);
                continue;
            }
            if (row.trim() === "") { flush(); i++; continue; }

            if ((m = /^##\s+(.+)$/.exec(row))) {          // a heading
                flush();
                var h = document.createElement("h3");
                inline(m[1], h);
                frag.appendChild(h);
                i++;
                continue;
            }
            if (/^>\s?/.test(row)) {                      // a quote
                flush();
                var quoted = [];
                while (i < rows.length && /^>\s?/.test(rows[i])) { quoted.push(rows[i].replace(/^>\s?/, "")); i++; }
                var bq = document.createElement("blockquote");
                lines(bq, quoted);
                frag.appendChild(bq);
                continue;
            }
            if (/^-\s+/.test(row) || /^\d+\.\s+/.test(row)) {   // a bullet or numbered list
                flush();
                var numbered = /^\d+\.\s+/.test(row);
                var itemRe = numbered ? /^\d+\.\s+(.*)$/ : /^-\s+(.*)$/;
                var list = document.createElement(numbered ? "ol" : "ul");
                while (i < rows.length && itemRe.test(rows[i])) {
                    var li = document.createElement("li");
                    inline(itemRe.exec(rows[i])[1], li);
                    list.appendChild(li);
                    i++;
                }
                frag.appendChild(list);
                continue;
            }
            buf.push(row);
            i++;
        }
        flush();
        return frag;
    };
})();
