<?php
require_once __DIR__ . '/api/db.php';

initDatabase();

$siteSettings = getSiteSettings();
$frontendAnalyticsCode = trim($siteSettings['frontend_analytics_code'] ?? '');
$token = trim((string)($_GET['t'] ?? $_GET['token'] ?? ''));
$share = null;

if ($token !== '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM shared_conversations WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $share = $stmt->fetch();

    if ($share) {
        $stmt = $db->prepare("UPDATE shared_conversations
            SET view_count = view_count + 1, updated_at = datetime('now')
            WHERE id = ?");
        $stmt->execute([$share['id']]);
        $share['view_count'] = (int)$share['view_count'] + 1;
    }
}

if (!$share) {
    http_response_code(404);
}

function esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function decodeJsonListForShare($value) {
    if ($value === null || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeSuggestionText($item) {
    if (is_string($item)) {
        return trim($item);
    }

    if (is_array($item)) {
        return trim((string)($item['text'] ?? $item['title'] ?? $item['label'] ?? ''));
    }

    return '';
}

function normalizeMarkdownUrl($url) {
    return trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
}

function isSafeMarkdownUrl($url) {
    return (bool)(preg_match('#^https?://#i', $url) || preg_match('#^/(?!/)#', $url) || str_starts_with($url, '#'));
}

function stripMarkdownHtmlTags($value) {
    return strip_tags((string)$value);
}

function splitMarkdownTrailingPunctuation($url) {
    $clean = (string)$url;
    $tail = '';

    while ($clean !== '' && preg_match('/([.,!?;:，。！？；：）)])$/u', $clean, $matches)) {
        $tail = $matches[1] . $tail;
        $clean = substr($clean, 0, -strlen($matches[1]));
    }

    return [$clean, $tail];
}

function renderInlineMarkdown($text) {
    $html = esc($text);
    $codePlaceholders = [];
    $htmlPlaceholders = [];

    $stashHtml = function ($value) use (&$htmlPlaceholders) {
        $placeholder = '@@SHAREHTML' . count($htmlPlaceholders) . '@@';
        $htmlPlaceholders[] = $value;
        return $placeholder;
    };

    $html = preg_replace_callback('/`([^`\n]+)`/u', function ($matches) use (&$codePlaceholders) {
        $placeholder = '@@SHARECODE' . count($codePlaceholders) . '@@';
        $codePlaceholders[] = '<code>' . $matches[1] . '</code>';
        return $placeholder;
    }, $html);

    $html = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $html);
    $html = preg_replace('/__([^_]+)__/u', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $html);
    $html = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '<em>$1</em>', $html);
    $html = preg_replace('/~~([^~]+)~~/u', '<del>$1</del>', $html);

    $html = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;[^&]*&quot;)?\)/u', function ($matches) use ($stashHtml) {
        $alt = stripMarkdownHtmlTags($matches[1]);
        $url = normalizeMarkdownUrl($matches[2]);

        if (!isSafeMarkdownUrl($url)) {
            return $alt;
        }

        return $stashHtml('<img src="' . esc($url) . '" alt="' . esc($alt) . '" loading="lazy" referrerpolicy="no-referrer">');
    }, $html);

    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/u', function ($matches) use ($stashHtml) {
        $label = $matches[1];
        $url = normalizeMarkdownUrl($matches[2]);

        if (!isSafeMarkdownUrl($url)) {
            return $label;
        }

        return $stashHtml('<a href="' . esc($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>');
    }, $html);

    $html = preg_replace_callback('/(^|[\s(>])((?:https?:\/\/)[^\s<]+)/u', function ($matches) use ($stashHtml) {
        [$visibleUrl, $tail] = splitMarkdownTrailingPunctuation($matches[2]);
        $url = normalizeMarkdownUrl($visibleUrl);

        if (!isSafeMarkdownUrl($url)) {
            return $matches[0];
        }

        return $matches[1] . $stashHtml('<a href="' . esc($url) . '" target="_blank" rel="noopener noreferrer">' . esc($url) . '</a>') . $tail;
    }, $html);

    foreach ($htmlPlaceholders as $index => $value) {
        $html = str_replace('@@SHAREHTML' . $index . '@@', $value, $html);
    }

    foreach ($codePlaceholders as $index => $value) {
        $html = str_replace('@@SHARECODE' . $index . '@@', $value, $html);
    }

    return $html;
}

function splitMarkdownTableLine($line) {
    $line = trim((string)$line);
    $line = preg_replace('/^\|/', '', $line);
    $line = preg_replace('/\|$/', '', $line);

    return array_map('trim', explode('|', $line));
}

function isMarkdownTableRow($line) {
    return str_starts_with(trim((string)$line), '|') && str_ends_with(trim((string)$line), '|');
}

function isMarkdownTableSeparator($line) {
    if (!isMarkdownTableRow($line)) {
        return false;
    }

    $cells = splitMarkdownTableLine($line);
    if (!$cells) {
        return false;
    }

    foreach ($cells as $cell) {
        if (!preg_match('/^:?-{3,}:?$/', preg_replace('/\s+/', '', $cell))) {
            return false;
        }
    }

    return true;
}

function renderSharedTable($headerLine, $rows) {
    $headers = splitMarkdownTableLine($headerLine);
    $html = '<div class="share-table-wrap"><table><thead><tr>';

    foreach ($headers as $header) {
        $html .= '<th>' . renderInlineMarkdown($header) . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $cells = splitMarkdownTableLine($row);
        $html .= '<tr>';
        foreach ($cells as $cell) {
            $html .= '<td>' . renderInlineMarkdown($cell) . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}

function renderSharedMarkdown($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $lines = explode("\n", $text);
    $html = [];
    $listType = null;
    $inParagraph = false;
    $inCode = false;
    $codeLang = '';
    $codeBuffer = [];

    $closeParagraph = function () use (&$html, &$inParagraph) {
        if ($inParagraph) {
            $html[] = '</p>';
            $inParagraph = false;
        }
    };

    $closeList = function () use (&$html, &$listType) {
        if ($listType) {
            $html[] = '</' . $listType . '>';
            $listType = null;
        }
    };

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $trim = trim($line);

        if ($inCode) {
            if (preg_match('/^```/', $trim)) {
                $preAttr = $codeLang !== '' ? ' data-lang="' . esc($codeLang) . '"' : '';
                $codeClass = $codeLang !== '' ? ' class="language-' . esc(preg_replace('/[^\w-]/', '-', $codeLang)) . '"' : '';
                $html[] = '<pre' . $preAttr . '><code' . $codeClass . '>' . esc(implode("\n", $codeBuffer)) . '</code></pre>';
                $codeBuffer = [];
                $inCode = false;
                $codeLang = '';
            } else {
                $codeBuffer[] = $line;
            }
            continue;
        }

        if (preg_match('/^```([^\n`]*)/', $trim, $matches)) {
            $closeParagraph();
            $closeList();
            $inCode = true;
            $codeLang = substr(preg_replace('/[^\w#+.-]/', '', trim($matches[1])), 0, 32);
            $codeBuffer = [];
            continue;
        }

        if (($i + 1) < count($lines) && isMarkdownTableRow($trim) && isMarkdownTableSeparator($lines[$i + 1])) {
            $closeParagraph();
            $closeList();
            $tableRows = [];
            $i += 2;
            while ($i < count($lines) && isMarkdownTableRow($lines[$i])) {
                $tableRows[] = $lines[$i];
                $i++;
            }
            $i--;
            $html[] = renderSharedTable($trim, $tableRows);
            continue;
        }

        if ($trim === '') {
            $closeParagraph();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $trim, $matches)) {
            $closeParagraph();
            $closeList();
            $level = min(6, strlen($matches[1]));
            $html[] = '<h' . $level . '>' . renderInlineMarkdown($matches[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^[-*_]{3,}$/', $trim)) {
            $closeParagraph();
            $closeList();
            $html[] = '<hr>';
            continue;
        }

        if (preg_match('/^>\s?(.+)$/u', $trim, $matches)) {
            $closeParagraph();
            $closeList();
            $html[] = '<blockquote><p>' . renderInlineMarkdown($matches[1]) . '</p></blockquote>';
            continue;
        }

        if (preg_match('/^[-*+]\s+\[( |x|X)\]\s+(.+)$/u', $trim, $matches)) {
            $closeParagraph();
            if ($listType !== 'ul') {
                $closeList();
                $html[] = '<ul>';
                $listType = 'ul';
            }
            $checked = preg_match('/x/i', $matches[1]);
            $html[] = '<li class="task-list-item"><span class="task-checkbox' . ($checked ? ' checked' : '') . '" role="checkbox" aria-checked="' . ($checked ? 'true' : 'false') . '"></span><span>' . renderInlineMarkdown($matches[2]) . '</span></li>';
            continue;
        }

        if (preg_match('/^[-*+•]\s+(.+)$/u', $trim, $matches)) {
            $closeParagraph();
            if ($listType !== 'ul') {
                $closeList();
                $html[] = '<ul>';
                $listType = 'ul';
            }
            $html[] = '<li>' . renderInlineMarkdown($matches[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/u', $trim, $matches)) {
            $closeParagraph();
            if ($listType !== 'ol') {
                $closeList();
                $html[] = '<ol>';
                $listType = 'ol';
            }
            $html[] = '<li>' . renderInlineMarkdown($matches[1]) . '</li>';
            continue;
        }

        $closeList();
        if (!$inParagraph) {
            $html[] = '<p>';
            $inParagraph = true;
        } else {
            $html[] = '<br>';
        }
        $html[] = renderInlineMarkdown($line);
    }

    if ($inCode) {
        $preAttr = $codeLang !== '' ? ' data-lang="' . esc($codeLang) . '"' : '';
        $codeClass = $codeLang !== '' ? ' class="language-' . esc(preg_replace('/[^\w-]/', '-', $codeLang)) . '"' : '';
        $html[] = '<pre' . $preAttr . '><code' . $codeClass . '>' . esc(implode("\n", $codeBuffer)) . '</code></pre>';
    }

    $closeParagraph();
    $closeList();

    return implode('', $html);
}

function formatShareTime($value) {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) {
        return '';
    }

    return date('Y-m-d H:i', $timestamp);
}

$pageTitle = $share ? ($share['title'] ?: '分享的对话') : '分享不存在';
$suggestions = $share ? array_values(array_filter(array_map('normalizeSuggestionText', decodeJsonListForShare($share['suggestions'] ?? null)))) : [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc($pageTitle); ?> - <?php echo esc($siteSettings['frontend_site_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .share-markdown { color: #374151; font-size: 15px; line-height: 1.8; }
        .share-markdown h1, .share-markdown h2, .share-markdown h3,
        .share-markdown h4, .share-markdown h5, .share-markdown h6 {
            color: #111827; font-weight: 700; line-height: 1.35; letter-spacing: 0; margin: 1.25rem 0 0.75rem;
        }
        .share-markdown h1 { font-size: 1.45rem; padding-bottom: 0.45rem; border-bottom: 1px solid #e5e7eb; }
        .share-markdown h2 { font-size: 1.25rem; }
        .share-markdown h3 { font-size: 1.1rem; }
        .share-markdown h4 { font-size: 1rem; }
        .share-markdown h5, .share-markdown h6 { color: #64748b; font-size: 0.95rem; }
        .share-markdown p { margin: 0.75rem 0; }
        .share-markdown ul, .share-markdown ol { margin: 0.75rem 0; padding-left: 1.25rem; }
        .share-markdown ul { list-style: disc; }
        .share-markdown ol { list-style: decimal; }
        .share-markdown li { margin: 0.35rem 0; }
        .share-markdown li.task-list-item { position: relative; list-style: none; padding-left: 1.6rem; }
        .share-markdown .task-checkbox {
            position: absolute; left: 0; top: 0.35rem; width: 1rem; height: 1rem;
            border-radius: 0.3rem; border: 1px solid #cbd5e1; background: #fff;
        }
        .share-markdown .task-checkbox.checked { border-color: #2563eb; background: #2563eb; }
        .share-markdown .task-checkbox.checked::after {
            content: ''; position: absolute; left: 0.31rem; top: 0.12rem; width: 0.3rem; height: 0.55rem;
            border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
        }
        .share-markdown strong { color: #1e40af; font-weight: 700; }
        .share-markdown em { color: #6b7280; }
        .share-markdown del { color: #64748b; text-decoration-thickness: 2px; }
        .share-markdown blockquote { border-left: 3px solid #93c5fd; background: #eff6ff; color: #1e3a8a; padding: 0.75rem 1rem; margin: 1rem 0; border-radius: 8px; }
        .share-markdown pre { position: relative; background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        .share-markdown pre[data-lang] { padding-top: 2.1rem; }
        .share-markdown pre[data-lang]::before { content: attr(data-lang); position: absolute; top: 0.6rem; right: 0.85rem; color: #94a3b8; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .share-markdown code { background: #f1f5f9; color: #1e293b; padding: 0.15rem 0.35rem; border-radius: 6px; font-size: 0.9em; }
        .share-markdown pre code { background: transparent; color: inherit; padding: 0; border-radius: 0; }
        .share-markdown a { color: #2563eb; text-decoration: underline; text-underline-offset: 3px; }
        .share-markdown img { display: block; max-width: 100%; height: auto; margin: 1rem 0; border: 1px solid #e2e8f0; border-radius: 10px; }
        .share-markdown .share-table-wrap { overflow-x: auto; margin: 1rem 0; border: 1px solid #e5e7eb; border-radius: 10px; }
        .share-markdown table { width: 100%; min-width: 34rem; border-collapse: collapse; font-size: 0.9rem; }
        .share-markdown th, .share-markdown td { padding: 0.75rem 0.9rem; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        .share-markdown th { background: #f8fafc; color: #1e293b; font-weight: 700; }
        .share-markdown tr:last-child td { border-bottom: 0; }
        .share-markdown hr { border: 0; border-top: 1px solid #e5e7eb; margin: 1.25rem 0; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <i class="fas fa-robot text-sm"></i>
                    </span>
                    <span class="truncate"><?php echo esc($siteSettings['frontend_site_name']); ?></span>
                </div>
            </div>
            <?php if ($share): ?>
                <button onclick="copyShareUrl(this)" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">
                    <i class="fas fa-link"></i>
                    <span>复制链接</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-8 sm:py-10">
        <?php if (!$share): ?>
            <section class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                    <i class="fas fa-link-slash"></i>
                </div>
                <h1 class="text-xl font-bold text-slate-900">分享链接不可用</h1>
                <p class="mt-2 text-sm leading-6 text-slate-500">该链接不存在、参数缺失，或原始对话已经被删除。</p>
                <a href="login.php" class="mt-6 inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700">
                    返回系统
                </a>
            </section>
        <?php else: ?>
            <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                        <span><i class="fas fa-globe mr-1"></i>公开分享</span>
                        <span>·</span>
                        <span><?php echo esc(formatShareTime($share['created_at'])); ?></span>
                        <span>·</span>
                        <span><?php echo (int)$share['view_count']; ?> 次浏览</span>
                    </div>
                    <h1 class="break-words text-2xl font-bold text-slate-950 sm:text-3xl"><?php echo esc($pageTitle); ?></h1>
                </div>

                <?php if (trim((string)$share['user_content']) !== ''): ?>
                    <section class="border-b border-slate-100 bg-slate-50 px-5 py-5 sm:px-7">
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">提问</div>
                        <div class="whitespace-pre-wrap break-words text-[15px] leading-7 text-slate-700"><?php echo esc($share['user_content']); ?></div>
                    </section>
                <?php endif; ?>

                <section class="px-5 py-6 sm:px-7">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">回答</div>
                    <div class="share-markdown break-words">
                        <?php echo renderSharedMarkdown($share['assistant_content']); ?>
                    </div>
                </section>

                <?php if (!empty($suggestions)): ?>
                    <section class="border-t border-slate-100 bg-slate-50 px-5 py-5 sm:px-7">
                        <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">推荐追问</div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($suggestions as $suggestion): ?>
                                <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600"><?php echo esc($suggestion); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </article>

            <footer class="mt-6 text-center text-xs leading-6 text-slate-400">
                <?php echo esc($siteSettings['copyright_text']); ?>
            </footer>
        <?php endif; ?>
    </main>

    <script>
        async function copyShareUrl(button) {
            const url = window.location.href;
            const original = button.innerHTML;
            let copied = false;

            try {
                if (navigator.clipboard) {
                    await navigator.clipboard.writeText(url);
                    copied = true;
                }
            } catch (error) {
                copied = false;
            }

            if (!copied) {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                copied = document.execCommand('copy');
                textarea.remove();
            }

            if (!copied) {
                window.prompt('复制分享链接', url);
                return;
            }

            button.innerHTML = '<i class="fas fa-check"></i><span>已复制</span>';
            button.classList.add('border-blue-200', 'bg-blue-50', 'text-blue-700');
            setTimeout(() => {
                button.innerHTML = original;
                button.classList.remove('border-blue-200', 'bg-blue-50', 'text-blue-700');
            }, 1600);
        }
    </script>
    <?php if ($frontendAnalyticsCode !== ''): ?>
    <?php echo "\n" . $frontendAnalyticsCode . "\n"; ?>
    <?php endif; ?>
</body>
</html>
