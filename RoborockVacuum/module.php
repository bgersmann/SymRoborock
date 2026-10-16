<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoborockCodes.php';
require_once __DIR__ . '/../libs/RoborockMap.php';

class RoborockVacuum extends IPSModuleStrict
{
    private const DATA_TO_CLOUD = '{5028C495-ECD8-40BE-9DFB-23CA2C094D00}';

    private const CONTROL_START = 1;
    private const CONTROL_PAUSE = 2;
    private const CONTROL_STOP = 3;
    private const CONTROL_HOME = 4;
    private const CONTROL_SPOT = 5;
    private const CONTROL_LOCATE = 6;
    private const CONTROL_EMPTY_BIN = 7;
    private const CONTROL_WASH_MOP = 8;
    private const CONTROL_CLEAN_ROOMS = 9;

    private const MODE_VACUUM = 1;
    private const MODE_VACUUM_MOP = 2;
    private const MODE_MOP = 3;
    private const MODE_CUSTOM = 4;
    private const MODE_SMART = 5;

    // Base options of the mode variables, values reported by the device are added automatically
    private const BASE_OPTIONS = [
        'FanPower'     => [101, 102, 103, 104],
        'WaterBoxMode' => [200, 201, 202, 203],
        'MopMode'      => [300, 301, 303]
    ];
    private const BASE_OPTIONS_WATER_SLIDER = [200, 225, 235, 245];

    // States in which the map changes
    private const ACTIVE_STATES = [4, 5, 6, 7, 11, 15, 16, 17, 18, 26, 29];

    private const DETAILS_INTERVAL = 600;
    private const ROOMS_INTERVAL = 3600;
    private const NO_MAP = 63;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('DUID', '');
        $this->RegisterPropertyInteger('Connection', 0);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('UpdateInterval', 30);
        $this->RegisterPropertyBoolean('ShowMap', true);
        $this->RegisterPropertyString('Programs', '[]');
        $this->RegisterPropertyBoolean('ProgramReturnsHome', false);

        $this->RegisterAttributeString('Rooms', '{}');
        $this->RegisterAttributeString('Maps', '{}');
        $this->RegisterAttributeInteger('CurrentMap', -1);
        $this->RegisterAttributeString('Routines', '[]');
        $this->RegisterAttributeString('KnownValues', '{}');
        $this->RegisterAttributeInteger('DockType', 0);
        $this->RegisterAttributeBoolean('SmartMode', false);
        $this->RegisterAttributeBoolean('WaterSlider', false);

        $this->RegisterTimer('Update', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Update\', 0);');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $duid = $this->ReadPropertyString('DUID');
        $this->SetReceiveDataFilter('.*"DUID":"' . preg_quote($duid, '/') . '".*');

        $this->RegisterVariables();
        if (!$this->ReadPropertyBoolean('ShowMap')) {
            $mediaID = @IPS_GetObjectIDByIdent('MapImage', $this->InstanceID);
            if ($mediaID !== false) {
                IPS_DeleteMedia($mediaID, true);
            }
        }

        if ($duid === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('Update', max(10, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY && $this->HasActiveParent()) {
            $this->SetBuffer('DetailsTime', '0');
            $this->SetBuffer('RoomsTime', '0');
            $this->SetBuffer('MapTime', '0');
            $this->RegisterOnceTimer('InitialUpdate', 'IPS_RequestAction($_IPS[\'TARGET\'], \'Update\', 0);');
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (str_starts_with($Ident, 'RoomSelect')) {
            // Only a selection for "Clean selected rooms"
            $this->SetValue($Ident, (bool) $Value);
            return;
        }
        switch ($Ident) {
            case 'Update':
                $this->Update();
                return;
            case 'Control':
                $this->Control((int) $Value);
                break;
            case 'CleanMode':
                $this->SetCleanMode((int) $Value);
                break;
            case 'FanPower':
                $this->SendCommandChecked('set_custom_mode', [(int) $Value]);
                break;
            case 'WaterBoxMode':
                $this->SendCommandChecked('set_water_box_custom_mode', [(int) $Value]);
                break;
            case 'MopMode':
                $this->SendCommandChecked('set_mop_mode', [(int) $Value]);
                break;
            case 'Passes':
                break;
            case 'Room':
                if ((int) $Value > 0) {
                    $this->CleanRooms((string) $Value);
                }
                break;
            case 'Map':
                $this->SendCommandChecked('load_multi_map', [(int) $Value]);
                // The rooms of the new map are read after the robot has loaded it
                $this->SetBuffer('RoomsTime', '0');
                $this->SetBuffer('MapTime', '0');
                break;
            case 'Routine':
                if ((int) $Value > 0) {
                    $this->RunRoutine((int) $Value);
                }
                $this->SetValue('Routine', 0);
                return;
            case 'Program':
                $programs = $this->GetPrograms();
                if (isset($programs[(int) $Value - 1])) {
                    $this->RunProgram($programs[(int) $Value - 1]);
                }
                $this->SetValue('Program', 0);
                return;
            default:
                throw new Exception('Invalid ident');
        }
        $this->SetValue($Ident, $Value);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $this->FillProgramElements($form['elements']);
        $info = $this->GetDeviceInfo();
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'DeviceInfo') {
                $action['caption'] = ($info === null)
                    ? $this->Translate('The device was not found in the Roborock account.')
                    : sprintf($this->Translate('Model: %s, firmware: %s, protocol: %s'), $info['model'] ?: '-', $info['firmware'] ?: '-', $info['pv']);
            }
        }
        return json_encode($form);
    }

    /**
     * Options of the program list and hints about the rooms, depending on the maps of the robot.
     */
    private function FillProgramElements(array &$Elements): void
    {
        $maps = json_decode($this->ReadAttributeString('Maps'), true) ?: [];
        foreach ($Elements as &$element) {
            if (isset($element['items'])) {
                $this->FillProgramElements($element['items']);
            }
            switch ($element['name'] ?? '') {
                case 'Programs':
                    $element['form'] = 'return json_decode(RRV_UIProgramForm($id, (int) ($Programs[\'Map\'] ?? -1), (string) ($Programs[\'Rooms\'] ?? \'\')), true);';
                    foreach ($element['columns'] as &$column) {
                        if (in_array($column['name'], ['Map', 'CleanMode', 'FanPower', 'WaterBoxMode', 'MopMode', 'Passes'], true)) {
                            $column['edit']['options'] = $this->GetProgramOptions($column['name']);
                        }
                    }
                    break;
                case 'ProgramRooms':
                    $lines = [];
                    foreach ($maps as $map) {
                        $lines[] = $map['name'] . ': ' . implode(', ', array_filter($map['rooms']));
                    }
                    $element['caption'] = count($lines) > 0
                        ? $this->Translate('Rooms (comma separated, empty = whole map):') . "\n" . implode("\n", $lines)
                        : $this->Translate('The rooms are shown after the first update of the robot.');
                    break;
                case 'ProgramCheck':
                    $problems = $this->CheckPrograms($maps);
                    $element['caption'] = implode("\n", $problems);
                    $element['visible'] = count($problems) > 0;
                    break;
            }
        }
    }

    /**
     * Maps for the programs: -1 = active map, then all maps of the robot. Rooms as segment => name.
     */
    private function GetProgramMaps(): array
    {
        $maps = [-1 => [
            'name'  => $this->Translate('Active map'),
            'rooms' => json_decode($this->ReadAttributeString('Rooms'), true) ?: []
        ]];
        foreach (json_decode($this->ReadAttributeString('Maps'), true) ?: [] as $flag => $map) {
            $maps[(int) $flag] = ['name' => $map['name'], 'rooms' => array_filter($map['rooms'])];
        }
        return $maps;
    }

    private function RoomFieldName(int $Map, string $Segment): string
    {
        return 'ProgramRoom' . ($Map < 0 ? 'A' : (string) $Map) . '_' . $Segment;
    }

    private function GetProgramOptions(string $Ident): array
    {
        $unchanged = ['caption' => $this->Translate('Unchanged'), 'value' => -1];
        $options = [];
        switch ($Ident) {
            case 'Map':
                foreach ($this->GetProgramMaps() as $flag => $map) {
                    $options[] = ['caption' => $map['name'], 'value' => $flag];
                }
                break;
            case 'CleanMode':
                $options[] = $unchanged;
                foreach ($this->GetCleanModes() as $value => [$caption]) {
                    $options[] = ['caption' => $caption, 'value' => $value];
                }
                break;
            case 'Passes':
                foreach ([1, 2, 3] as $value) {
                    $options[] = ['caption' => $value . 'x', 'value' => $value];
                }
                break;
            default:
                $options[] = $unchanged;
                foreach ($this->GetModeOptions($Ident) as $value => $caption) {
                    $options[] = ['caption' => $caption, 'value' => $value];
                }
        }
        return $options;
    }

    private function CheckPrograms(array $Maps): array
    {
        $problems = [];
        $currentMap = $this->ReadAttributeInteger('CurrentMap');
        foreach ($this->GetPrograms() as $program) {
            $flag = (int) ($program['Map'] ?? -1);
            $flag = $flag >= 0 ? $flag : $currentMap;
            $rooms = $Maps[(string) $flag]['rooms'] ?? json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
            [, $missing] = $this->ResolveRooms((string) ($program['Rooms'] ?? ''), $rooms);
            if (count($missing) > 0) {
                $problems[] = sprintf($this->Translate('Program "%s": unknown rooms %s'), trim($program['Name']), implode(', ', $missing));
            }
        }
        return $problems;
    }

    // -------------------------------------------------------------------------
    // Public functions
    // -------------------------------------------------------------------------

    /**
     * Reads the status of the vacuum. Consumables, statistics and the map are read every 10 minutes,
     * the map continuously during cleaning, rooms and routines every hour.
     */
    public function Update(): bool
    {
        if ($this->ReadPropertyString('DUID') === '' || !$this->HasActiveParent()) {
            return false;
        }
        try {
            $status = json_decode($this->SendCommand('get_status', '[]'), true);
            if (is_array($status) && isset($status[0])) {
                $status = $status[0];
            }
            if (!is_array($status)) {
                throw new Exception('Invalid status');
            }
            $this->ApplyStatus($status);
            if ($this->GetStatus() !== 102) {
                $this->SetStatus(102);
            }
        } catch (Exception $e) {
            // No log entry: the state is visible in the status of the instance and the connection variables
            $this->SendDebug('Update', $e->getMessage(), 0);
            $this->SetStatus(201);
            return false;
        }

        if (time() - (int) $this->GetBuffer('RoomsTime') >= self::ROOMS_INTERVAL) {
            $this->RunSafely('Rooms', function () {
                $this->UpdateRooms();
                $this->UpdateRoutines();
                $this->UpdateFeatures();
                $this->SetBuffer('RoomsTime', (string) time());
            });
        }
        if (time() - (int) $this->GetBuffer('DetailsTime') >= self::DETAILS_INTERVAL) {
            $this->SetBuffer('DetailsTime', (string) time());
            $this->UpdateDetails();
        }
        if ($this->ReadPropertyBoolean('ShowMap') && $this->GetValue('CloudConnection') === 1) {
            // Maps are only available via the cloud: during cleaning on every update, afterwards once more, otherwise hourly
            $active = in_array($this->GetValue('State'), self::ACTIVE_STATES, true) || $this->GetValue('State') > 6300;
            $wasActive = $this->GetBuffer('MapActive') === '1';
            $this->SetBuffer('MapActive', $active ? '1' : '0');
            if ($active || $wasActive || time() - (int) $this->GetBuffer('MapTime') >= self::ROOMS_INTERVAL) {
                $this->RunSafely('Map', function () {
                    $this->UpdateMap();
                });
            }
        }
        return true;
    }

    /**
     * Reads the maps and the rooms of the current map and updates the room variables.
     */
    public function UpdateRooms(): string
    {
        // Names of all maps and their rooms (newer firmware)
        $maps = [];
        try {
            $list = json_decode($this->SendCommand('get_multi_maps_list', '[]'), true);
            $list = (is_array($list) && isset($list[0])) ? $list[0] : $list;
            foreach ($list['map_info'] ?? [] as $info) {
                $rooms = [];
                foreach ($info['rooms'] ?? [] as $room) {
                    $rooms[(string) $room['id']] = (string) ($room['iot_name'] ?? '');
                }
                $flag = (int) $info['mapFlag'];
                $maps[(string) $flag] = [
                    'name'  => ($info['name'] ?? '') !== '' ? $info['name'] : sprintf($this->Translate('Map %d'), $flag + 1),
                    'rooms' => $rooms
                ];
            }
        } catch (Exception $e) {
            $this->SendDebug('Maps', $e->getMessage(), 0);
        }
        $this->WriteAttributeString('Maps', json_encode($maps));

        // Segment IDs of the current map
        $mapping = json_decode($this->SendCommand('get_room_mapping', '[]'), true);
        if (!is_array($mapping)) {
            $mapping = [];
        }
        if (count($mapping) > 0 && !is_array($mapping[0])) {
            $mapping = [$mapping];
        }
        $currentNames = $maps[(string) $this->ReadAttributeInteger('CurrentMap')]['rooms'] ?? [];
        $homeRooms = null;
        $rooms = [];
        foreach ($mapping as $entry) {
            $segment = (string) (int) $entry[0];
            $name = $currentNames[$segment] ?? '';
            if ($name === '') {
                // Older firmware: names from the rooms of the home
                $homeRooms ??= $this->GetHomeRooms();
                $name = $homeRooms[(string) ($entry[1] ?? '')] ?? sprintf($this->Translate('Room %s'), $segment);
            }
            $rooms[$segment] = $name;
        }
        $this->WriteAttributeString('Rooms', json_encode($rooms));
        $this->RegisterMapVariables();
        return sprintf($this->Translate('%d rooms found'), count($rooms));
    }

    /**
     * Reads the map from the robot and stores it as image.
     */
    public function UpdateMap(): bool
    {
        $response = json_decode($this->SendToCloud(['Command' => 'GetMap', 'DUID' => $this->ReadPropertyString('DUID')]), true);
        if (!is_array($response) || isset($response['error'])) {
            throw new Exception((string) ($response['error']['message'] ?? 'The map could not be loaded'));
        }
        $raw = gzdecode(base64_decode((string) ($response['map'] ?? '')));
        if ($raw === false) {
            throw new Exception('The map could not be decompressed');
        }
        $this->SetBuffer('MapTime', (string) time());
        $map = RoborockMap::Parse($raw);
        $rooms = json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
        $result = RoborockMap::Render($map, $rooms);
        $this->SetMapImage($result['png']);
        $this->SetValue('CurrentRoom', $rooms[(string) $result['robotRoom']] ?? '-');
        return true;
    }

    /**
     * Cleans the given rooms with the number of passes of the variable "Passes".
     * $SegmentIDs is a comma separated list, e.g. "16,17" (values of the variable "Clean room").
     */
    public function CleanRooms(string $SegmentIDs): void
    {
        $this->CleanSegments(array_map('intval', explode(',', $SegmentIDs)), (int) $this->GetValue('Passes'));
    }

    /**
     * Cleans rooms of the current map by name, e.g. "Flur,Wohnzimmer,Küche". The order is kept.
     */
    public function CleanRoomsByName(string $RoomNames): void
    {
        $rooms = json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
        [$segments, $missing] = $this->ResolveRooms($RoomNames, $rooms);
        if (count($missing) > 0) {
            throw new Exception(sprintf($this->Translate('Unknown rooms on the current map: %s (available: %s)'), implode(', ', $missing), implode(', ', $rooms)));
        }
        $this->CleanSegments($segments, (int) $this->GetValue('Passes'));
    }

    /**
     * Edit dialog of a cleaning program: one checkbox per room for every map, the checked rooms are
     * written into the field "Rooms" in the order of clicking.
     */
    public function UIProgramForm(int $Map, string $Rooms): string
    {
        $maps = $this->GetProgramMaps();
        $selected = array_map('mb_strtolower', array_map('trim', explode(',', $Rooms)));

        // Script for every checkbox and the map selection: collects the checkboxes per map
        $collect = [];
        foreach ($maps as $flag => $map) {
            $entries = [];
            foreach (array_keys($map['rooms']) as $segment) {
                $entries[] = var_export((string) $segment, true) . ' => ($' . $this->RoomFieldName($flag, (string) $segment) . ' ?? false)';
            }
            $collect[] = var_export((string) $flag, true) . ' => [' . implode(', ', $entries) . ']';
        }
        $onChange = '$rooms = [' . implode(', ', $collect) . ']; RRV_UIProgramRoomsChanged($id, json_encode($rooms[(string) $Map] ?? []), (int) $Map, $Rooms);';

        $mapOptions = [];
        foreach ($maps as $flag => $map) {
            $items = [];
            foreach ($map['rooms'] as $segment => $name) {
                $items[] = [
                    'type'     => 'CheckBox',
                    'name'     => $this->RoomFieldName($flag, (string) $segment),
                    'caption'  => $name,
                    'value'    => ($flag === $Map) && in_array(mb_strtolower($name), $selected, true),
                    'onChange' => $onChange
                ];
            }
            $mapOptions[] = ['caption' => $map['name'], 'value' => $flag, 'items' => $items];
        }

        $form = [
            ['type' => 'ValidationTextBox', 'name' => 'Name', 'caption' => $this->Translate('Name'), 'validate' => '^\\s*\\S.*$', 'width' => '400px'],
            ['type' => 'Select', 'name' => 'Map', 'caption' => $this->Translate('Map'), 'value' => $Map, 'options' => $mapOptions, 'onChange' => $onChange],
            ['type' => 'ValidationTextBox', 'name' => 'Rooms', 'caption' => $this->Translate('Rooms in cleaning order (empty = whole map)'), 'width' => '400px']
        ];
        foreach ([
            'CleanMode'    => 'Cleaning mode',
            'FanPower'     => 'Suction power',
            'WaterBoxMode' => 'Water flow',
            'MopMode'      => 'Mop route'
        ] as $ident => $caption) {
            $form[] = ['type' => 'Select', 'name' => $ident, 'caption' => $this->Translate($caption), 'options' => $this->GetProgramOptions($ident)];
        }
        $form[] = ['type' => 'Select', 'name' => 'Passes', 'caption' => $this->Translate('Passes'), 'options' => $this->GetProgramOptions('Passes')];
        return json_encode($form);
    }

    /**
     * Updates the field "Rooms" of the edit dialog: keeps the order of rooms that stay checked and appends new ones.
     */
    public function UIProgramRoomsChanged(string $Checked, int $Map, string $Rooms): void
    {
        $maps = $this->GetProgramMaps();
        $names = $maps[$Map]['rooms'] ?? [];
        $checked = [];
        foreach (json_decode($Checked, true) ?: [] as $segment => $value) {
            if ($value && isset($names[$segment])) {
                $checked[] = $names[$segment];
            }
        }
        $result = [];
        foreach (explode(',', $Rooms) as $name) {
            $name = trim($name);
            foreach ($checked as $candidate) {
                if (mb_strtolower($candidate) === mb_strtolower($name) && !in_array($candidate, $result, true)) {
                    $result[] = $candidate;
                }
            }
        }
        foreach ($checked as $candidate) {
            if (!in_array($candidate, $result, true)) {
                $result[] = $candidate;
            }
        }
        $this->UpdateFormField('Rooms', 'value', implode(', ', $result));
    }

    /**
     * Starts a cleaning program of the instance configuration by its name.
     */
    public function StartProgram(string $Name): void
    {
        foreach ($this->GetPrograms() as $program) {
            if (mb_strtolower(trim($program['Name'])) === mb_strtolower(trim($Name))) {
                $this->RunProgram($program);
                return;
            }
        }
        throw new Exception(sprintf($this->Translate('Unknown program: %s'), $Name));
    }

    /**
     * Sends any V1 command (see python-roborock RoborockCommand). $Params is JSON, e.g. "[]" or "[102]".
     * Returns the result as JSON.
     */
    public function SendCommand(string $Method, string $Params): string
    {
        $params = json_decode($Params === '' ? '[]' : $Params, true);
        if ($params === null && trim($Params) !== 'null') {
            throw new Exception('Params must be valid JSON');
        }
        $response = json_decode($this->SendToCloud([
            'Command' => 'RPC',
            'DUID'    => $this->ReadPropertyString('DUID'),
            'Method'  => $Method,
            'Params'  => $params,
            'Mode'    => ['auto', 'cloud', 'local'][$this->ReadPropertyInteger('Connection')] ?? 'auto',
            'Host'    => $this->ReadPropertyString('Host')
        ]), true);
        if (!is_array($response)) {
            throw new Exception('No response from the cloud instance');
        }
        $this->UpdateConnection($response);
        if (isset($response['error'])) {
            $error = $response['error'];
            throw new Exception(is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error);
        }
        $result = $response['result'] ?? null;
        if ($result === 'unknown_method') {
            throw new Exception(sprintf($this->Translate('The command %s is not supported by this device'), $Method));
        }
        return json_encode($result);
    }

    // -------------------------------------------------------------------------
    // Data flow
    // -------------------------------------------------------------------------

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (($data['DUID'] ?? '') !== $this->ReadPropertyString('DUID') || ($data['Type'] ?? '') !== 'dps') {
            return '';
        }
        $this->SendDebug('Push', json_encode($data['Data']), 0);
        $status = [];
        foreach ($data['Data'] as $code => $value) {
            if (isset(RoborockCodes::DPS[(string) $code])) {
                $status[RoborockCodes::DPS[(string) $code]] = $value;
            }
        }
        if (count($status) > 0) {
            $this->ApplyStatus($status);
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    private function Control(int $Value): void
    {
        switch ($Value) {
            case self::CONTROL_START:
                $inCleaning = (int) $this->GetBuffer('InCleaning');
                $paused = $this->GetValue('State') === 10;
                $method = 'app_start';
                if ($paused && $inCleaning === 2) {
                    $method = 'resume_zoned_clean';
                } elseif ($paused && $inCleaning === 3) {
                    $method = 'resume_segment_clean';
                }
                $this->SendCommandChecked($method, []);
                break;
            case self::CONTROL_PAUSE:
                $this->SendCommandChecked('app_pause', []);
                break;
            case self::CONTROL_STOP:
                $this->SendCommandChecked('app_stop', []);
                break;
            case self::CONTROL_HOME:
                $this->SendCommandChecked('app_charge', []);
                break;
            case self::CONTROL_SPOT:
                $this->SendCommandChecked('app_spot', []);
                break;
            case self::CONTROL_LOCATE:
                $this->SendCommandChecked('find_me', []);
                break;
            case self::CONTROL_EMPTY_BIN:
                $this->SendCommandChecked('app_start_collect_dust', []);
                break;
            case self::CONTROL_WASH_MOP:
                $this->SendCommandChecked('app_start_wash', []);
                break;
            case self::CONTROL_CLEAN_ROOMS:
                $segments = [];
                foreach (array_keys(json_decode($this->ReadAttributeString('Rooms'), true) ?: []) as $segment) {
                    $id = @$this->GetIDForIdent('RoomSelect' . $segment);
                    if ($id !== false && GetValueBoolean($id)) {
                        $segments[] = $segment;
                    }
                }
                $this->CleanRooms(implode(',', $segments));
                break;
            default:
                throw new Exception('Invalid value');
        }
    }

    /**
     * Sets the combination of suction power, water flow and mop route like the cleaning modes of the app.
     */
    private function SetCleanMode(int $Mode): void
    {
        $fan = (int) $this->GetValue('FanPower');
        $water = (int) $this->GetValue('WaterBoxMode');
        $hasMopMode = @$this->GetIDForIdent('MopMode') !== false;
        $mop = $hasMopMode ? (int) $this->GetValue('MopMode') : 300;

        // Keep the current levels where they fit the new mode
        $fanLevel = in_array($fan, [101, 102, 103, 104, 108], true) || ($fan > 0 && $fan <= 100) ? $fan : 102;
        $waterLevel = ($water > 200 && !in_array($water, [204, 207, 209], true)) ? $water : ($this->ReadAttributeBoolean('WaterSlider') ? 235 : 202);
        $mopLevel = in_array($mop, [300, 301, 303, 304, 305], true) ? $mop : 300;

        switch ($Mode) {
            case self::MODE_VACUUM:
                $values = [$fanLevel, 200, $mopLevel];
                break;
            case self::MODE_VACUUM_MOP:
                $values = [$fanLevel, $waterLevel, $mopLevel];
                break;
            case self::MODE_MOP:
                $values = [105, $waterLevel, $mopLevel];
                break;
            case self::MODE_CUSTOM:
                $values = [106, 204, 302];
                break;
            case self::MODE_SMART:
                $values = [110, 209, 306];
                break;
            default:
                throw new Exception('Invalid value');
        }
        $params = ['fan_power' => $values[0], 'water_box_mode' => $values[1]];
        if ($hasMopMode) {
            $params['mop_mode'] = $values[2];
        }
        $this->SendCommandChecked('set_clean_motor_mode', [$params]);
    }

    private function CleanSegments(array $Segments, int $Passes): void
    {
        $segments = array_values(array_filter(array_map('intval', $Segments)));
        if (count($segments) === 0) {
            throw new Exception($this->Translate('No rooms selected'));
        }
        $params = ['segments' => $segments];
        if ($Passes > 1) {
            $params['repeat'] = $Passes;
        }
        $this->SendCommandChecked('app_segment_clean', [$params]);
    }

    /**
     * Returns [segment IDs, unknown names] for a comma separated list of room names.
     */
    private function ResolveRooms(string $RoomNames, array $Rooms): array
    {
        $lookup = [];
        foreach ($Rooms as $segment => $name) {
            $lookup[mb_strtolower(trim($name))] = (int) $segment;
        }
        $segments = [];
        $missing = [];
        foreach (explode(',', $RoomNames) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (isset($lookup[mb_strtolower($name)])) {
                $segments[] = $lookup[mb_strtolower($name)];
            } else {
                $missing[] = $name;
            }
        }
        return [$segments, $missing];
    }

    private function GetPrograms(): array
    {
        $programs = json_decode($this->ReadPropertyString('Programs'), true);
        if (!is_array($programs)) {
            return [];
        }
        return array_values(array_filter($programs, function ($program) {
            return trim((string) ($program['Name'] ?? '')) !== '';
        }));
    }

    private function RunProgram(array $Program): void
    {
        $this->SendDebug('Program', json_encode($Program), 0);
        $state = (int) $this->GetValue('State');
        $active = in_array($state, self::ACTIVE_STATES, true) || $state > 6300;
        if ($active && $this->ReadPropertyBoolean('ProgramReturnsHome')) {
            // Like a start/stop button: a running cleaning is ended
            $this->SendCommandChecked('app_charge', []);
            return;
        }

        $map = (int) ($Program['Map'] ?? -1);
        if ($map >= 0 && $map !== $this->ReadAttributeInteger('CurrentMap')) {
            $this->SwitchMap($map);
        }

        // Cleaning mode first, then the individual settings override it
        if ((int) ($Program['CleanMode'] ?? -1) > 0 && @$this->GetIDForIdent('CleanMode') !== false) {
            $this->SetCleanMode((int) $Program['CleanMode']);
            $this->SetValue('CleanMode', (int) $Program['CleanMode']);
        }
        foreach (['FanPower' => 'set_custom_mode', 'WaterBoxMode' => 'set_water_box_custom_mode', 'MopMode' => 'set_mop_mode'] as $ident => $method) {
            $value = (int) ($Program[$ident] ?? -1);
            if ($value >= 0 && @$this->GetIDForIdent($ident) !== false) {
                $this->SendCommand($method, json_encode([$value]));
                $this->SetValue($ident, $value);
            }
        }

        $passes = max(1, (int) ($Program['Passes'] ?? 1));
        if (trim((string) ($Program['Rooms'] ?? '')) === '') {
            $this->SendCommandChecked('app_start', []);
            return;
        }
        $rooms = json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
        [$segments, $missing] = $this->ResolveRooms((string) $Program['Rooms'], $rooms);
        if (count($missing) > 0) {
            throw new Exception(sprintf($this->Translate('Unknown rooms on the current map: %s (available: %s)'), implode(', ', $missing), implode(', ', $rooms)));
        }
        $this->CleanSegments($segments, $passes);
    }

    /**
     * Loads another map and waits until the robot uses it, then reads its rooms.
     */
    private function SwitchMap(int $Flag): void
    {
        $this->SendDebug('Program', 'Switching to map ' . $Flag, 0);
        $this->SendCommand('load_multi_map', json_encode([$Flag]));
        $deadline = time() + 25;
        do {
            IPS_Sleep(2500);
            $status = json_decode($this->SendCommand('get_status', '[]'), true);
            $status = (is_array($status) && isset($status[0])) ? $status[0] : $status;
            $current = ((int) ($status['map_status'] ?? 0)) >> 2;
        } while ($current !== $Flag && time() < $deadline);
        if ($current !== $Flag) {
            throw new Exception($this->Translate('The robot did not switch the map'));
        }
        $this->ApplyStatus($status);
        $this->UpdateRooms();
    }

    private function UpdateConnection(array $Response): void
    {
        if (isset($Response['cloud'])) {
            $this->SetValue('CloudConnection', (int) $Response['cloud']);
        }
        // Only known if the local connection was used for this command
        if (isset($Response['local'])) {
            $this->SetValue('LocalConnection', (bool) $Response['local']);
        }
    }

    private function RunRoutine(int $RoutineID): void
    {
        $response = json_decode($this->SendToCloud(['Command' => 'RunRoutine', 'RoutineID' => $RoutineID]), true);
        if (!is_array($response) || isset($response['error'])) {
            throw new Exception((string) ($response['error']['message'] ?? 'The routine could not be started'));
        }
        $this->RegisterOnceTimer('CommandUpdate', 'IPS_Sleep(3000); IPS_RequestAction($_IPS[\'TARGET\'], \'Update\', 0);');
    }

    private function SendCommandChecked(string $Method, array $Params): void
    {
        $result = json_decode($this->SendCommand($Method, json_encode($Params)), true);
        $this->SendDebug($Method, json_encode($result), 0);
        // Read the new status shortly after the command
        $this->RegisterOnceTimer('CommandUpdate', 'IPS_Sleep(2000); IPS_RequestAction($_IPS[\'TARGET\'], \'Update\', 0);');
    }

    private function SendToCloud(array $Data): string
    {
        if (!$this->HasActiveParent()) {
            throw new Exception($this->Translate('The Roborock Cloud instance is not active'));
        }
        $Data['DataID'] = self::DATA_TO_CLOUD;
        return $this->SendDataToParent(json_encode($Data));
    }

    private function RunSafely(string $Name, callable $Function): void
    {
        try {
            $Function();
        } catch (Exception $e) {
            $this->SendDebug($Name, $e->getMessage(), 0);
        }
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    private function GetDeviceInfo(): ?array
    {
        if ($this->ReadPropertyString('DUID') === '' || !$this->HasActiveParent()) {
            return null;
        }
        try {
            $info = json_decode($this->SendToCloud(['Command' => 'GetDevice', 'DUID' => $this->ReadPropertyString('DUID')]), true);
        } catch (Exception $e) {
            return null;
        }
        return (is_array($info) && !isset($info['error'])) ? $info : null;
    }

    private function GetHomeRooms(): array
    {
        $names = json_decode($this->SendToCloud(['Command' => 'GetRooms', 'Refresh' => true]), true);
        return (is_array($names) && !isset($names['error'])) ? $names : [];
    }

    private function UpdateRoutines(): void
    {
        $routines = json_decode($this->SendToCloud(['Command' => 'GetRoutines', 'DUID' => $this->ReadPropertyString('DUID')]), true);
        if (!is_array($routines) || isset($routines['error'])) {
            throw new Exception((string) ($routines['error']['message'] ?? 'Routines could not be loaded'));
        }
        $this->SendDebug('Routines', json_encode($routines), 0);
        $this->WriteAttributeString('Routines', json_encode($routines));
        $this->RegisterRoutineVariable();
    }

    private function UpdateFeatures(): void
    {
        $init = json_decode($this->SendCommand('app_get_init_status', '[]'), true);
        $init = (is_array($init) && isset($init[0])) ? $init[0] : $init;
        $features = (string) ($init['new_feature_info_str'] ?? '');
        // Bit 55 of new_feature_info_str: smart cleaning mode (python-roborock NewFeatureStrBit.SMART_CLEAN_MODE_SET)
        $index = strlen($features) - 1 - intdiv(55, 4);
        if ($index >= 0 && ((hexdec($features[$index]) >> (55 % 4)) & 1) === 1 && !$this->ReadAttributeBoolean('SmartMode')) {
            $this->WriteAttributeBoolean('SmartMode', true);
            $this->RegisterCleanModeVariable();
        }
    }

    private function UpdateDetails(): void
    {
        $this->RunSafely('Consumables', function () {
            $consumables = json_decode($this->SendCommand('get_consumable', '[]'), true);
            if (is_array($consumables) && isset($consumables[0])) {
                $consumables = $consumables[0];
            }
            if (is_array($consumables)) {
                foreach (RoborockCodes::CONSUMABLES as $field => [$ident, $caption, $lifetime]) {
                    if (isset($consumables[$field])) {
                        $this->SetValue($ident, max(0, (int) round(100 - $consumables[$field] / $lifetime * 100)));
                    }
                }
            }
        });

        $this->RunSafely('Summary', function () {
            $summary = json_decode($this->SendCommand('get_clean_summary', '[]'), true);
            if (is_array($summary) && array_is_list($summary)) {
                // Older firmware: [clean_time, clean_area, clean_count, [records]]
                $summary = [
                    'clean_time'  => $summary[0] ?? 0,
                    'clean_area'  => $summary[1] ?? 0,
                    'clean_count' => $summary[2] ?? 0,
                    'records'     => $summary[3] ?? []
                ];
            }
            if (is_array($summary)) {
                $this->SetValue('TotalCleanTime', round(($summary['clean_time'] ?? 0) / 3600, 1));
                $this->SetValue('TotalCleanArea', round(($summary['clean_area'] ?? 0) / 1000000, 1));
                $this->SetValue('TotalCleanCount', (int) ($summary['clean_count'] ?? 0));
                $records = $summary['records'] ?? [];
                $last = $summary['last_clean_t'] ?? (is_array($records) && count($records) > 0 ? max($records) : 0);
                if (is_numeric($last) && $last > 0) {
                    $this->SetValue('LastClean', (int) $last);
                }
            }
        });
    }

    private function ApplyStatus(array $Status): void
    {
        $this->SendDebug('Status', json_encode($Status), 0);

        if (isset($Status['state'])) {
            $state = (int) $Status['state'];
            $this->SetValue('State', $state);
            $this->SetValue('Control', $this->ControlFromState($state));
            if (!in_array($state, [5, 11, 17, 18], true) && $this->GetValue('Room') !== 0) {
                $this->SetValue('Room', 0);
            }
        }
        if (isset($Status['battery'])) {
            $this->SetValue('Battery', (int) $Status['battery']);
        }
        if (isset($Status['error_code'])) {
            $this->SetValue('Error', (int) $Status['error_code']);
        }
        if (isset($Status['clean_time'])) {
            $this->SetValue('CleanTime', (int) $Status['clean_time']);
        }
        if (isset($Status['clean_area'])) {
            $this->SetValue('CleanArea', round($Status['clean_area'] / 1000000, 1));
        }
        if (isset($Status['in_cleaning'])) {
            $this->SetBuffer('InCleaning', (string) $Status['in_cleaning']);
        }
        if (isset($Status['water_box_mode'])) {
            $water = (int) $Status['water_box_mode'];
            if ($water > 220 && $water <= 250 && !$this->ReadAttributeBoolean('WaterSlider')) {
                $this->WriteAttributeBoolean('WaterSlider', true);
                $this->RegisterModeVariable('WaterBoxMode');
            }
        }
        if (($Status['fan_power'] ?? 0) === 110 && !$this->ReadAttributeBoolean('SmartMode')) {
            $this->WriteAttributeBoolean('SmartMode', true);
        }

        foreach (['fan_power' => 'FanPower', 'water_box_mode' => 'WaterBoxMode', 'mop_mode' => 'MopMode'] as $field => $ident) {
            if (isset($Status[$field])) {
                $value = (int) $Status[$field];
                $this->RememberValue($ident, $value);
                $this->SetValue($ident, $value);
            }
        }
        if (isset($Status['water_box_mode']) || isset($Status['fan_power']) || isset($Status['mop_mode'])) {
            if (@$this->GetIDForIdent('CleanMode') === false && @$this->GetIDForIdent('WaterBoxMode') !== false) {
                $this->RegisterCleanModeVariable();
            }
            if (@$this->GetIDForIdent('CleanMode') !== false) {
                $this->SetValue('CleanMode', $this->CleanModeFromValues());
            }
        }

        if (isset($Status['map_status'])) {
            $currentMap = ((int) $Status['map_status']) >> 2;
            if ($currentMap !== self::NO_MAP && $currentMap !== $this->ReadAttributeInteger('CurrentMap')) {
                $this->WriteAttributeInteger('CurrentMap', $currentMap);
                // Other map, other rooms
                $this->SetBuffer('RoomsTime', '0');
                $this->SetBuffer('MapTime', '0');
            }
            if (@$this->GetIDForIdent('Map') !== false && $currentMap !== self::NO_MAP) {
                $this->SetValue('Map', $currentMap);
            }
        }

        if (isset($Status['dock_type'])) {
            $dockType = (int) $Status['dock_type'];
            if ($dockType !== $this->ReadAttributeInteger('DockType')) {
                $this->WriteAttributeInteger('DockType', $dockType);
                $this->RegisterVariables();
            }
        }
        if (isset($Status['dock_error_status']) && @$this->GetIDForIdent('DockError') !== false) {
            $this->SetValue('DockError', (int) $Status['dock_error_status']);
        }
        // Brush and filter times are also pushed via DPS
        foreach (RoborockCodes::CONSUMABLES as $field => [$ident, $caption, $lifetime]) {
            if (isset($Status[$field]) && $field !== 'sensor_dirty_time') {
                $this->SetValue($ident, max(0, (int) round(100 - $Status[$field] / $lifetime * 100)));
            }
        }
    }

    private function CleanModeFromValues(): int
    {
        $fan = (int) $this->GetValue('FanPower');
        $water = (int) $this->GetValue('WaterBoxMode');
        $mop = @$this->GetIDForIdent('MopMode') !== false ? (int) $this->GetValue('MopMode') : 0;
        if ($fan === 110 || $water === 209 || $mop === 306) {
            return self::MODE_SMART;
        }
        if ($fan === 106 || $water === 204 || $mop === 302) {
            return self::MODE_CUSTOM;
        }
        if ($fan === 105) {
            return self::MODE_MOP;
        }
        if ($water === 200) {
            return self::MODE_VACUUM;
        }
        return self::MODE_VACUUM_MOP;
    }

    private function ControlFromState(int $State): int
    {
        if ($State === 10) {
            return self::CONTROL_PAUSE;
        }
        if ($State === 11) {
            return self::CONTROL_SPOT;
        }
        if (in_array($State, [6, 8, 15, 22, 23, 25, 26, 100], true)) {
            return self::CONTROL_HOME;
        }
        if (in_array($State, [4, 5, 7, 16, 17, 18], true) || $State > 6300) {
            return self::CONTROL_START;
        }
        return self::CONTROL_STOP;
    }

    /**
     * Adds values of mode variables that are not part of the base options yet.
     */
    private function RememberValue(string $Ident, int $Value): void
    {
        $known = json_decode($this->ReadAttributeString('KnownValues'), true) ?: [];
        $values = $known[$Ident] ?? [];
        $isNew = !in_array($Value, $values, true);
        if ($isNew) {
            $values[] = $Value;
            sort($values);
            $known[$Ident] = $values;
            $this->WriteAttributeString('KnownValues', json_encode($known));
        }
        if ($isNew || @$this->GetIDForIdent($Ident) === false) {
            $this->RegisterModeVariable($Ident);
        }
    }

    private function SetMapImage(string $Png): void
    {
        $mediaID = @IPS_GetObjectIDByIdent('MapImage', $this->InstanceID);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, 'MapImage');
            IPS_SetName($mediaID, $this->Translate('Map'));
            IPS_SetPosition($mediaID, 5);
            IPS_SetMediaCached($mediaID, true);
            IPS_SetMediaFile($mediaID, 'media' . DIRECTORY_SEPARATOR . 'RoborockMap_' . $this->InstanceID . '.png', false);
        }
        IPS_SetMediaContent($mediaID, base64_encode($Png));
    }

    // -------------------------------------------------------------------------
    // Variables
    // -------------------------------------------------------------------------

    private function RegisterVariables(): void
    {
        $dock = $this->ReadAttributeInteger('DockType') > 0;

        $controls = [
            self::CONTROL_START       => ['Start', 'play'],
            self::CONTROL_PAUSE       => ['Pause', 'pause'],
            self::CONTROL_STOP        => ['Stop', 'stop'],
            self::CONTROL_HOME        => ['Return to dock', 'house'],
            self::CONTROL_SPOT        => ['Spot cleaning', 'bullseye'],
            self::CONTROL_LOCATE      => ['Locate', 'location-dot'],
            self::CONTROL_CLEAN_ROOMS => ['Clean selected rooms', 'door-open']
        ];
        if ($dock) {
            $controls[self::CONTROL_EMPTY_BIN] = ['Empty dust bin', 'trash-can'];
            $controls[self::CONTROL_WASH_MOP] = ['Wash mop', 'droplet'];
        }
        $options = [];
        foreach ($controls as $value => [$caption, $icon]) {
            $options[] = ['Value' => $value, 'Caption' => $this->Translate($caption), 'IconActive' => true, 'IconValue' => $icon, 'Color' => -1];
        }
        $this->RegisterVariableInteger('Control', $this->Translate('Control'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'robot',
            'LAYOUT'       => 1,
            'DISPLAY'      => 1,
            'OPTIONS'      => json_encode($options)
        ], 10);
        $this->EnableAction('Control');

        $this->RegisterVariableInteger('State', $this->Translate('State'), [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'             => 'robot',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => $this->BuildIntervals(RoborockCodes::STATES, RoborockCodes::STATE_ICONS)
        ], 20);

        $this->RegisterVariableString('CurrentRoom', $this->Translate('Current room'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'location-dot'
        ], 25);

        $this->RegisterVariableInteger('CloudConnection', $this->Translate('Cloud connection'), [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'             => 'cloud',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => $this->BuildIntervals(
                [0 => 'Disconnected', 1 => 'Connected', 2 => 'Not responding'],
                [0 => 'cloud-slash', 1 => 'cloud-check', 2 => 'cloud-exclamation']
            )
        ], 26);
        $this->RegisterVariableBoolean('LocalConnection', $this->Translate('Local connection'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'network-wired',
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('Not reachable'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => $this->Translate('Reachable'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1]
            ])
        ], 27);

        $this->RegisterVariableInteger('Battery', $this->Translate('Battery'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'TEMPLATE'     => VARIABLE_TEMPLATE_VALUE_PRESENTATION_BATTERY
        ], 30);

        $this->RegisterVariableInteger('Error', $this->Translate('Error'), [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'             => 'triangle-exclamation',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => $this->BuildIntervals(RoborockCodes::ERRORS, [])
        ], 40);

        $known = json_decode($this->ReadAttributeString('KnownValues'), true) ?: [];
        foreach (array_keys(self::BASE_OPTIONS) as $ident) {
            // Water and mop modes are only created when the device reports them
            if ($ident === 'FanPower' || isset($known[$ident])) {
                $this->RegisterModeVariable($ident);
            }
        }
        if (isset($known['WaterBoxMode'])) {
            $this->RegisterCleanModeVariable();
        }

        $this->RegisterMapVariables();
        $this->RegisterRoutineVariable();
        $this->RegisterProgramVariable();

        $this->RegisterVariableInteger('CleanTime', $this->Translate('Cleaning time'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DURATION,
            'FORMAT'       => 2
        ], 100);
        $this->RegisterVariableFloat('CleanArea', $this->Translate('Cleaned area'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' m²',
            'DIGITS'       => 1,
            'ICON'         => 'ruler-combined'
        ], 101);

        $this->MaintainVariable('DockError', $this->Translate('Dock error'), VARIABLETYPE_INTEGER, [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'             => 'triangle-exclamation',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => $this->BuildIntervals(RoborockCodes::DOCK_ERRORS, [])
        ], 110, $dock);

        $position = 200;
        foreach (RoborockCodes::CONSUMABLES as [$ident, $caption]) {
            $this->RegisterVariableInteger($ident, $this->Translate($caption), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' %',
                'MIN'          => 0,
                'MAX'          => 100,
                'ICON'         => 'screwdriver-wrench'
            ], $position++);
        }

        $this->RegisterVariableFloat('TotalCleanTime', $this->Translate('Total cleaning time'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' h',
            'DIGITS'       => 1,
            'ICON'         => 'clock'
        ], 300);
        $this->RegisterVariableFloat('TotalCleanArea', $this->Translate('Total cleaned area'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' m²',
            'DIGITS'       => 1,
            'ICON'         => 'ruler-combined'
        ], 310);
        $this->RegisterVariableInteger('TotalCleanCount', $this->Translate('Number of cleanings'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'hashtag'
        ], 320);
        $this->RegisterVariableInteger('LastClean', $this->Translate('Last cleaning'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'DATE'         => 1,
            'TIME'         => 1
        ], 330);
    }

    /**
     * Cleaning modes: value => [caption (translated), icon].
     */
    private function GetCleanModes(): array
    {
        $modes = [
            self::MODE_VACUUM      => ['Vacuum', 'broom'],
            self::MODE_VACUUM_MOP  => ['Vacuum and mop', 'broom-wide'],
            self::MODE_MOP         => ['Mop', 'droplet'],
            self::MODE_CUSTOM      => ['Custom', 'sliders']
        ];
        if ($this->ReadAttributeBoolean('SmartMode')) {
            $modes[self::MODE_SMART] = ['SmartPlan', 'wand-magic-sparkles'];
        }
        foreach ($modes as &$mode) {
            $mode[0] = $this->Translate($mode[0]);
        }
        return $modes;
    }

    private function RegisterCleanModeVariable(): void
    {
        $options = [];
        foreach ($this->GetCleanModes() as $value => [$caption, $icon]) {
            $options[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => true, 'IconValue' => $icon, 'Color' => -1];
        }
        $this->RegisterVariableInteger('CleanMode', $this->Translate('Cleaning mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'list-check',
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode($options)
        ], 45);
        $this->EnableAction('CleanMode');
    }

    private function RegisterModeVariable(string $Ident): void
    {
        [$caption, $icon, $position] = [
            'FanPower'     => ['Suction power', 'fan', 50],
            'WaterBoxMode' => ['Water flow', 'droplet', 60],
            'MopMode'      => ['Mop route', 'route', 70]
        ][$Ident];
        $options = [];
        foreach ($this->GetModeOptions($Ident) as $value => $text) {
            $options[] = ['Value' => $value, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->RegisterVariableInteger($Ident, $this->Translate($caption), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => $icon,
            'OPTIONS'      => json_encode($options)
        ], $position);
        $this->EnableAction($Ident);
    }

    /**
     * Options of a mode variable: value => caption (translated).
     */
    private function GetModeOptions(string $Ident): array
    {
        $known = json_decode($this->ReadAttributeString('KnownValues'), true) ?: [];
        $base = ($Ident === 'WaterBoxMode' && $this->ReadAttributeBoolean('WaterSlider')) ? self::BASE_OPTIONS_WATER_SLIDER : self::BASE_OPTIONS[$Ident];
        $values = array_unique(array_merge($base, $known[$Ident] ?? []));
        if ($Ident === 'WaterBoxMode' && $this->ReadAttributeBoolean('WaterSlider')) {
            // The classic levels 201-203 duplicate the slider levels (e.g. reported before the slider was detected)
            $values = array_filter($values, function ($value) {
                return $value < 201 || $value > 203;
            });
        }
        sort($values);

        switch ($Ident) {
            case 'FanPower':
                $table = RoborockCodes::FAN_POWER + RoborockCodes::FAN_POWER_LEGACY;
                // Older models only use percentages
                if (count(array_filter($known[$Ident] ?? [], function ($value) {
                    return $value <= 100;
                })) > 0) {
                    $values = array_unique(array_merge(array_keys(RoborockCodes::FAN_POWER_LEGACY), $known[$Ident]));
                    sort($values);
                }
                break;
            case 'WaterBoxMode':
                $table = RoborockCodes::WATER_BOX_MODES + RoborockCodes::WATER_SLIDER;
                break;
            default:
                $table = RoborockCodes::MOP_MODES;
                break;
        }
        $options = [];
        foreach ($values as $value) {
            if (isset($table[$value])) {
                $text = $this->Translate($table[$value]);
            } elseif ($Ident === 'WaterBoxMode' && $value > 220 && $value <= 250) {
                // Devices with a water slider report 220 + level
                $text = sprintf($this->Translate('Level %d'), $value - 220);
            } else {
                $text = (string) $value;
            }
            $options[$value] = $text;
        }
        return $options;
    }

    private function RegisterProgramVariable(): void
    {
        $programs = $this->GetPrograms();
        $options = [['Value' => 0, 'Caption' => '-', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]];
        foreach ($programs as $index => $program) {
            $options[] = ['Value' => $index + 1, 'Caption' => trim($program['Name']), 'IconActive' => true, 'IconValue' => 'play', 'Color' => -1];
        }
        $this->MaintainVariable('Program', $this->Translate('Start program'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'list-check',
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode($options)
        ], 12, count($programs) > 0);
        if (count($programs) > 0) {
            $this->EnableAction('Program');
        }
    }

    /**
     * Map selection, single room, passes and the selection switches of the rooms of the current map.
     */
    private function RegisterMapVariables(): void
    {
        $maps = json_decode($this->ReadAttributeString('Maps'), true) ?: [];
        $options = [];
        foreach ($maps as $flag => $map) {
            $options[] = ['Value' => (int) $flag, 'Caption' => $map['name'], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Map', $this->Translate('Map'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'layer-group',
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode($options)
        ], 75, count($maps) > 1);
        if (count($maps) > 1) {
            $this->EnableAction('Map');
            if ($this->ReadAttributeInteger('CurrentMap') >= 0) {
                $this->SetValue('Map', $this->ReadAttributeInteger('CurrentMap'));
            }
        }

        $rooms = json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
        $options = [['Value' => 0, 'Caption' => '-', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]];
        foreach ($rooms as $segment => $name) {
            $options[] = ['Value' => (int) $segment, 'Caption' => $name, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->RegisterVariableInteger('Room', $this->Translate('Clean room'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'door-open',
            'OPTIONS'      => json_encode($options)
        ], 80);
        $this->EnableAction('Room');

        if ($this->RegisterVariableInteger('Passes', $this->Translate('Passes'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'repeat',
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode([
                ['Value' => 1, 'Caption' => '1x', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 2, 'Caption' => '2x', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 3, 'Caption' => '3x', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]
            ])
        ], 81)) {
            $this->SetValue('Passes', 1);
        }
        $this->EnableAction('Passes');

        // Selection switches: one per room of the current map
        $position = 82;
        foreach ($rooms as $segment => $name) {
            $this->RegisterVariableBoolean('RoomSelect' . $segment, sprintf($this->Translate('Select %s'), $name), [
                'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
                'USE_ICON_FALSE' => true,
                'ICON_TRUE'      => 'square-check',
                'ICON_FALSE'     => 'square',
                'USAGE_TYPE'     => 2
            ], $position++);
            $this->EnableAction('RoomSelect' . $segment);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (str_starts_with($ident, 'RoomSelect') && !isset($rooms[substr($ident, strlen('RoomSelect'))])) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    private function RegisterRoutineVariable(): void
    {
        $routines = json_decode($this->ReadAttributeString('Routines'), true) ?: [];
        $options = [['Value' => 0, 'Caption' => '-', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]];
        foreach ($routines as $routine) {
            $options[] = ['Value' => (int) $routine['id'], 'Caption' => $routine['name'], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Routine', $this->Translate('Start routine'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'list-ol',
            'OPTIONS'      => json_encode($options)
        ], 95, count($routines) > 0);
        if (count($routines) > 0) {
            $this->EnableAction('Routine');
        }
    }

    /**
     * Text per code for read-only integer variables (an enumeration requires a variable action).
     */
    private function BuildIntervals(array $Table, array $Icons): string
    {
        $intervals = [];
        foreach ($Table as $value => $caption) {
            $intervals[] = [
                'IntervalMinValue' => $value,
                'IntervalMaxValue' => $value,
                'ConstantActive'   => true,
                'ConstantValue'    => $this->Translate($caption),
                'ConversionFactor' => 1,
                'PrefixActive'     => false,
                'PrefixValue'      => '',
                'SuffixActive'     => false,
                'SuffixValue'      => '',
                'DigitsActive'     => false,
                'DigitsValue'      => 0,
                'IconActive'       => isset($Icons[$value]),
                'IconValue'        => $Icons[$value] ?? '',
                'ColorActive'      => false,
                // The documentation names it "Color", the kernel only accepts "ColorValue"
                'ColorValue'       => -1
            ];
        }
        return json_encode($intervals);
    }
}
