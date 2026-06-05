<?php

namespace Blesta\Helpers\Color;

use Blesta\Core\Util\Helpers\Helper;
use Illuminate\Support\Str;

/**
 * Color helper
 *
 * Create color contrasts and convert color values to various formats
 *
 * @package blesta
 * @subpackage helpers.color
 */
class Color extends Helper
{
    /**
     * @var array A representation of the current color
     */
    private $color = [0,0,0];

    /**
     * Sets the current color to the given hex or HTML hex value
     *
     * @param string $color The given color in hex or HTMl hex value
     * @return Color $this
     */
    public function hex($color)
    {
        $this->color = $this->toRgb($color);
        return $this;
    }

    /**
     * Sets the current color to the given RGB value
     *
     * @param array $color The given color in a numerically indexed array where
     *  index 0 is red, index 1 is green, index 2 is blue
     * @return Color $this
     */
    public function rgb(array $color)
    {
        $this->color = $color;
        return $this;
    }

    /**
     * Finds the constrast of the current color using the 50/50 method then sets
     * it as the internal color
     *
     * @return Color $this
     */
    public function contrast50()
    {

        if (empty($this->color)) {
            return $this;
        }

        // Convert color to hex
        $hex = $this->asHex();

        // Find contrast color, set as new color
        return $this->hex((hexdec($hex) > 0xffffff / 2) ? '000' : 'fff');
    }

    /**
     * Finds the constrast of the current color in YIQ space then sets it as the
     * internal color
     *
     * @return Color $this
     */
    public function contrastYiq()
    {
        if (empty($this->color)) {
            return $this;
        }

        // Convert color to yiq
        $yiq = (($this->color[0] * 299) + ($this->color[1] * 587) + ($this->color[2] * 114)) / 1000;

        // Find constrast color, set as new color
        return $this->hex(($yiq >= 128) ? '000' : 'fff');
    }

    /**
     * Convert the internal color to HTML hex color
     *
     * @return string The HTML hex color for the current color
     */
    public function asHtml()
    {
        if (empty($this->color)) {
            return null;
        }

        return '#' . $this->asHex();
    }

    /**
     * Convert the internal color to hex
     *
     * @return string The hex color for the current color
     */
    public function asHex()
    {
        if (empty($this->color)) {
            return null;
        }

        return sprintf('%02x%02x%02x', $this->color[0], $this->color[1], $this->color[2]);
    }

    /**
     * Convert the internal color to an rgb array
     *
     * @return array The rgb color for the current color
     */
    public function asRgb()
    {
        if (empty($this->color)) {
            return null;
        }

        return $this->color;
    }

    /**
     * Sets the current color from various formats (hex, RGB string, or RGB array)
     *
     * @param mixed $color The color in hex format, RGB string format, or RGB array
     * @return Color $this
     */
    public function from($color)
    {
        $this->color = [0,0,0];

        if (is_array($color)) {
            if (count($color) === 3) {
                // Check if each value is an integer between 0 and 255
                foreach ($color as $value) {
                    if (!is_int($value) || $value < 0 || $value > 255) {
                        return $this;
                    }
                }

                $this->rgb($color);
            }
        }

        if (is_string($color) && $this->isValidColor($color)) {
            $color = ltrim($color, '#');

            if (ctype_alnum($color) && strlen($color) === 6) {
                $this->hex($color);

                return $this;
            }

            if (str_contains($color, ',')) {
                $color = Str::of($color)->replaceStart('rgb', '')
                    ->trim()
                    ->replaceStart('(', '')
                    ->trim()
                    ->replaceEnd(')', '')
                    ->trim()
                    ->replace(' ', '')
                    ->explode(',', 3)
                    ->toArray();
                $this->rgb($color);
            }
        }

        return $this;
    }

    /**
     * Validates if a string or array is a valid color in hex or RGB format
     *
     * @param mixed $color The color string or array to validate
     * @return bool True if the color is valid, false otherwise
     */
    public function isValidColor($color)
    {
        if (empty($color)) {
            return false;
        }

        // Check if it's an array first
        if (is_array($color)) {
            // Check if it has exactly 3 values
            if (count($color) === 3) {
                // Check if each value is an integer between 0 and 255
                foreach ($color as $value) {
                    if (!is_int($value) || $value < 0 || $value > 255) {
                        return false;
                    }
                }
                return true;
            }

            return false;
        }

        // If not an array, it must be a string
        if (!is_string($color)) {
            return false;
        }

        $color = trim($color);

        // Check hex format (#000000 or 000000)
        if (preg_match('/^#?([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $color)) {
            return true;
        }

        // Check RGB format: "0,0,0", "0, 0, 0", "rgb(0,0,0)", or "rgb(0, 0, 0)"
        if (preg_match('/^(rgb\()?(\s*\d{1,3}\s*)(,\s*\d{1,3}\s*)(,\s*\d{1,3}\s*)\)?$/i', $color, $matches)) {
            // Extract the numbers from the matches
            $numbers = [];
            for ($i = 2; $i <= 4; $i++) {
                if (isset($matches[$i])) {
                    $numbers[] = (int) trim($matches[$i]);
                }
            }

            // Check if we have exactly 3 numbers and each is between 0 and 255
            if (count($numbers) === 3) {
                foreach ($numbers as $num) {
                    if ($num < 0 || $num > 255) {
                        return false;
                    }
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a color from one format to rgb
     *
     * @param mixed $color The color to make rgb
     * @param string $from The format to convert from
     * @return array An rgb array
     */
    private function toRgb($color, $from = 'hex')
    {
        $rgb = [];
        switch ($from) {
            case 'hex':
                $color = trim($color, '#');
                $length = strlen($color);

                if ($length == 6) {
                    $hex = str_split($color, 2);
                } else {
                    $hex = str_split($color);
                    foreach ($hex as &$val) {
                        $val .= $val;
                    }
                }

                if (count($hex) == 3) {
                    $rgb = [hexdec($hex[0]), hexdec($hex[1]), hexdec($hex[2])];
                }
                break;
            case 'rgb':
                $rgb = $color;
                break;
        }

        return $rgb;
    }
}
