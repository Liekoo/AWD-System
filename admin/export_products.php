<?php
require '../config.php';

$format = $_GET['format'] ?? 'csv';
$result = $conn->query("SELECT Product_ID, Product_Name, Product_Price, Product_Quantity_Stock,
    Product_Status, Product_Description, Product_Image FROM products ORDER BY Product_ID DESC");
$rows  = [];
while ($r = $result->fetch_assoc()) $rows[] = $r;
$count = count($rows);
$date  = date('Y-m-d');
$base  = 'aqualuxe_products_' . $date;

// ── CSV ───────────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$base.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product_ID','Product_Name','Product_Price','Product_Quantity_Stock',
                   'Product_Status','Product_Description','Product_Image']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

// ── Blank Import Template ─────────────────────────────────────────────────────
if ($format === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="aqualuxe_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product_ID','Product_Name','Product_Price','Product_Quantity_Stock',
                   'Product_Status','Product_Description','Product_Image']);
    fputcsv($out, ['','Sample Product','25.00','100','Active','Description here','']);
    fclose($out);
    exit;
}

// ── Excel (XLS) ───────────────────────────────────────────────────────────────
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$base.'.xls"');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body{font-family:Arial,sans-serif;font-size:12px}
    h2{color:#001a4d;margin-bottom:4px}
    p{color:#5e8ab4;font-size:11px;margin-bottom:12px}
    table{border-collapse:collapse;width:100%}
    th{background:#003d8f;color:#fff;padding:8px 12px;text-align:left;font-size:11px;letter-spacing:0.4px}
    td{border:1px solid #b3d4f0;padding:7px 12px;vertical-align:top}
    tr:nth-child(even) td{background:#e6f4ff}
    .active{color:#009e77;font-weight:bold}
    .inactive{color:#c04a00;font-weight:bold}
    .oos{color:#c08000;font-weight:bold}
    </style></head><body>';
    echo '<h2>AquaLuxe — Products Export</h2>';
    echo '<p>Generated: '.date('F d, Y H:i').' &nbsp;|&nbsp; '.$count.' product'.($count!=1?'s':'').'</p>';
    echo '<table><thead><tr>
        <th>#ID</th><th>Product Name</th><th>Base Price</th>
        <th>Stock</th><th>Status</th><th>Description</th><th>Image Path</th>
    </tr></thead><tbody>';
    foreach ($rows as $r) {
        $cls = match($r['Product_Status']) {
            'Active'       => 'active',
            'Inactive'     => 'inactive',
            'Out of Stock' => 'oos',
            default        => ''
        };
        echo '<tr>
            <td>#'.$r['Product_ID'].'</td>
            <td>'.htmlspecialchars($r['Product_Name']).'</td>
            <td>&#8369;'.number_format($r['Product_Price'],2).'</td>
            <td>'.$r['Product_Quantity_Stock'].'</td>
            <td class="'.$cls.'">'.htmlspecialchars($r['Product_Status']).'</td>
            <td>'.htmlspecialchars($r['Product_Description'] ?? '').'</td>
            <td>'.htmlspecialchars($r['Product_Image'] ?? '').'</td>
        </tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// ── SVG ───────────────────────────────────────────────────────────────────────
if ($format === 'svg') {
    $cols = [
        ['#ID',          55,  'Product_ID',            'middle'],
        ['Product Name', 210, 'Product_Name',           'start'],
        ['Price',        88,  'Product_Price',          'middle'],
        ['Stock',        60,  'Product_Quantity_Stock', 'middle'],
        ['Status',       108, 'Product_Status',         'middle'],
        ['Description',  305, 'Product_Description',    'start'],
    ];

    $svgW    = 890;
    $padL    = 24;
    $rowH    = 38;
    $headerH = 78;
    $tblHdrH = 36;
    $tableY  = $headerH + 14;
    $bodyY   = $tableY + $tblHdrH;
    $totalH  = $bodyY + ($count * $rowH) + 48;

    $xPos = []; $x = $padL;
    foreach ($cols as $c) { $xPos[] = $x; $x += $c[1]; }

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$base.'.svg"');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$svgW.'" height="'.$totalH.'"
               viewBox="0 0 '.$svgW.' '.$totalH.'">';

    // Background
    echo '<rect width="'.$svgW.'" height="'.$totalH.'" fill="#eef7ff"/>';

    // Branding bar
    echo '<rect width="'.$svgW.'" height="'.$headerH.'" fill="#001a4d"/>';
    echo '<text x="'.$padL.'" y="36" font-family="Georgia,serif" font-size="21" font-weight="bold" fill="#e6f4ff">';
    echo 'Aqua<tspan fill="#0070ff">Luxe</tspan></text>';
    echo '<text x="'.$padL.'" y="56" font-family="Courier New,monospace" font-size="11" fill="rgba(168,212,245,0.5)">';
    echo 'Products Export &#8212; Generated '.date('F d, Y').'</text>';

    // Count pill
    $px = $svgW - $padL - 108;
    echo '<rect x="'.$px.'" y="20" width="108" height="24" rx="12" fill="rgba(0,112,255,0.18)" stroke="rgba(0,177,255,0.3)" stroke-width="1"/>';
    echo '<text x="'.($px+54).'" y="36" text-anchor="middle" font-family="Courier New,monospace" font-size="11" font-weight="bold" fill="#00b1ff">';
    echo $count.' product'.($count!=1?'s':'').'</text>';

    // Table header
    echo '<rect x="0" y="'.$tableY.'" width="'.$svgW.'" height="'.$tblHdrH.'" fill="#003d8f"/>';
    foreach ($cols as $i => [$label,$w,$key,$align]) {
        $tx = $align === 'middle' ? $xPos[$i] + $w/2 : $xPos[$i] + 8;
        $ta = $align === 'middle' ? 'middle' : 'start';
        echo '<text x="'.$tx.'" y="'.($tableY+23).'" text-anchor="'.$ta.'"
               font-family="Arial,sans-serif" font-size="11" font-weight="bold"
               letter-spacing="0.4" fill="#ffffff">'.$label.'</text>';
    }

    // Rows
    foreach ($rows as $ri => $r) {
        $ry  = $bodyY + $ri * $rowH;
        $bg  = $ri % 2 === 0 ? '#ffffff' : '#e6f4ff';
        $ty  = $ry + 24;

        echo '<rect x="0" y="'.$ry.'" width="'.$svgW.'" height="'.$rowH.'" fill="'.$bg.'"/>';
        echo '<line x1="0" y1="'.($ry+$rowH).'" x2="'.$svgW.'" y2="'.($ry+$rowH).'" stroke="#b3d4f0" stroke-width="0.5"/>';

        foreach ($cols as $i => [$label,$w,$key,$align]) {
            $val = (string)($r[$key] ?? '');
            $tx  = $align === 'middle' ? $xPos[$i] + $w/2 : $xPos[$i] + 8;
            $ta  = $align === 'middle' ? 'middle' : 'start';

            if ($key === 'Product_ID')    $val = '#'.$val;
            if ($key === 'Product_Price') $val = 'PHP '.number_format((float)$val,2);
            if ($key === 'Product_Description' && mb_strlen($val) > 52)
                $val = mb_substr($val,0,49).'...';

            if ($key === 'Product_Status') {
                [$fg,$bg2,$stroke] = match($val) {
                    'Active'       => ['#007a5e','#e6fff6','#009e77'],
                    'Inactive'     => ['#a03000','#fff0eb','#c04a00'],
                    default        => ['#8a5c00','#fff8e0','#c08000'],
                };
                $bw=82; $bh=20;
                $bx=$xPos[$i]+($w-$bw)/2; $by=$ry+($rowH-$bh)/2;
                echo '<rect x="'.$bx.'" y="'.$by.'" width="'.$bw.'" height="'.$bh.'" rx="10"
                           fill="'.$bg2.'" stroke="'.$stroke.'" stroke-width="1"/>';
                echo '<text x="'.($bx+$bw/2).'" y="'.($by+14).'" text-anchor="middle"
                       font-family="Arial,sans-serif" font-size="10" font-weight="bold"
                       fill="'.$fg.'">'.htmlspecialchars($val).'</text>';
                continue;
            }

            $fill = '#051c3a';
            if ($key === 'Product_Quantity_Stock' && (int)$val <= 5) $fill = '#c04a00';

            echo '<text x="'.$tx.'" y="'.$ty.'" text-anchor="'.$ta.'"
                   font-family="Arial,sans-serif" font-size="12" fill="'.$fill.'">'.htmlspecialchars($val).'</text>';
        }

        // Column dividers
        for ($i = 1; $i < count($cols); $i++) {
            echo '<line x1="'.$xPos[$i].'" y1="'.$ry.'" x2="'.$xPos[$i].'" y2="'.($ry+$rowH).'" stroke="#b3d4f0" stroke-width="0.5"/>';
        }
    }

    // Footer
    $fy = $bodyY + $count * $rowH + 18;
    echo '<text x="'.$padL.'" y="'.$fy.'" font-family="Courier New,monospace" font-size="10" fill="#5e8ab4">';
    echo 'AquaLuxe Water Refilling System &#183; '.date('Y').' &#183; Admin Export</text>';

    echo '</svg>';
    exit;
}