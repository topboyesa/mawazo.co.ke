<?php
// Any logged-in member can write posts. You can edit and delete your own; admins can manage every post.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/notify.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$me  = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
$stmt->execute([$me]);
$isAdmin = (bool) $stmt->fetchColumn();

function can_manage(PDO $pdo, int $postId, int $me, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM post_authors WHERE post_id = ? AND user_id = ?');
    $stmt->execute([$postId, $me]);
    return (bool) $stmt->fetchColumn();
}

$errors = [];
$editId = 0;
$title  = '';
$body   = '';

// Opening write.php?id=4 loads post 4 into the form, if it is yours (or you are an admin).
if (isset($_GET['id']) && ctype_digit((string) $_GET['id']) && can_manage($pdo, (int) $_GET['id'], $me, $isAdmin)) {
    $stmt = $pdo->prepare('SELECT id, title, body FROM posts WHERE id = ?');
    $stmt->execute([(int) $_GET['id']]);
    if ($row = $stmt->fetch()) {
        $editId = (int) $row['id'];
        $title  = $row['title'];
        $body   = $row['body'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // The server drops everything when the upload is bigger than its limit, so say what happened.
    $errors[] = 'Those pictures are too big to upload together. Try fewer or smaller ones.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id     = (int) ($_POST['id'] ?? 0);

        if ($action === 'delete') {
            if (!can_manage($pdo, $id, $me, $isAdmin)) {
                $errors[] = 'You can only delete your own posts.';
            } else {
                // Remove the post and everything attached to it.
                $key = 'post-' . $id;
                delete_post_media($pdo, $id);   // the picture files go too
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
                foreach (['post_votes', 'post_views', 'comments', 'notifications'] as $table) {
                    $pdo->prepare("DELETE FROM $table WHERE post_id = ?")->execute([$key]);
                }
                $pdo->commit();
                header('Location: ' . (($_POST['back'] ?? '') === '1' ? 'index.html' : 'write.php?done=deleted'));
                exit;
            }
        }

        if ($action === 'save') {
            $title  = trim((string) ($_POST['title'] ?? ''));
            $body   = str_replace(["\r\n", "\r"], "\n", trim((string) ($_POST['body'] ?? '')));   // one kind of line break, so edits compare fairly
            $editId = $id;

            $titleLength = preg_match_all('/./us', $title);
            $bodyLength  = preg_match_all('/./us', $body);
            if ($titleLength < 1 || $titleLength > 200) {
                $errors[] = 'The title must be 1 to 200 characters.';
            }
            if ($bodyLength < 1 || $bodyLength > 16000) {
                $errors[] = 'The post must be 1 to 16000 characters.';
            }
            if ($id > 0 && !can_manage($pdo, $id, $me, $isAdmin)) {
                $errors[] = 'You can only edit your own posts.';
            }

            // Slow down spam: one new post a minute for members (admins are not limited).
            if (!$errors && $id === 0 && !$isAdmin) {
                $recent = $pdo->prepare('SELECT COUNT(*) FROM post_authors pa JOIN posts p ON p.id = pa.post_id WHERE pa.user_id = ? AND p.created_at > (NOW() - INTERVAL 60 SECOND)');
                $recent->execute([$me]);
                if ((int) $recent->fetchColumn() > 0) {
                    $errors[] = 'Please wait a minute before posting again.';
                }
            }

            // Pictures: check and shrink them first, so a bad file stops everything before anything is saved.
            $removeIds = array_values(array_filter(array_map('intval', (array) ($_POST['remove'] ?? []))));
            $newMedia  = [];
            if (!$errors) {
                $room     = MEDIA_MAX_PER_POST - ($id > 0 ? media_count($pdo, $id, $removeIds) : 0);
                $newMedia = prepare_media(normalize_files($_FILES['media'] ?? null), $room, $errors);
            }
            if (!$errors && $newMedia && !$isAdmin) {
                $adding = array_sum(array_map(fn($m) => strlen($m['data']), $newMedia));
                if (member_media_bytes($pdo, $me) + $adding > MEDIA_MEMBER_QUOTA) {
                    $errors[] = "You've used up your picture storage. Remove pictures from an older post first.";
                }
            }

            if (!$errors) {
                $postId = $id;
                if ($id > 0) {
                    // Keep the history: remember the post as it was, then save the change as the next version.
                    $oldStmt = $pdo->prepare('SELECT title, body, created_at FROM posts WHERE id = ?');
                    $oldStmt->execute([$id]);
                    $before = $oldStmt->fetch();
                    $oldBody = $before ? str_replace(["\r\n", "\r"], "\n", $before['body']) : '';
                    $changed = $before && ($before['title'] !== $title || $oldBody !== $body);
                    $saved = false;

                    if ($changed) {
                        try {
                            $pdo->beginTransaction();
                            $count = $pdo->prepare('SELECT COUNT(*) FROM post_versions WHERE post_id = ?');
                            $count->execute([$id]);
                            $count = (int) $count->fetchColumn();

                            if ($count === 0) {
                                // The first edit: store the original text as version 1, dated when it was first published.
                                $pdo->prepare('INSERT INTO post_versions (post_id, version, title, body, edited_by, created_at) VALUES (?, 1, ?, ?, ?, ?)')
                                    ->execute([$id, $before['title'], $oldBody, post_author_id($pdo, 'post-' . $id) ?: null, $before['created_at']]);
                                $count = 1;
                            }
                            $pdo->prepare('UPDATE posts SET title = ?, body = ? WHERE id = ?')->execute([$title, $body, $id]);
                            $pdo->prepare('INSERT INTO post_versions (post_id, version, title, body, edited_by) VALUES (?, ?, ?, ?, ?)')
                                ->execute([$id, $count + 1, $title, $body, $me]);
                            $pdo->commit();
                            $saved = true;
                        } catch (PDOException $ex) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            error_log($ex->getMessage());   // no history table yet: fall back to a plain save below
                        }
                    }
                    if (!$saved) {
                        $pdo->prepare('UPDATE posts SET title = ?, body = ? WHERE id = ?')->execute([$title, $body, $id]);
                    }
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO posts (title, body) VALUES (?, ?)')->execute([$title, $body]);
                    $postId = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO post_authors (post_id, user_id) VALUES (?, ?)')->execute([$postId, $me]);
                    $pdo->commit();
                }
                if ($id > 0 && $removeIds) {
                    remove_media($pdo, $id, $removeIds);
                }
                save_media($pdo, $postId, $newMedia);
                header('Location: write.php?done=saved');
                exit;
            }
        }
    }
}

// Admins see every post here; everyone else sees their own.
$sql = 'SELECT p.id, p.title, UNIX_TIMESTAMP(p.created_at) AS ts, u.username
        FROM posts p
        LEFT JOIN post_authors pa ON pa.post_id = p.id
        LEFT JOIN users u ON u.id = pa.user_id'
     . ($isAdmin ? '' : ' WHERE pa.user_id = ?')
     . ' ORDER BY p.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($isAdmin ? [] : [$me]);
$posts = $stmt->fetchAll();
$done  = (string) ($_GET['done'] ?? '');

$currentMedia = [];
if ($editId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id FROM post_media WHERE post_id = ? ORDER BY position, id');
        $stmt->execute([$editId]);
        $currentMedia = $stmt->fetchAll();
    } catch (PDOException $ex) {
        // The pictures table isn't there yet: run the latest schema.sql.
    }
}

page_head($editId ? 'Edit post' : 'Write a post');
?>
<?php if ($done === 'saved'): ?>
<p style="text-align: center;">Post saved. <a href="index.html">See it on the site</a></p>
<?php elseif ($done === 'deleted'): ?>
<p style="text-align: center;">Post deleted.</p>
<?php endif; ?>

<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<form class="auth" method="post" action="write.php<?= $editId ? '?id=' . $editId : '' ?>" enctype="multipart/form-data" hx-boost="false" style="max-width: 720px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editId ?>">

    <label for="title">Title</label>
    <input id="title" name="title" type="text" value="<?= e($title) ?>" required maxlength="200">

    <label for="body">Post (a blank line starts a new paragraph)</label>
    <div id="toolbar" style="display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0;">
        <button type="button" data-fmt="bold" title="Bold"><b>B</b></button>
        <button type="button" data-fmt="italic" title="Italic"><i>I</i></button>
        <button type="button" data-fmt="link" title="Link">Link</button>
        <button type="button" data-fmt="code" title="Code">&lt;/&gt;</button>
        <button type="button" data-fmt="list" title="Bullet list">&bull; List</button>
        <button type="button" data-fmt="quote" title="Quote">&ldquo; Quote</button>
        <button type="button" data-fmt="heading" title="Heading">H</button>
        <button type="button" id="preview-btn" style="margin-left: auto;">Preview</button>
    </div>
    <textarea id="body" name="body" rows="14" required maxlength="16000" style="display: block; width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg); font: inherit;"><?= e($body) ?></textarea>

    <label for="media">Pictures (optional): up to <?= MEDIA_MAX_PER_POST ?> JPG, PNG, WebP or GIF files. Photos are shrunk to save space. GIFs can be up to <?= MEDIA_MAX_GIF_BYTES / 1048576 ?> MB. No videos.</label>
    <input id="media" name="media[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
<?php if ($currentMedia): ?>
    <p style="margin: 12px 0 4px;">Pictures on this post (tick the ones to remove):</p>
    <div style="display: flex; flex-wrap: wrap; gap: 12px;">
<?php foreach ($currentMedia as $m): ?>
        <label style="display: block; margin: 0;">
            <img src="media.php?id=<?= (int) $m['id'] ?>" alt="" width="96" height="96" style="display: block; object-fit: cover; border-radius: 8px;">
            <input type="checkbox" name="remove[]" value="<?= (int) $m['id'] ?>" style="width: auto; padding: 0;"> remove
        </label>
<?php endforeach; ?>
    </div>
<?php endif; ?>

    <div id="preview" class="fmt" hidden style="margin-top: 8px; padding: 12px; border: 1px solid var(--line); border-radius: 8px; overflow-wrap: anywhere;"></div>
    <p style="margin: 8px 0 0; opacity: 0.7; font-size: 0.85em;">Formatting: **bold**, *italic*, [link text](https://address), `code`, a line starting with "- " for a list, "&gt; " for a quote, "## " for a heading.</p>

    <button type="button" id="check-btn" style="background: none; color: inherit;">Check spelling &amp; grammar</button>
    <p id="check-msg" style="margin: 8px 0 0; min-height: 1.2em;"></p>
    <div id="check-result" hidden style="margin-top: 8px; padding: 12px; border: 1px solid var(--line); border-radius: 8px;">
        <div id="check-diff" style="white-space: pre-wrap; overflow-wrap: anywhere;"></div>
        <div style="display: flex; gap: 8px;">
            <button type="button" id="check-use">Use corrected version</button>
            <button type="button" id="check-keep" style="background: none; color: inherit;">Keep mine</button>
        </div>
    </div>
    <p style="margin: 12px 0 0; opacity: 0.7; font-size: 0.85em;">The checker sends your text to Anthropic's Claude. It only fixes spelling, grammar and punctuation, and nothing changes until you choose.</p>

    <button type="submit"><?= $editId ? 'Save changes' : 'Publish post' ?></button>
</form>
<?php if ($editId): ?>
<p style="text-align: center;"><a href="write.php">Write a new post instead</a></p>
<?php endif; ?>

<h2><?= $isAdmin ? 'All posts written here' : 'Your posts' ?></h2>
<?php if (!$posts): ?>
<p style="text-align: center;">Nothing yet.</p>
<?php else: ?>
<ul style="max-width: 720px; margin: 0 auto 1.5rem; padding: 0 12px; list-style: none;">
<?php foreach ($posts as $p): ?>
    <li style="padding: 8px 0; border-bottom: 1px solid var(--line);">
        <?= e($p['title']) ?>
        <span style="opacity: 0.7;"> &middot; <?= e(date('j M Y', (int) $p['ts'])) ?><?= $isAdmin && $p['username'] ? ' &middot; @' . e($p['username']) : '' ?></span>
        &middot; <a href="write.php?id=<?= (int) $p['id'] ?>">edit</a>
        &middot;
        <form method="post" action="write.php" hx-boost="false" style="display: inline;" onsubmit="return confirm('Delete this post and its comments, likes and views?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" style="background: none; border: none; padding: 0; color: inherit; font: inherit; text-decoration: underline; cursor: pointer;">delete</button>
        </form>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<style>
    #toolbar button { width: auto; margin: 0; padding: 4px 10px; background: none; color: inherit; }
    #check-diff ins { background: rgba(46, 158, 91, 0.3); text-decoration: none; }
    #check-diff del { background: rgba(192, 57, 43, 0.3); }
</style>
<script>
    // "Check spelling & grammar": asks check.php for a corrected version and shows what would change.
    (function () {
        var btn = document.getElementById("check-btn");
        if (!btn) return;
        var box = document.getElementById("body");
        var msg = document.getElementById("check-msg");
        var result = document.getElementById("check-result");
        var diffEl = document.getElementById("check-diff");
        var csrf = document.querySelector('input[name="csrf"]').value;
        var corrected = "";

        function tokens(s) { return s.split(/(\s+)/).filter(function (t) { return t !== ""; }); }

        // Word by word: what stays the same, what goes, and what is new.
        function diff(a, b) {
            var x = tokens(a), y = tokens(b), start = 0;
            while (start < x.length && start < y.length && x[start] === y[start]) start++;
            var endX = x.length, endY = y.length;
            while (endX > start && endY > start && x[endX - 1] === y[endY - 1]) { endX--; endY--; }
            var n = endX - start, m = endY - start;
            if (n * m > 25000000) return null;
            var w = m + 1, t = new Uint16Array((n + 1) * w), i, j, k;
            for (i = n - 1; i >= 0; i--)
                for (j = m - 1; j >= 0; j--)
                    t[i * w + j] = x[start + i] === y[start + j] ? t[(i + 1) * w + j + 1] + 1 : Math.max(t[(i + 1) * w + j], t[i * w + j + 1]);
            var out = [];
            for (k = 0; k < start; k++) out.push(["=", x[k]]);
            i = 0; j = 0;
            while (i < n && j < m) {
                if (x[start + i] === y[start + j]) { out.push(["=", x[start + i]]); i++; j++; }
                else if (t[(i + 1) * w + j] >= t[i * w + j + 1]) { out.push(["-", x[start + i]]); i++; }
                else { out.push(["+", y[start + j]]); j++; }
            }
            while (i < n) { out.push(["-", x[start + i]]); i++; }
            while (j < m) { out.push(["+", y[start + j]]); j++; }
            for (k = endX; k < x.length; k++) out.push(["=", x[k]]);
            return out;
        }

        function show(original, fixed) {
            diffEl.textContent = "";
            var parts = diff(original, fixed);
            if (!parts) { diffEl.textContent = fixed; return; }
            parts.forEach(function (p) {
                var el = p[0] === "=" ? document.createTextNode(p[1]) : document.createElement(p[0] === "-" ? "del" : "ins");
                if (p[0] !== "=") el.textContent = p[1];
                diffEl.appendChild(el);
            });
        }

        btn.addEventListener("click", function () {
            var text = box.value.trim();
            if (!text) { msg.textContent = "Write something first."; return; }
            btn.disabled = true;
            msg.textContent = "Checking...";
            result.hidden = true;
            fetch("check.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ csrf: csrf, text: text })
            })
                .then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (d) {
                        if (!r.ok) throw new Error(d.error || "The checker isn't available right now.");
                        return d;
                    });
                })
                .then(function (d) {
                    corrected = d.corrected;
                    var left = " " + d.remaining + " checks left today.";
                    if (corrected.trim() === text) { msg.textContent = "No mistakes found." + left; return; }
                    show(text, corrected.trim());
                    result.hidden = false;
                    msg.textContent = "Green is new, red is removed." + left;
                })
                .catch(function (e) { msg.textContent = e.message; })
                .then(function () { btn.disabled = false; });
        });

        document.getElementById("check-use").addEventListener("click", function () {
            box.value = corrected;
            result.hidden = true;
            msg.textContent = "The corrected version is in the box. Read it through before you publish.";
        });
        document.getElementById("check-keep").addEventListener("click", function () {
            result.hidden = true;
            msg.textContent = "";
        });
    })();
</script>
<script>
    // The formatting buttons put the symbols around your selection, and Preview shows how the post will look.
    (function () {
        var ta = document.getElementById("body");
        var preview = document.getElementById("preview");
        var previewBtn = document.getElementById("preview-btn");

        function wrap(before, after, placeholder) {
            var s = ta.selectionStart, e = ta.selectionEnd;
            var selected = ta.value.slice(s, e) || placeholder;
            ta.value = ta.value.slice(0, s) + before + selected + after + ta.value.slice(e);
            ta.focus();
            ta.setSelectionRange(s + before.length, s + before.length + selected.length);
        }

        function prefixLines(prefix) {
            var v = ta.value, s = ta.selectionStart, e = ta.selectionEnd;
            var start = v.lastIndexOf("\n", s - 1) + 1;
            var end = v.indexOf("\n", e);
            if (end === -1) end = v.length;
            var block = v.slice(start, end).split("\n").map(function (l) { return prefix + l; }).join("\n");
            ta.value = v.slice(0, start) + block + v.slice(end);
            ta.focus();
            ta.setSelectionRange(start, start + block.length);
        }

        function refresh() {
            preview.textContent = "";
            preview.appendChild(window.renderPost(ta.value));
        }

        document.querySelectorAll("#toolbar [data-fmt]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var kind = btn.getAttribute("data-fmt");
                if (kind === "bold") wrap("**", "**", "bold text");
                else if (kind === "italic") wrap("*", "*", "italic text");
                else if (kind === "code") wrap("`", "`", "code");
                else if (kind === "list") prefixLines("- ");
                else if (kind === "quote") prefixLines("> ");
                else if (kind === "heading") prefixLines("## ");
                else if (kind === "link") {
                    var url = prompt("Link address (it must start with https://)", "https://");
                    if (url && /^https?:\/\//.test(url)) wrap("[", "](" + url + ")", "link text");
                }
                if (!preview.hidden) refresh();
            });
        });

        previewBtn.addEventListener("click", function () {
            preview.hidden = !preview.hidden;
            previewBtn.textContent = preview.hidden ? "Preview" : "Hide preview";
            if (!preview.hidden) refresh();
        });
        ta.addEventListener("input", function () { if (!preview.hidden) refresh(); });
    })();
</script>
<?php page_foot();
