<?php
namespace Statusboard;

/**
 * A QR code as SVG, for a short text such as a Remote Control link: byte mode, error correction M, versions 1 to 10.
 * Written here so the board needs no outside library (a script from a CDN would run with the board's own rights).
 * Follows the standard construction (ISO/IEC 18004), the same steps as Project Nayuki's reference encoder.
 */
final class Qr {
    // Index = version (1-10), error correction level M.
    private const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const FORMAT_M = 0;

    private int $size;
    private array $dark = [];
    private array $fixed = [];

    private function __construct(private int $version) {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->dark = array_fill(0, $this->size, $row);
        $this->fixed = array_fill(0, $this->size, $row);
    }

    public static function svg(string $text, int $border = 4): string {
        $q = self::encode($text);
        $n = $q->size + 2 * $border;
        $path = '';
        for ($y = 0; $y < $q->size; $y++) {
            for ($x = 0; $x < $q->size; $x++) if ($q->dark[$y][$x]) $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
        }
        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 $n $n\" shape-rendering=\"crispEdges\"><rect width=\"$n\" height=\"$n\" fill=\"#fff\"/><path d=\"$path\" fill=\"#000\"/></svg>";
    }

    private static function encode(string $text): self {
        $len = strlen($text);
        for ($v = 1; $v <= 10; $v++) {
            $capacity = self::dataCodewords($v) * 8;
            $countBits = $v <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * $len <= $capacity) break;
        }
        if ($v > 10) throw new \RuntimeException('text too long for a QR code here');
        // Byte mode: mode 0100, the length, the bytes; then the terminator, byte alignment and the pad bytes.
        $bits = [];
        $put = function (int $val, int $n) use (&$bits) { for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $put(4, 4);
        $put($len, $countBits);
        for ($i = 0; $i < $len; $i++) $put(ord($text[$i]), 8);
        $put(0, min(4, $capacity - count($bits)));
        $put(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) $put($pad, 8);
        $data = [];
        foreach (array_chunk($bits, 8) as $byte) $data[] = (int)bindec(implode('', $byte));

        $q = new self($v);
        $q->functionPatterns();
        $q->codewords($q->withEcc($data));
        // The mask that keeps dark and light closest to half and half: any mask decodes, this one scans most easily.
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $q->mask($m);
            $q->formatBits($m);
            $score = $q->penalty();
            if ($score < $bestScore) [$best, $bestScore] = [$m, $score];
            $q->mask($m);
        }
        $q->mask($best);
        $q->formatBits($best);
        return $q;
    }

    private static function rawModules(int $v): int {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $align = intdiv($v, 7) + 2;
            $r -= (25 * $align - 10) * $align - 55;
            if ($v >= 7) $r -= 36;
        }
        return $r;
    }

    private static function dataCodewords(int $v): int {
        return intdiv(self::rawModules($v), 8) - self::ECC_PER_BLOCK[$v] * self::BLOCKS[$v];
    }

    private function set(int $x, int $y, bool $dark): void {
        $this->dark[$y][$x] = $dark;
        $this->fixed[$y][$x] = true;
    }

    private function functionPatterns(): void {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy));
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) $this->set($x, $y, $d !== 2 && $d !== 4);
            }
        }
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
        }
        $this->formatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = $this->version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function alignmentPositions(): array {
        if ($this->version === 1) return [];
        $n = intdiv($this->version, 7) + 2;
        $step = (int)ceil(($this->version * 4 + 4) / ($n * 2 - 2)) * 2;
        $r = [6];
        for ($p = $this->size - 7; count($r) < $n; $p -= $step) array_splice($r, 1, 0, [$p]);
        return $r;
    }

    private function formatBits(int $mask): void {
        $data = self::FORMAT_M << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $bit($i));
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $this->set($s - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $s - 15 + $i, $bit($i));
        $this->set(8, $s - 8, true);
    }

    /** Splits the data into blocks, adds each block's Reed-Solomon codewords and interleaves them. */
    private function withEcc(array $data): array {
        $blocks = self::BLOCKS[$this->version];
        $eccLen = self::ECC_PER_BLOCK[$this->version];
        $raw = intdiv(self::rawModules($this->version), 8);
        $short = $blocks - $raw % $blocks;
        $shortLen = intdiv($raw, $blocks);
        $divisor = self::rsDivisor($eccLen);
        $all = [];
        for ($i = 0, $k = 0; $i < $blocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $short ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $short) $dat[] = 0;
            $all[] = array_merge($dat, $ecc);
        }
        $out = [];
        for ($i = 0; $i < count($all[0]); $i++) {
            foreach ($all as $j => $b) if ($i !== $shortLen - $eccLen || $j >= $short) $out[] = $b[$i];
        }
        return $out;
    }

    private static function gfMul(int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) $r[$j] ^= $r[$j + 1];
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $divisor): array {
        $r = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($divisor as $i => $d) $r[$i] ^= self::gfMul($d, $factor);
        }
        return $r;
    }

    /** The zigzag placement of the codewords over every module that is not a fixed pattern. */
    private function codewords(array $data): void {
        $s = $this->size;
        $i = 0;
        $total = count($data) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $up = (($right + 1) & 2) === 0;
                    $y = $up ? $s - 1 - $vert : $vert;
                    if (!$this->fixed[$y][$x] && $i < $total) {
                        $this->dark[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    /** XOR with mask pattern $m on the data modules. Applying it twice undoes it. */
    private function mask(int $m): void {
        for ($y = 0; $y < $this->size; $y++) for ($x = 0; $x < $this->size; $x++) {
            if ($this->fixed[$y][$x]) continue;
            $flip = match ($m) {
                0 => ($x + $y) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($x + $y) % 3 === 0,
                4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => $x * $y % 2 + $x * $y % 3 === 0,
                6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
            };
            if ($flip) $this->dark[$y][$x] = !$this->dark[$y][$x];
        }
    }

    /** The standard's rules 1 (runs of five or more) and 4 (dark share away from 50%), enough to pick a good mask. */
    private function penalty(): int {
        $s = $this->size;
        $score = 0;
        $darkCount = 0;
        for ($a = 0; $a < $s; $a++) {
            foreach ([true, false] as $rows) {
                $run = 0;
                $prev = null;
                for ($b = 0; $b < $s; $b++) {
                    $c = $rows ? $this->dark[$a][$b] : $this->dark[$b][$a];
                    if ($c === $prev) {
                        $run++;
                        if ($run === 5) $score += 3;
                        elseif ($run > 5) $score++;
                    } else {
                        $run = 1;
                        $prev = $c;
                    }
                }
            }
            foreach ($this->dark[$a] as $c) if ($c) $darkCount++;
        }
        $total = $s * $s;
        return $score + intdiv(abs($darkCount * 20 - $total * 10) + $total - 1, $total) * 10;
    }
}
