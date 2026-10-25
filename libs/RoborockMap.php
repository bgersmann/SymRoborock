<?php

declare(strict_types=1);

/**
 * Parser and renderer for Roborock V1 maps (get_map_v1).
 *
 * Ported to PHP and modified from vacuum-map-parser-roborock by Piotr Machowski (map_data_parser.py,
 * image_parser.py), https://github.com/PiotrMachowski/Python-package-vacuum-map-parser-roborock,
 * licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 */
class RoborockMap
{
    private const BLOCK_CHARGER = 1;
    private const BLOCK_IMAGE = 2;
    private const BLOCK_PATH = 3;
    private const BLOCK_GOTO_PREDICTED_PATH = 5;
    private const BLOCK_ROBOT_POSITION = 8;
    private const BLOCK_NO_GO_AREAS = 9;
    private const BLOCK_VIRTUAL_WALLS = 10;
    private const BLOCK_NO_MOPPING_AREAS = 12;
    private const BLOCK_OBSTACLES = 13;

    private const PIXEL_OUTSIDE = 0x00;
    private const PIXEL_WALL = 0x01;
    private const PIXEL_INSIDE = 0xFF;
    private const PIXEL_SCAN = 0x07;

    // One image pixel corresponds to 50 map units (mm)
    private const MAP_SCALE = 50;

    private const ROOM_COLORS = [
        [240, 178, 122], [133, 193, 233], [217, 136, 128], [52, 152, 219],
        [205, 97, 85], [243, 156, 18], [88, 214, 141], [245, 176, 65],
        [252, 212, 81], [72, 201, 176], [84, 153, 199], [165, 105, 189],
        [82, 190, 128], [236, 112, 99], [93, 173, 226], [244, 208, 63]
    ];

    /**
     * Parses the decompressed map. Coordinates of objects are in map units.
     */
    public static function Parse(string $Raw): array
    {
        if (strlen($Raw) < 20 || substr($Raw, 0, 2) !== 'rr') {
            throw new Exception('Invalid map data');
        }
        $map = [
            'image'      => null,
            'charger'    => null,
            'robot'      => null,
            'path'       => [],
            'predicted'  => [],
            'noGoAreas'  => [],
            'noMopAreas' => [],
            'walls'      => [],
            'obstacles'  => []
        ];
        $length = strlen($Raw);
        $position = self::UInt16($Raw, 2);
        while ($position + 8 <= $length) {
            $headerLength = self::UInt16($Raw, $position + 2);
            // A block header has at least type, header length and data length (8 bytes) and must be complete
            if ($headerLength < 8 || $position + $headerLength > $length) {
                break;
            }
            $type = self::UInt16($Raw, $position);
            $dataLength = self::UInt32($Raw, $position + 4);
            $header = substr($Raw, $position, $headerLength);
            $data = substr($Raw, $position + $headerLength, $dataLength);

            switch ($type) {
                case self::BLOCK_CHARGER:
                    $map['charger'] = self::ParsePosition($data);
                    break;
                case self::BLOCK_ROBOT_POSITION:
                    $map['robot'] = self::ParsePosition($data);
                    break;
                case self::BLOCK_IMAGE:
                    $map['image'] = [
                        'top'    => self::UInt32($header, $headerLength - 16),
                        'left'   => self::UInt32($header, $headerLength - 12),
                        'height' => self::UInt32($header, $headerLength - 8),
                        'width'  => self::UInt32($header, $headerLength - 4),
                        'pixels' => $data
                    ];
                    break;
                case self::BLOCK_PATH:
                    $map['path'] = self::ParsePoints($data);
                    break;
                case self::BLOCK_GOTO_PREDICTED_PATH:
                    $map['predicted'] = self::ParsePoints($data);
                    break;
                case self::BLOCK_NO_GO_AREAS:
                    $map['noGoAreas'] = self::ParseAreas($header, $data);
                    break;
                case self::BLOCK_NO_MOPPING_AREAS:
                    $map['noMopAreas'] = self::ParseAreas($header, $data);
                    break;
                case self::BLOCK_VIRTUAL_WALLS:
                    $count = self::UInt16($header, 8);
                    for ($i = 0; $i < $count; $i++) {
                        $map['walls'][] = [
                            [self::UInt16($data, $i * 8), self::UInt16($data, $i * 8 + 2)],
                            [self::UInt16($data, $i * 8 + 4), self::UInt16($data, $i * 8 + 6)]
                        ];
                    }
                    break;
                case self::BLOCK_OBSTACLES:
                    $count = self::UInt16($header, 8);
                    $size = $count > 0 ? intdiv(strlen($data), $count) : 0;
                    for ($i = 0; $i < $count && $size >= 4; $i++) {
                        $map['obstacles'][] = [self::UInt16($data, $i * $size), self::UInt16($data, $i * $size + 2)];
                    }
                    break;
            }
            // The low byte of the header length is used, like in the reference parser
            $step = $dataLength + ord($header[2]);
            if ($step <= 0) {
                // Invalid block, would never advance
                break;
            }
            $position += $step;
        }
        return $map;
    }

    /**
     * Renders the map as PNG. $RoomNames maps segment IDs to names.
     * Returns ['png' => binary, 'robotRoom' => segment ID or 0].
     */
    public static function Render(array $Map, array $RoomNames, int $MaxSize = 800): array
    {
        $image = $Map['image'];
        if ($image === null || $image['width'] === 0 || $image['height'] === 0) {
            throw new Exception('The map does not contain an image');
        }
        $width = $image['width'];
        $height = $image['height'];
        $pixels = $image['pixels'];
        if ($width > 4096 || $height > 4096 || $width * $height > strlen($pixels)) {
            throw new Exception('Invalid map image size');
        }

        // Bounding box of the used area (display coordinates, y from the top)
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        $rooms = [];
        for ($row = 0; $row < $height; $row++) {
            $offset = $row * $width;
            $y = $height - 1 - $row;
            for ($x = 0; $x < $width; $x++) {
                $value = ord($pixels[$offset + $x]);
                if ($value === self::PIXEL_OUTSIDE) {
                    continue;
                }
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
                if ($value !== self::PIXEL_INSIDE && $value !== self::PIXEL_SCAN && ($value & 0x07) === 7) {
                    $room = $value >> 3;
                    if (!isset($rooms[$room])) {
                        $rooms[$room] = [$x, $y, $x, $y, 0, 0, 0];
                    }
                    $rooms[$room][0] = min($rooms[$room][0], $x);
                    $rooms[$room][1] = min($rooms[$room][1], $y);
                    $rooms[$room][2] = max($rooms[$room][2], $x);
                    $rooms[$room][3] = max($rooms[$room][3], $y);
                    $rooms[$room][4] += $x;
                    $rooms[$room][5] += $y;
                    $rooms[$room][6]++;
                }
            }
        }
        if ($maxX < 0) {
            throw new Exception('The map is empty');
        }
        $margin = 4;
        $minX = max(0, $minX - $margin);
        $minY = max(0, $minY - $margin);
        $maxX = min($width - 1, $maxX + $margin);
        $maxY = min($height - 1, $maxY + $margin);
        $cropWidth = $maxX - $minX + 1;
        $cropHeight = $maxY - $minY + 1;

        // Draw the pixels 1:1, then scale up without smoothing
        $small = imagecreatetruecolor($cropWidth, $cropHeight);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        $transparent = imagecolorallocatealpha($small, 0, 0, 0, 127);
        imagefill($small, 0, 0, $transparent);
        $colorWall = imagecolorallocate($small, 85, 90, 100);
        $colorInside = imagecolorallocate($small, 210, 214, 220);
        $colorScan = imagecolorallocate($small, 228, 231, 235);
        $colorGrey = imagecolorallocate($small, 160, 165, 172);
        $roomColors = [];
        foreach (self::ROOM_COLORS as $index => [$r, $g, $b]) {
            $roomColors[$index] = imagecolorallocate($small, $r, $g, $b);
        }
        for ($row = 0; $row < $height; $row++) {
            $y = $height - 1 - $row - $minY;
            if ($y < 0 || $y >= $cropHeight) {
                continue;
            }
            $offset = $row * $width;
            for ($x = $minX; $x <= $maxX; $x++) {
                $value = ord($pixels[$offset + $x]);
                switch (true) {
                    case $value === self::PIXEL_OUTSIDE:
                        continue 2;
                    case $value === self::PIXEL_WALL:
                        $color = $colorWall;
                        break;
                    case $value === self::PIXEL_INSIDE:
                        $color = $colorInside;
                        break;
                    case $value === self::PIXEL_SCAN:
                        $color = $colorScan;
                        break;
                    case ($value & 0x07) === 7:
                        $color = $roomColors[($value >> 3) % count($roomColors)];
                        break;
                    case ($value & 0x07) === 1:
                        $color = $colorWall;
                        break;
                    default:
                        $color = $colorGrey;
                }
                imagesetpixel($small, $x - $minX, $y, $color);
            }
        }

        $scale = max(1, min(6, intdiv($MaxSize, max($cropWidth, $cropHeight))));
        $canvas = imagecreatetruecolor($cropWidth * $scale, $cropHeight * $scale);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresized($canvas, $small, 0, 0, 0, 0, $cropWidth * $scale, $cropHeight * $scale, $cropWidth, $cropHeight);
        imagedestroy($small);
        imagealphablending($canvas, true);

        // Map units -> canvas pixels
        $toCanvas = function (array $Point) use ($image, $height, $minX, $minY, $scale): array {
            $x = $Point[0] / self::MAP_SCALE - $image['left'];
            $y = $height - 1 - ($Point[1] / self::MAP_SCALE - $image['top']);
            return [(int) round(($x - $minX + 0.5) * $scale), (int) round(($y - $minY + 0.5) * $scale)];
        };

        // Restricted areas and virtual walls
        $noGoFill = imagecolorallocatealpha($canvas, 230, 50, 50, 90);
        $noGoLine = imagecolorallocate($canvas, 220, 40, 40);
        $noMopFill = imagecolorallocatealpha($canvas, 150, 70, 200, 90);
        $noMopLine = imagecolorallocate($canvas, 140, 60, 190);
        foreach ([[$Map['noGoAreas'], $noGoFill, $noGoLine], [$Map['noMopAreas'], $noMopFill, $noMopLine]] as [$areas, $fill, $line]) {
            foreach ($areas as $area) {
                $points = [];
                foreach ($area as $point) {
                    array_push($points, ...$toCanvas($point));
                }
                imagefilledpolygon($canvas, $points, $fill);
                imagepolygon($canvas, $points, $line);
            }
        }
        imagesetthickness($canvas, max(2, $scale));
        foreach ($Map['walls'] as [$from, $to]) {
            [$x1, $y1] = $toCanvas($from);
            [$x2, $y2] = $toCanvas($to);
            imageline($canvas, $x1, $y1, $x2, $y2, $noGoLine);
        }

        // Cleaning path
        imagesetthickness($canvas, 1);
        $pathColor = imagecolorallocatealpha($canvas, 255, 255, 255, 30);
        self::DrawPolyline($canvas, $Map['path'], $toCanvas, $pathColor);
        $predictedColor = imagecolorallocatealpha($canvas, 60, 60, 60, 40);
        self::DrawPolyline($canvas, $Map['predicted'], $toCanvas, $predictedColor);

        // Obstacles
        $obstacleColor = imagecolorallocate($canvas, 240, 140, 0);
        foreach ($Map['obstacles'] as $obstacle) {
            [$x, $y] = $toCanvas($obstacle);
            imagefilledellipse($canvas, $x, $y, 3 * $scale, 3 * $scale, $obstacleColor);
        }

        // Room names
        $textColor = imagecolorallocate($canvas, 30, 35, 45);
        $haloColor = imagecolorallocatealpha($canvas, 255, 255, 255, 40);
        $font = $scale >= 3 ? 5 : 3;
        foreach ($rooms as $segment => $room) {
            $name = $RoomNames[(string) $segment] ?? $RoomNames[$segment] ?? '';
            if ($name === '' || $room[6] === 0) {
                continue;
            }
            $text = mb_convert_encoding($name, 'ISO-8859-1', 'UTF-8');
            $centerX = (int) round(($room[4] / $room[6] - $minX + 0.5) * $scale);
            $centerY = (int) round(($room[5] / $room[6] - $minY + 0.5) * $scale);
            $textWidth = imagefontwidth($font) * strlen($text);
            $textHeight = imagefontheight($font);
            $left = (int) ($centerX - $textWidth / 2);
            $top = (int) ($centerY - $textHeight / 2);
            imagefilledrectangle($canvas, $left - 3, $top - 2, $left + $textWidth + 2, $top + $textHeight + 1, $haloColor);
            imagestring($canvas, $font, $left, $top, $text, $textColor);
        }

        // Charger and robot
        $size = max(10, 4 * $scale);
        if ($Map['charger'] !== null) {
            [$x, $y] = $toCanvas($Map['charger']);
            imagefilledellipse($canvas, $x, $y, $size, $size, imagecolorallocate($canvas, 40, 170, 80));
            imageellipse($canvas, $x, $y, $size, $size, imagecolorallocate($canvas, 255, 255, 255));
        }
        $robotRoom = 0;
        if ($Map['robot'] !== null) {
            [$x, $y] = $toCanvas($Map['robot']);
            $robotSize = (int) round($size * 1.4);
            imagefilledellipse($canvas, $x, $y, $robotSize, $robotSize, imagecolorallocate($canvas, 255, 255, 255));
            imagesetthickness($canvas, 2);
            imageellipse($canvas, $x, $y, $robotSize, $robotSize, imagecolorallocate($canvas, 30, 110, 220));
            imagefilledellipse($canvas, $x, $y, (int) round($robotSize / 3), (int) round($robotSize / 3), imagecolorallocate($canvas, 30, 110, 220));
            imagesetthickness($canvas, 1);
            $robotRoom = self::RoomAt($Map, $Map['robot']);
        }

        ob_start();
        imagepng($canvas, null, 6);
        $png = ob_get_clean();
        imagedestroy($canvas);
        return ['png' => $png, 'robotRoom' => $robotRoom];
    }

    /**
     * Segment ID of the room at a position in map units, 0 if none.
     */
    public static function RoomAt(array $Map, array $Point): int
    {
        $image = $Map['image'];
        if ($image === null) {
            return 0;
        }
        $x = (int) round($Point[0] / self::MAP_SCALE - $image['left']);
        $row = (int) round($Point[1] / self::MAP_SCALE - $image['top']);
        if ($x < 0 || $row < 0 || $x >= $image['width'] || $row >= $image['height']) {
            return 0;
        }
        $value = ord($image['pixels'][$row * $image['width'] + $x]);
        if ($value !== self::PIXEL_INSIDE && $value !== self::PIXEL_SCAN && ($value & 0x07) === 7) {
            return $value >> 3;
        }
        return 0;
    }

    private static function DrawPolyline($Canvas, array $Points, callable $ToCanvas, int $Color): void
    {
        $previous = null;
        foreach ($Points as $point) {
            $current = $ToCanvas($point);
            if ($previous !== null) {
                imageline($Canvas, $previous[0], $previous[1], $current[0], $current[1], $Color);
            }
            $previous = $current;
        }
    }

    private static function ParsePosition(string $Data): array
    {
        $angle = null;
        if (strlen($Data) > 8) {
            $angle = self::UInt32($Data, 8);
            if ($angle > 0xFF) {
                $angle = ($angle & 0xFF) - 256;
            }
        }
        return [self::UInt32($Data, 0), self::UInt32($Data, 4), $angle];
    }

    private static function ParsePoints(string $Data): array
    {
        $points = [];
        $count = intdiv(strlen($Data), 4);
        for ($i = 0; $i < $count; $i++) {
            $points[] = [self::UInt16($Data, $i * 4), self::UInt16($Data, $i * 4 + 2)];
        }
        return $points;
    }

    private static function ParseAreas(string $Header, string $Data): array
    {
        $areas = [];
        $count = self::UInt16($Header, 8);
        for ($i = 0; $i < $count; $i++) {
            $area = [];
            for ($corner = 0; $corner < 4; $corner++) {
                $area[] = [self::UInt16($Data, $i * 16 + $corner * 4), self::UInt16($Data, $i * 16 + $corner * 4 + 2)];
            }
            $areas[] = $area;
        }
        return $areas;
    }

    private static function UInt16(string $Data, int $Offset): int
    {
        if ($Offset + 2 > strlen($Data)) {
            return 0;
        }
        return unpack('v', $Data, $Offset)[1];
    }

    private static function UInt32(string $Data, int $Offset): int
    {
        if ($Offset + 4 > strlen($Data)) {
            return 0;
        }
        return unpack('V', $Data, $Offset)[1];
    }
}
