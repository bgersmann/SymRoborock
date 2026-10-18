<?php

declare(strict_types=1);

/**
 * Code tables of V1 devices. The captions are English and get translated via the locale.json of the module.
 *
 * Taken and modified from python-roborock (roborock/data/v1/v1_code_mappings.py),
 * https://github.com/Python-roborock/python-roborock,
 * licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 */
class RoborockCodes
{
    public const STATES = [
        0    => 'Unknown',
        1    => 'Starting',
        2    => 'Charger disconnected',
        3    => 'Idle',
        4    => 'Remote control active',
        5    => 'Cleaning',
        6    => 'Returning home',
        7    => 'Manual mode',
        8    => 'Charging',
        9    => 'Charging problem',
        10   => 'Paused',
        11   => 'Spot cleaning',
        12   => 'Error',
        13   => 'Shutting down',
        14   => 'Updating',
        15   => 'Docking',
        16   => 'Going to target',
        17   => 'Zoned cleaning',
        18   => 'Room cleaning',
        22   => 'Emptying the bin',
        23   => 'Washing the mop',
        25   => 'Washing the mop',
        26   => 'Going to wash the mop',
        28   => 'In call',
        29   => 'Mapping',
        30   => 'Egg attack',
        32   => 'Patrol',
        33   => 'Attaching the mop',
        34   => 'Detaching the mop',
        100  => 'Charging complete',
        101  => 'Device offline',
        103  => 'Locked',
        202  => 'Air drying stopping',
        6301 => 'Mopping',
        6302 => 'Vacuuming and mopping',
        6303 => 'Vacuuming and mopping',
        6304 => 'Room mopping',
        6305 => 'Room vacuuming and mopping',
        6306 => 'Room vacuuming and mopping',
        6307 => 'Zone mopping',
        6308 => 'Zone vacuuming and mopping',
        6309 => 'Zone vacuuming and mopping',
        6310 => 'Washing the mop'
    ];

    // Icons for the state enumeration
    public const STATE_ICONS = [
        5  => 'broom', 11 => 'broom', 17 => 'broom', 18 => 'broom',
        6  => 'house', 15 => 'house', 26 => 'house',
        8  => 'battery-bolt', 100 => 'battery-full',
        9  => 'triangle-exclamation', 12 => 'triangle-exclamation', 101 => 'wifi-slash',
        10 => 'pause', 3 => 'circle-pause', 22 => 'trash-can', 23 => 'droplet', 25 => 'droplet'
    ];

    public const ERRORS = [
        0  => 'No error',
        1  => 'Laser sensor fault',
        2  => 'Collision sensor fault',
        3  => 'Wheel floating',
        4  => 'Cliff sensor fault',
        5  => 'Main brush jammed',
        6  => 'Side brush jammed',
        7  => 'Wheels jammed',
        8  => 'Robot trapped',
        9  => 'No dustbin',
        10 => 'Filter blocked',
        11 => 'Strong magnetic field detected',
        12 => 'Low battery',
        13 => 'Charging problem',
        14 => 'Battery failure',
        15 => 'Wall sensor dirty',
        16 => 'Robot tilted',
        17 => 'Side brush error',
        18 => 'Fan error',
        19 => 'Dock not connected to power',
        20 => 'Optical flow sensor dirty',
        21 => 'Vertical bumper pressed',
        22 => 'Dock locator error',
        23 => 'Return to dock failed',
        24 => 'No-go zone or invisible wall detected',
        25 => 'Camera error',
        26 => 'Wall sensor error',
        27 => 'VibraRise system jammed',
        28 => 'Robot on carpet',
        29 => 'Filter blocked or wet',
        30 => 'No-go zone or invisible wall detected',
        31 => 'Cannot cross carpet',
        32 => 'Internal error',
        34 => 'Clean auto-empty dock',
        35 => 'Auto-empty dock voltage error',
        36 => 'Mopping roller jammed',
        37 => 'Mopping roller not lowered',
        38 => 'Clean water tank empty',
        39 => 'Dirty water tank full',
        40 => 'Check sink strainer',
        41 => 'Clean water tank error',
        42 => 'Check cleaning brush',
        43 => 'Check cleaning brush',
        44 => 'Check filter screen',
        45 => 'Mopping roller jammed',
        48 => 'Water inlet error',
        49 => 'Drain error',
        51 => 'Temperature protection',
        52 => 'Cleaning carousel error',
        53 => 'Cleaning carousel water full',
        54 => 'Water carriage dropped',
        55 => 'Check cleaning carousel',
        56 => 'Audio error'
    ];

    public const DOCK_ERRORS = [
        0  => 'No error',
        32 => 'No dustbin or filter',
        33 => 'Auto-empty dock fan error',
        34 => 'Duct blockage',
        35 => 'Auto-empty dock voltage error',
        38 => 'Clean water tank empty',
        39 => 'Dirty water tank full',
        42 => 'Maintenance brush jammed',
        44 => 'Dirty water tank latch open',
        46 => 'No dustbin',
        53 => 'Cleaning tank full or blocked'
    ];

    public const FAN_POWER = [
        101 => 'Quiet',
        102 => 'Balanced',
        103 => 'Turbo',
        104 => 'Max',
        105 => 'Off',
        106 => 'Custom',
        108 => 'Max+',
        110 => 'Smart mode'
    ];

    // Older models use percentages instead of codes
    public const FAN_POWER_LEGACY = [
        38  => 'Quiet',
        60  => 'Balanced',
        75  => 'Turbo',
        100 => 'Max'
    ];

    public const WATER_BOX_MODES = [
        200 => 'Off',
        201 => 'Low',
        202 => 'Medium',
        203 => 'High',
        204 => 'Custom',
        205 => 'Min',
        206 => 'Max',
        207 => 'Custom water flow',
        208 => 'Extreme',
        209 => 'Smart mode'
    ];

    // Devices with a water slider (e.g. Saros) report 220 + level 1..30
    public const WATER_SLIDER = [
        221 => 'Very low',
        225 => 'Low',
        230 => 'Mild',
        235 => 'Medium',
        240 => 'Moderate',
        245 => 'High',
        250 => 'Extreme'
    ];

    public const MOP_MODES = [
        300 => 'Standard',
        301 => 'Deep',
        302 => 'Custom',
        303 => 'Deep+',
        304 => 'Fast',
        305 => 'Deep+',
        306 => 'Smart mode'
    ];

    public const DUST_COLLECTION_MODES = [
        0 => 'Smart',
        1 => 'Light',
        2 => 'Balanced',
        4 => 'Max'
    ];

    // Consumables: status field => [ident, caption, lifetime in seconds or count]
    public const CONSUMABLES = [
        'main_brush_work_time' => ['MainBrush', 'Main brush', 1080000],
        'side_brush_work_time' => ['SideBrush', 'Side brush', 720000],
        'filter_work_time'     => ['Filter', 'Filter', 540000],
        'sensor_dirty_time'    => ['Sensors', 'Sensors', 108000]
    ];

    // DPS codes in MQTT pushes and in the home data deviceStatus
    public const DPS = [
        '120' => 'error_code',
        '121' => 'state',
        '122' => 'battery',
        '123' => 'fan_power',
        '124' => 'water_box_mode',
        '125' => 'main_brush_work_time',
        '126' => 'side_brush_work_time',
        '127' => 'filter_work_time',
        '133' => 'charge_status'
    ];
}
