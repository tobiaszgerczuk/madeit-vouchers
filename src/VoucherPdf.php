<?php

namespace Madeit\Vouchers;

use RuntimeException;

defined('ABSPATH') || exit;

final class VoucherPdf
{
    /**
     * Renders the voucher as a one-page PDF and returns the path of a temporary file.
     *
     * @param  array<string, string>  $content
     */
    public function create(string $template, string $color, string $code, string $amount, string $expires, string $message, string $storeName, string $brandColor = Settings::DEFAULT_BRAND_COLOR, array $content = []): string
    {
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            throw new RuntimeException(esc_html__('The server needs the GD extension with TrueType font support.', 'madeit-vouchers'));
        }

        $design = Settings::sanitizeDesign($template, $color, $brandColor);
        $template = $design['template'];
        $content = array_merge(Settings::content(), $content);
        $image = imagecreatetruecolor(1600, 1000);
        if (! $image) {
            throw new RuntimeException(esc_html__('Could not create the voucher image.', 'madeit-vouchers'));
        }

        try {
            $accent = $this->rgb($image, Settings::accent($design));
            $ink = imagecolorallocate($image, 31, 47, 67);
            $muted = imagecolorallocate($image, 107, 119, 131);
            $white = imagecolorallocate($image, 255, 255, 255);
            $light = imagecolorallocate($image, 248, 246, 240);
            $font = dirname(__DIR__).'/assets/fonts/DejaVuSans.ttf';
            if (! is_readable($font)) {
                throw new RuntimeException(esc_html__('The PDF font is missing.', 'madeit-vouchers'));
            }

            /**
             * Lets an add-on draw its own template. Return null to use the built-in classic layout.
             *
             * @param  array{accent: int, ink: int, muted: int, white: int, light: int}  $palette
             * @return array{foreground: int, secondary: int, left: int, name: int, messageLeft: int, message: int}|null
             */
            $layout = apply_filters('madeit_vouchers_pdf_layout', null, $template, $image, compact('accent', 'ink', 'muted', 'white', 'light'));
            if (! is_array($layout)) {
                imagefilledrectangle($image, 0, 0, 1599, 999, $light);
                imagerectangle($image, 45, 45, 1554, 954, $accent);
                imagerectangle($image, 59, 59, 1540, 940, $accent);
                imagefilledrectangle($image, 94, 92, 260, 99, $accent);
                imagefilledrectangle($image, 1340, 900, 1506, 907, $accent);
                $layout = ['foreground' => $ink, 'secondary' => $muted, 'left' => 120, 'name' => $ink, 'messageLeft' => 950, 'message' => $ink];
            }
            $foreground = $layout['foreground'];
            $secondary = $layout['secondary'];
            $left = $layout['left'];

            $this->write($image, $font, 27, $left, 150, mb_strtoupper($storeName), $layout['name']);
            $this->write($image, $font, 92, $left, 330, mb_strtoupper(wp_strip_all_tags($content['pdf_title'])), $foreground);
            $this->write($image, $font, 31, $left + 4, 390, wp_strip_all_tags($content['pdf_subtitle']), $secondary);
            imagefilledrectangle($image, $left, 438, $left + 142, 445, $accent);
            $this->write($image, $font, 26, $left, 515, mb_strtoupper(wp_strip_all_tags($content['pdf_amount_label'])), $secondary);
            $this->write($image, $font, 74, $left, 605, $amount, $foreground);
            $this->write($image, $font, 24, $left, 710, mb_strtoupper(wp_strip_all_tags($content['pdf_code_label'])), $secondary);
            $this->write($image, $font, 45, $left, 777, $code, $foreground);
            $this->write($image, $font, 22, $left, 855, str_replace('{expires}', $expires, wp_strip_all_tags($content['pdf_valid_until'])), $secondary);

            if ($message !== '') {
                $message = mb_substr(trim(wp_strip_all_tags($message)), 0, 120);
                $messageTop = 675;
                foreach (array_slice($this->wrap($font, 23, $message, 520), 0, 3) as $line) {
                    $this->write($image, $font, 23, $layout['messageLeft'], $messageTop, $line, $layout['message']);
                    $messageTop += 38;
                }
            }

            ob_start();
            imagejpeg($image, null, 88);
            $jpeg = ob_get_clean();
            if (! is_string($jpeg) || $jpeg === '') {
                throw new RuntimeException(esc_html__('Could not prepare the PDF graphic.', 'madeit-vouchers'));
            }

            return $this->save($this->pdf($jpeg));
        } finally {
            imagedestroy($image);
        }
    }

    private function save(string $pdf): string
    {
        global $wp_filesystem;
        require_once ABSPATH.'wp-admin/includes/file.php';
        WP_Filesystem();
        $temporary = wp_tempnam('madeit-voucher');
        $path = $temporary ? $temporary.'.pdf' : '';
        if (! $temporary || ! $wp_filesystem || ! $wp_filesystem->move($temporary, $path, true) || ! $wp_filesystem->put_contents($path, $pdf)) {
            throw new RuntimeException(esc_html__('Could not save the voucher PDF.', 'madeit-vouchers'));
        }

        return $path;
    }

    private function rgb($image, string $hex): int
    {
        return imagecolorallocate($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    }

    private function write($image, string $font, int $size, int $x, int $y, string $text, int $color): void
    {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
    }

    private function wrap(string $font, int $size, string $text, int $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = trim($current.' '.$word);
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($current !== '' && $box && $box[2] - $box[0] > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function pdf(string $jpeg): string
    {
        $content = "q 800 0 0 500 0 0 cm /Im0 Do Q\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 800 500] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /XObject /Subtype /Image /Width 1600 /Height 1000 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream",
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
