<?php

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Minimal PDF 1.4 writer using built-in Helvetica.
 * Meeting-usable letter pages: title, key/value lines, and a simple table.
 */
class TemperSimplePdf
{
    private float $pageW = 612.0;
    private float $pageH = 792.0;
    private float $marginL = 50.0;
    private float $marginR = 50.0;
    private float $marginT = 48.0;
    private float $marginB = 42.0;
    /** @var list<string> */
    private array $pages = [];
    private string $cur = '';
    private float $y = 0.0;
    private string $docTitle = 'Document';
    private string $runningHeader = '';
    /** @var array{0:list<string>,1:list<float>,2:list<string>,3:float}|null */
    private ?array $tableHead = null;

    public function __construct()
    {
        $this->startPage();
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if ($title !== '') {
            $this->docTitle = $title;
        }
    }

    public function setRunningHeader(string $text): void
    {
        $this->runningHeader = trim($text);
    }

    public function usableWidth(): float
    {
        return $this->pageW - $this->marginL - $this->marginR;
    }

    public function space(float $pts): void
    {
        $this->ensureSpace($pts);
        $this->y -= $pts;
    }

    public function rule(): void
    {
        $this->ensureSpace(10);
        $y = $this->y;
        $this->cur .= sprintf(
            "q 0.55 0.55 0.55 RG 0.6 w %.2f %.2f m %.2f %.2f l S Q\n",
            $this->marginL,
            $y,
            $this->pageW - $this->marginR,
            $y
        );
        $this->y -= 8;
    }

    public function text(string $text, float $size = 10.0, bool $bold = false, ?float $maxWidth = null): void
    {
        $maxWidth = $maxWidth ?? $this->usableWidth();
        $lines = $this->wrap($text, $size, $bold, $maxWidth);
        $leading = $size + 3.0;
        foreach ($lines as $line) {
            $this->ensureSpace($leading);
            $this->drawText($this->marginL, $this->y, $line, $size, $bold);
            $this->y -= $leading;
        }
    }

    public function kv(string $label, string $value, float $size = 10.0): void
    {
        $label = rtrim($label, ':');
        $this->text($label . ': ' . $value, $size, false);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<float> $widths  column widths in points
     * @param list<'left'|'right'> $align
     */
    public function table(array $headers, array $rows, array $widths, array $align, float $size = 9.0): void
    {
        $this->tableHead = [$headers, $widths, $align, $size];
        $this->drawTableHeader($headers, $widths, $align, $size);
        foreach ($rows as $row) {
            $this->drawTableRow($row, $widths, $align, $size, false);
        }
    }

    /**
     * @param list<string> $cells
     * @param list<float> $widths
     * @param list<'left'|'right'> $align
     */
    public function tableFooterRow(array $cells, array $widths, array $align, float $size = 9.0): void
    {
        $this->drawTableRow($cells, $widths, $align, $size, true);
        $this->tableHead = null;
    }

    public function output(): string
    {
        $this->flushPage();
        $n = count($this->pages);
        if ($n === 0) {
            $this->startPage();
            $this->flushPage();
            $n = 1;
        }

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $pageObj = 3 + ($i * 2);
            $kids[] = $pageObj . ' 0 R';
        }
        $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';

        $fontRegular = 3 + ($n * 2);
        $fontBold = $fontRegular + 1;

        for ($i = 0; $i < $n; $i++) {
            $pageObj = 3 + ($i * 2);
            $contentObj = $pageObj + 1;
            $stream = $this->pages[$i] . $this->pageFooter($i + 1, $n);
            $objects[$pageObj - 1] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $this->pageW,
                $this->pageH,
                $fontRegular,
                $fontBold,
                $contentObj
            );
            $objects[$contentObj - 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[$fontRegular - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBold - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $out = "%PDF-1.4\n";
        $offsets = [0];
        $count = count($objects);
        for ($i = 1; $i <= $count; $i++) {
            $offsets[$i] = strlen($out);
            $out .= $i . " 0 obj\n" . $objects[$i - 1] . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($count + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $title = $this->pdfEscape($this->toWinAnsi($this->docTitle));
        $out .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R /Info << /Title ({$title}) /Producer (Temper) >> >>\n";
        $out .= "startxref\n{$xref}\n%%EOF\n";
        return $out;
    }

    private function startPage(): void
    {
        $this->cur = "0.12 0.12 0.12 rg\n0.12 0.12 0.12 RG\n";
        $this->y = $this->pageH - $this->marginT;
        if ($this->runningHeader !== '' && $this->pages !== []) {
            $this->drawText($this->marginL, $this->y, $this->toWinAnsi($this->runningHeader), 8.0, false);
            $this->y -= 12;
            $this->rule();
        }
    }

    private function flushPage(): void
    {
        $this->pages[] = $this->cur;
        $this->cur = '';
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed >= $this->marginB + 8) {
            return;
        }
        $head = $this->tableHead;
        $this->tableHead = null;
        $this->flushPage();
        $this->startPage();
        if ($head !== null) {
            $this->drawTableHeader($head[0], $head[1], $head[2], $head[3]);
            $this->tableHead = $head;
        }
    }

    /**
     * @param list<string> $headers
     * @param list<float> $widths
     * @param list<'left'|'right'> $align
     */
    private function drawTableHeader(array $headers, array $widths, array $align, float $size): void
    {
        $this->ensureSpace($size + 14);
        $this->drawTableRow($headers, $widths, $align, $size, true);
        $y = $this->y + 4;
        $this->cur .= sprintf(
            "q 0.2 0.2 0.2 RG 0.8 w %.2f %.2f m %.2f %.2f l S Q\n",
            $this->marginL,
            $y,
            $this->pageW - $this->marginR,
            $y
        );
    }

    /**
     * @param list<string> $cells
     * @param list<float> $widths
     * @param list<'left'|'right'> $align
     */
    private function drawTableRow(array $cells, array $widths, array $align, float $size, bool $bold): void
    {
        $wrapped = [];
        $maxLines = 1;
        foreach ($cells as $i => $cell) {
            $w = $widths[$i] ?? 80.0;
            $lines = $this->wrap((string)$cell, $size, $bold, max(12.0, $w - 6));
            $wrapped[] = $lines;
            $maxLines = max($maxLines, count($lines));
        }
        $leading = $size + 2.5;
        $rowH = ($maxLines * $leading) + 4;
        $this->ensureSpace($rowH);
        // If ensureSpace started a new page, reprint the header on continuation
        // (caller reprints via running header; body rows continue).
        $x = $this->marginL;
        $top = $this->y;
        foreach ($wrapped as $i => $lines) {
            $w = $widths[$i] ?? 80.0;
            $a = $align[$i] ?? 'left';
            $lineY = $top;
            foreach ($lines as $line) {
                $tx = $x + 2;
                if ($a === 'right') {
                    $tw = $this->textWidth($line, $size, $bold);
                    $tx = $x + $w - 4 - $tw;
                    if ($tx < $x + 1) {
                        $tx = $x + 1;
                    }
                }
                $this->drawText($tx, $lineY, $line, $size, $bold);
                $lineY -= $leading;
            }
            $x += $w;
        }
        $this->y -= $rowH;
    }

    private function pageFooter(int $page, int $total): string
    {
        $label = $this->toWinAnsi('Page ' . $page . ' of ' . $total);
        $size = 8.0;
        $w = $this->textWidth($label, $size, false);
        $x = $this->pageW - $this->marginR - $w;
        $y = 24.0;
        $esc = $this->pdfEscape($label);
        return sprintf(
            "BT /F1 %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $size,
            $x,
            $y,
            $esc
        );
    }

    private function drawText(float $x, float $y, string $winAnsi, float $size, bool $bold): void
    {
        $font = $bold ? '/F2' : '/F1';
        $this->cur .= sprintf(
            "BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $y,
            $this->pdfEscape($winAnsi)
        );
    }

    /**
     * @return list<string> WinAnsi lines
     */
    private function wrap(string $text, float $size, bool $bold, float $maxWidth): array
    {
        $text = $this->toWinAnsi($text);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }
        if ($this->textWidth($text, $size, $bold) <= $maxWidth) {
            return [$text];
        }
        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($trial, $size, $bold) <= $maxWidth) {
                $current = $trial;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            if ($this->textWidth($word, $size, $bold) <= $maxWidth) {
                $current = $word;
                continue;
            }
            $chunk = '';
            $len = strlen($word);
            for ($i = 0; $i < $len; $i++) {
                $ch = $word[$i];
                if ($chunk !== '' && $this->textWidth($chunk . $ch, $size, $bold) > $maxWidth) {
                    $lines[] = $chunk;
                    $chunk = $ch;
                } else {
                    $chunk .= $ch;
                }
            }
            $current = $chunk;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines !== [] ? $lines : [''];
    }

    private function textWidth(string $winAnsi, float $size, bool $bold): float
    {
        $total = 0;
        $len = strlen($winAnsi);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($winAnsi[$i]);
            $total += $this->glyphWidth($c, $bold);
        }
        return $total * $size / 1000.0;
    }

    private function glyphWidth(int $code, bool $bold): int
    {
        static $regular = null;
        static $boldW = null;
        if ($regular === null) {
            $regular = $this->helveticaWidths(false);
            $boldW = $this->helveticaWidths(true);
        }
        $table = $bold ? $boldW : $regular;
        return $table[$code] ?? 500;
    }

    /**
     * Helvetica / Helvetica-Bold widths for WinAnsi 32–126 (1/1000 em).
     *
     * @return array<int,int>
     */
    private function helveticaWidths(bool $bold): array
    {
        $w = [];
        // Default for unused codes
        for ($i = 0; $i < 256; $i++) {
            $w[$i] = 500;
        }
        if ($bold) {
            $map = [
                32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
                40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
                48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
                56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
                64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
                72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778,
                80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
                88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556,
                96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
                104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611,
                112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778,
                120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584,
            ];
        } else {
            $map = [
                32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
                40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
                48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
                56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
                64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
                72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
                80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
                88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
                96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
                104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
                112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
                120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
            ];
        }
        foreach ($map as $k => $v) {
            $w[$k] = $v;
        }
        return $w;
    }

    private function toWinAnsi(string $utf8): string
    {
        $utf8 = str_replace(["\r\n", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $utf8);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $utf8);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $utf8) ?? $utf8;
    }

    private function pdfEscape(string $winAnsi): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $winAnsi);
    }
}
