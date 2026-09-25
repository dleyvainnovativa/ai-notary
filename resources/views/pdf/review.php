<?php
/**
 * Review PDF — generic for every module. Plain PHP (not Blade) so it renders
 * identically inside Laravel and in standalone tests. dompdf-safe CSS only:
 * tables for layout, no flexbox/grid.
 *
 * @var array $doc       header meta: module, reference, notaria, generated_at, status, draft_saved_at
 * @var array $sections  from ReviewPdfPresenter::present()
 */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$renderNotes = function (array $notes) use ($e) {
    foreach ($notes as $n) {
        echo '<div class="note"><span class="note__tag">Verificar</span> ' . $e($n) . '</div>';
    }
};

$renderGrid = function (array $items) use ($e, $renderNotes) {
    // 3 columns; "full" cells take a whole row
    $rows = [];
    $current = [];
    foreach ($items as $it) {
        if ($it['full']) {
            if ($current) { $rows[] = $current; $current = []; }
            $rows[] = [$it];
            continue;
        }
        $current[] = $it;
        if (count($current) === 3) { $rows[] = $current; $current = []; }
    }
    if ($current) $rows[] = $current;

    echo '<table class="grid">';
    foreach ($rows as $row) {
        echo '<tr>';
        $span = count($row) === 1 && $row[0]['full'] ? 3 : 1;
        foreach ($row as $it) {
            echo '<td class="cell" colspan="' . $span . '">';
            echo '<div class="cell__label">' . $e($it['label']) . '</div>';
            if ($it['value'] !== null) {
                echo '<div class="cell__value">' . $e($it['value']) . '</div>';
            } elseif ($it['missing']) {
                echo '<div class="cell__value"><span class="missing">Falta</span></div>';
            } else {
                echo '<div class="cell__value cell__value--empty">—</div>';
            }
            $renderNotes($it['notes']);
            echo '</td>';
        }
        for ($i = count($row) * $span; $i < 3; $i++) echo '<td class="cell"></td>';
        echo '</tr>';
    }
    echo '</table>';
};

$renderBlocks = function (array $blocks, int $depth = 0) use (&$renderBlocks, $renderGrid, $renderNotes, $e) {
    foreach ($blocks as $b) {
        switch ($b['type']) {
            case 'grid':
                $renderGrid($b['items']);
                break;

            case 'group':
                echo '<div class="group">';
                if ($b['title'] !== null) echo '<div class="group__title">' . $e($b['title']) . '</div>';
                $renderNotes($b['notes']);
                $renderBlocks($b['blocks'], $depth + 1);
                echo '</div>';
                break;

            case 'empty':
                if ($b['title'] !== null) echo '<div class="subhead">' . $e($b['title']) . '</div>';
                echo '<div class="empty">' . $e($b['text']) . '</div>';
                break;

            case 'table':
                if ($b['title'] !== null) echo '<div class="subhead">' . $e($b['title']) . '</div>';
                echo '<table class="data"><thead><tr>';
                foreach ($b['columns'] as $c) {
                    echo '<th' . ($c['numeric'] ? ' class="num"' : '') . '>' . $e($c['label']) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($b['rows'] as $cells) {
                    echo '<tr>';
                    foreach ($cells as $c) {
                        echo '<td' . ($c['numeric'] ? ' class="num"' : '') . '>';
                        if ($c['hidden']) echo '';
                        elseif ($c['value'] !== null) echo $e($c['value']);
                        elseif ($c['missing']) echo '<span class="missing">Falta</span>';
                        else echo '<span class="muted">—</span>';
                        foreach ($c['notes'] as $n) echo '<div class="note note--inline"><span class="note__tag">Verificar</span> ' . $e($n) . '</div>';
                        echo '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody>';
                if ($b['totals']) {
                    echo '<tfoot><tr>';
                    foreach ($b['totals'] as $i => $t) {
                        if ($i === 0) {
                            echo '<td class="num">' . ($t === null ? 'Total' : '<span class="total-label">Total</span> ' . $e($t)) . '</td>';
                        } else {
                            echo '<td class="num">' . ($t === null ? '' : $e($t)) . '</td>';
                        }
                    }
                    echo '</tr></tfoot>';
                }
                echo '</table>';
                break;

            case 'cards':
                if ($depth > 0 && $b['title'] !== null) echo '<div class="subhead">' . $e($b['title']) . ' <span class="count">(' . (int) $b['count'] . ')</span></div>';
                foreach ($b['items'] as $card) {
                    echo '<div class="card card--d' . min($depth, 2) . '">';
                    echo '<table class="card__head"><tr><td class="card__title">' . $e($card['title']) . '</td>';
                    echo '<td class="card__badge-cell">' . ($card['badge'] ? '<span class="badge">' . $e($card['badge']) . '</span>' : '') . '</td></tr></table>';
                    echo '<div class="card__body">';
                    $renderBlocks($card['blocks'], $depth + 1);
                    echo '</div></div>';
                }
                break;
        }
    }
};
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= $e($doc['module']) ?> · <?= $e($doc['reference']) ?></title>
<style>
    @page { margin: 136px 42px 56px 42px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; color: #1f2937; line-height: 1.35; }

    /* Fixed header repeated on every page */
    .header { position: fixed; top: -114px; left: 0; right: 0; height: 100px; }
    .header table { width: 100%; border-collapse: collapse; }
    .header td { vertical-align: top; padding: 0; }
    .brand { font-size: 19pt; font-weight: bold; letter-spacing: -0.5pt; color: #111827; line-height: 1; }
    .brand span { color: #2563eb; }
    .brand-sub { font-size: 7.2pt; color: #6b7280; margin-top: 4px; letter-spacing: 0.6pt; text-transform: uppercase; }
    .doc-meta { text-align: right; }
    .doc-title { font-size: 11.5pt; font-weight: bold; color: #111827; }
    .doc-ref { font-size: 8.6pt; color: #374151; margin-top: 2px; }
    .doc-date { font-size: 7.4pt; color: #6b7280; margin-top: 2px; }
    .header-rule { height: 3px; background: #2563eb; margin-top: 10px; }
    .header-rule-thin { height: 1px; background: #dbe3f0; }
    .notaria { font-size: 7.6pt; color: #4b5563; margin-top: 6px; }

    /* Sections */
    .section { margin-bottom: 14px; }
    .section__title { page-break-after: avoid; background: #f1f5fb; border-left: 3px solid #2563eb; padding: 6px 9px; font-size: 9.4pt; font-weight: bold;
        color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5pt; }
    .section__subtitle { font-size: 7.6pt; color: #6b7280; padding: 3px 0 0 12px; }
    .section__body { padding: 6px 0 0 0; }

    /* Label/value grid */
    table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.grid tr { page-break-inside: avoid; }
    td.cell { width: 33.33%; vertical-align: top; padding: 4px 8px 6px 0; }
    .cell__label { font-size: 6.6pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4pt; margin-bottom: 1px; }
    .cell__value { font-size: 8.8pt; color: #111827; word-wrap: break-word; }
    .cell__value--empty { color: #9ca3af; }
    .missing { background: #fee2e2; color: #b91c1c; font-size: 7pt; font-weight: bold; padding: 1px 5px; border-radius: 3px; }
    .muted { color: #9ca3af; }

    /* Resolver notes */
    .note { font-size: 6.8pt; color: #1d4ed8; margin-top: 2px; }
    .note--inline { margin-top: 1px; }
    .note__tag { font-weight: bold; text-transform: uppercase; font-size: 6pt; letter-spacing: 0.4pt; background: #dbeafe; padding: 0 3px; border-radius: 2px; }

    /* Objects */
    .group { margin: 4px 0 6px 0; }
    .group__title { font-size: 7.8pt; font-weight: bold; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 2px; margin-bottom: 2px; }

    /* Cards (array rows) */
    .subhead { font-size: 8.2pt; font-weight: bold; color: #1f2937; margin: 8px 0 4px 0; page-break-after: avoid; }
    .count { color: #6b7280; font-weight: normal; }
    .card { border: 1px solid #e3e8ef; border-radius: 4px; margin: 0 0 8px 0; }
    .card--d0 { border-color: #cfd8e6; }
    .card__head { width: 100%; border-collapse: collapse; background: #f8fafc; border-bottom: 1px solid #e3e8ef; }
    .card--d0 > .card__head { background: #eef3fb; }
    .card__title { padding: 5px 8px; font-weight: bold; font-size: 8.6pt; color: #111827; }
    .card__badge-cell { padding: 5px 8px; text-align: right; width: 34%; }
    .badge { font-size: 6.8pt; color: #1e40af; background: #dbeafe; padding: 1px 6px; border-radius: 8px; }
    .card__body { padding: 4px 8px 4px 8px; }

    /* Data tables */
    table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.data th { font-size: 6.6pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #4b5563; text-align: left;
        background: #f3f4f6; padding: 4px 6px; border-bottom: 1px solid #d1d5db; }
    table.data td { padding: 4px 6px; border-bottom: 1px solid #eef0f3; font-size: 8.4pt; vertical-align: top; }
    table.data tbody tr:nth-child(even) td { background: #fafbfc; }
    table.data tr { page-break-inside: avoid; }
    table.data .num { text-align: right; }
    table.data tfoot td { font-weight: bold; border-top: 1.5px solid #9ca3af; border-bottom: none; background: #ffffff; }
    .total-label { font-size: 6.6pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4pt; margin-right: 4px; }
    .empty { font-size: 8pt; color: #9ca3af; font-style: italic; padding: 2px 0 6px 0; }

    .disclaimer { font-size: 6.8pt; color: #9ca3af; margin-top: 10px; border-top: 1px solid #e5e7eb; padding-top: 5px; }
</style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td>
                <div class="brand">Notar<span>IA</span></div>
                <div class="brand-sub">Vista previa de revisión</div>
            </td>
            <td class="doc-meta">
                <div class="doc-title"><?= $e($doc['module']) ?></div>
                <div class="doc-ref">Referencia: <strong><?= $e($doc['reference']) ?></strong></div>
                <div class="doc-date">Generado: <?= $e($doc['generated_at']) ?><?php if (!empty($doc['status'])): ?> · <?= $e($doc['status']) ?><?php endif; ?></div>
            </td>
        </tr>
    </table>
    <div class="header-rule"></div>
    <?php if (!empty($doc['notaria'])): ?>
        <div class="notaria"><?= $e($doc['notaria']) ?></div>
    <?php endif; ?>
</div>

<?php foreach ($sections as $section): ?>
    <div class="section">
        <div class="section__title"><?= $e($section['title']) ?></div>
        <?php if (!empty($section['subtitle'])): ?><div class="section__subtitle"><?= $e($section['subtitle']) ?></div><?php endif; ?>
        <div class="section__body"><?php $renderBlocks($section['blocks']); ?></div>
    </div>
<?php endforeach; ?>

<div class="disclaimer">
    Documento de revisión generado por NotarIA a partir de los datos capturados. No sustituye al archivo oficial presentado ante el SAT / UIF.
    <?php if (!empty($doc['draft_saved_at'])): ?> Datos al <?= $e($doc['draft_saved_at']) ?>.<?php endif; ?>
</div>

</body>
</html>
