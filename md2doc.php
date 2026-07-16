<?php
$md = file_get_contents(__DIR__.'/../docs/interview-v2.6.1-issues.md');

$html = '<html><head><meta charset="UTF-8"><style>
body{font-family:SimSun;font-size:14px;line-height:1.8;max-width:900px;margin:40px auto;padding:20px}
h1{font-size:22px;border-bottom:2px solid #333;padding-bottom:8px}
h2{font-size:18px;color:#1a1a8a;margin-top:24px}
h3{font-size:15px;color:#333}
code{background:#f5f5f5;padding:2px 6px;border-radius:3px;font-family:Consolas,monospace;font-size:12px}
pre{background:#f0f0f0;padding:12px;border-left:3px solid #6366f1;overflow-x:auto;white-space:pre-wrap}
table{border-collapse:collapse;width:100%;margin:12px 0}
th,td{border:1px solid #ddd;padding:8px 12px;text-align:left}
th{background:#6366f1;color:#fff}
tr:nth-child(even){background:#fafafa}
strong{color:#c00}
</style></head><body>';

$lines = explode("\n", $md);
$inCode = false;
$inTable = false;
$tableRows = [];

foreach ($lines as $line) {
    // Code block
    if (preg_match('/^```/', $line)) {
        if ($inCode) { $html .= "</pre>\n"; $inCode = false; }
        else { $inCode = true; $html .= "<pre>"; }
        continue;
    }
    if ($inCode) { $html .= htmlspecialchars($line) . "\n"; continue; }

    // Table
    if (preg_match('/^\|(.+)\|$/', $line)) {
        if (strpos($line, '---') !== false) continue; // skip separator
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (!$inTable) { $html .= "<table>\n"; $inTable = true; }
        $tag = empty($tableRows) ? 'th' : 'td';
        $html .= "<tr>" . implode('', array_map(fn($c) => "<$tag>" . htmlspecialchars($c) . "</$tag>", $cells)) . "</tr>\n";
        $tableRows[] = $cells;
        continue;
    } elseif ($inTable) {
        $html .= "</table>\n"; $inTable = false; $tableRows = [];
    }

    // Headings
    if (preg_match('/^### (.+)/', $line, $m)) { $html .= "<h3>{$m[1]}</h3>\n"; continue; }
    if (preg_match('/^## (.+)/', $line, $m)) { $html .= "<h2>{$m[1]}</h2>\n"; continue; }
    if (preg_match('/^# (.+)/', $line, $m)) { $html .= "<h1>{$m[1]}</h1>\n"; continue; }

    // Bold
    $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
    // Inline code
    $line = preg_replace('/`([^`]+)`/', '<code>$1</code>', $line);
    // List items
    if (preg_match('/^- (.+)/', $line, $m)) { $html .= "<li>{$m[1]}</li>\n"; continue; }
    // Empty line
    if (trim($line) === '') { $html .= "<br>\n"; continue; }

    $html .= "<p>" . $line . "</p>\n";
}

if ($inTable) $html .= "</table>\n";
if ($inCode) $html .= "</pre>\n";
$html .= '</body></html>';

file_put_contents(__DIR__.'/../docs/interview-senior-engineer-questions.doc', $html);
echo "DOC saved: docs/interview-senior-engineer-questions.doc\n";
echo "用 Word 打开即可\n";
