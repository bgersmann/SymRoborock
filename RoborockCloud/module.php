<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoborockProtocol.php';

/**
 * Login, cloud API and MQTT connection of a Roborock account.
 *
 * The login flow, the Hawk authentication and the MQTT credentials are ported to PHP and modified from
 * python-roborock (roborock/web_api.py, roborock/mqtt), https://github.com/Python-roborock/python-roborock,
 * licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 */
class RoborockCloud extends IPSModuleStrict
{
    private const BASE_URLS = [
        'us' => 'https://usiot.roborock.com',
        'eu' => 'https://euiot.roborock.com',
        'cn' => 'https://cniot.roborock.com',
        'ru' => 'https://ruiot.roborock.com'
    ];

    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DATA_TO_IO = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const DATA_TO_CHILDREN = '{F28F0F64-9BD5-4036-9D8D-3B2918DDEB1F}';

    private const APP_VERSION = '4.54.02';
    private const MQTT_KEEPALIVE = 60;
    private const RPC_TIMEOUT = 10;
    private const LOCAL_HOST_LIFETIME = 43200;
    private const HOME_DATA_MIN_INTERVAL = 60;
    private const CLOUD_RECONNECT_INTERVAL = 300;

    // Connection state of the cloud for the children
    private const CLOUD_DISCONNECTED = 0;
    private const CLOUD_CONNECTED = 1;
    private const CLOUD_NOT_RESPONDING = 2;

    // Result of the local connection during the current RPC (null = not tried)
    private ?bool $localReachable = null;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('DeviceIdentifier', '');
        $this->RegisterAttributeString('Email', '');
        $this->RegisterAttributeString('BaseUrl', '');
        $this->RegisterAttributeString('UserData', '');
        $this->RegisterAttributeString('HomeData', '');
        $this->RegisterAttributeString('LocalHosts', '{}');
        $this->RegisterAttributeString('MqttClientID', '');

        $this->RegisterTimer('KeepAlive', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'KeepAlive\', 0);');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterParentStatus();

        if (!$this->IsLoggedIn()) {
            $this->SetTimerInterval('KeepAlive', 0);
            $this->SetMqttConnected(false);
            $this->SetSummary('');
            $this->SetStatus(104);
            return;
        }

        $this->SetSummary($this->MaskEmail($this->ReadAttributeString('Email')));
        $this->SetTimerInterval('KeepAlive', 30 * 1000);
        if ($this->UpdateParentStatus() && $this->HasActiveParent() && $this->GetBuffer('MqttConnected') !== '1') {
            $this->MqttConnect();
        }
    }

    /**
     * The broker depends on the account. The module must not change the client socket itself, so the user applies
     * the configuration of GetConfigurationForParent in the socket. Returns true if the socket is configured.
     */
    private function UpdateParentStatus(): bool
    {
        if (!$this->IsParentConfigured()) {
            $this->SetStatus(203);
            return false;
        }
        if ($this->GetStatus() !== 202) {
            $this->SetStatus(102);
        }
        return true;
    }

    private function IsParentConfigured(): bool
    {
        $parent = $this->GetParentID();
        if ($parent === 0 || !IPS_InstanceExists($parent)) {
            return false;
        }
        $configuration = json_decode(IPS_GetConfiguration($parent), true);
        foreach (json_decode($this->GetConfigurationForParent(), true) as $property => $value) {
            if (!array_key_exists($property, $configuration) || $configuration[$property] != $value) {
                return false;
            }
        }
        return true;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->RegisterParentStatus();
                if ($this->IsLoggedIn()) {
                    $this->UpdateParentStatus();
                }
                break;
            case IM_CHANGESTATUS:
            case IM_CHANGESETTINGS:
                if ($SenderID !== $this->GetParentID()) {
                    break;
                }
                // New connection of the socket: a new MQTT session is required
                $this->SetMqttConnected(false);
                $this->SetBuffer('MqttRx', '');
                if ($this->IsLoggedIn() && $this->UpdateParentStatus() && $this->HasActiveParent()) {
                    $this->MqttConnect();
                }
                break;
        }
    }

    public function GetCompatibleParents(): string
    {
        // The dialog for the new client socket explains that the broker is entered after the login
        return json_encode(['type' => 'require', 'modules' => [[
            'moduleID'     => self::CLIENT_SOCKET,
            'formOverride' => [
                'Open' => ['caption' => $this->Translate('Active (switched on after the login)')],
                'Host' => ['caption' => $this->Translate('Host (entered after the login in Roborock Cloud)')]
            ]
        ]]]);
    }

    public function GetConfigurationForParent(): string
    {
        $userData = $this->GetUserData();
        if ($userData === null) {
            // Before the login only the server is unknown, everything else is fixed
            return json_encode([
                'Host'       => '',
                'Port'       => 8883,
                'UseSSL'     => true,
                'VerifyPeer' => true,
                'VerifyHost' => true,
                'Open'       => false
            ]);
        }
        $broker = parse_url($userData['rriot']['r']['m'] ?? '');
        return json_encode([
            'Host'       => $broker['host'] ?? '',
            'Port'       => $broker['port'] ?? 8883,
            'UseSSL'     => ($broker['scheme'] ?? 'ssl') === 'ssl',
            'VerifyPeer' => true,
            'VerifyHost' => true,
            'Open'       => true
        ]);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $loggedIn = $this->IsLoggedIn();

        $form['elements'][0]['caption'] = $loggedIn
            ? sprintf($this->Translate('Logged in as %s'), $this->ReadAttributeString('Email'))
            : $this->Translate('Not logged in. Please log in with your Roborock account.');
        if ($loggedIn) {
            $home = $this->GetHomeData();
            $form['elements'][1]['caption'] = sprintf(
                $this->Translate('Home: %s, devices: %d, cloud connection: %s'),
                $home['name'] ?? '-',
                count($this->GetDeviceList()),
                $this->GetBuffer('MqttConnected') === '1' ? $this->Translate('connected') : $this->Translate('not connected')
            );
        } else {
            $form['elements'][1]['visible'] = false;
        }

        // Hint and button to apply the broker in the client socket
        $parent = $this->GetParentID();
        // Before the login the broker is unknown, the user is guided after the login
        $configured = ($parent > 0 && !$loggedIn) || $this->IsParentConfigured();
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'SocketHint') {
                $element['visible'] = !$configured;
                $element['caption'] = ($parent === 0)
                    ? $this->Translate('No interface (client socket) is connected. Please connect one via "Gateway".')
                    : $this->Translate('The interface (client socket) does not match the account yet. Please open it with the button below and click "Apply changes" there, the required values are already entered.');
            }
            if (($element['name'] ?? '') === 'SocketButton') {
                $element['visible'] = !$configured && $parent > 0;
                $element['objectID'] = $parent;
            }
        }
        unset($element);

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'LoginWizard') {
                // The wizard opens automatically as long as no account is set up
                $action['visible'] = !$loggedIn;
            }
            if (($action['name'] ?? '') === 'LogoutButton' || ($action['name'] ?? '') === 'RefreshButton') {
                $action['visible'] = $loggedIn;
            }
        }
        return json_encode($form);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'KeepAlive':
                $this->MqttKeepAlive();
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    // -------------------------------------------------------------------------
    // Login wizard
    // -------------------------------------------------------------------------

    public function UIOpenWizard(): void
    {
        $this->UpdateFormField('LoginWizard', 'visible', true);
    }

    /**
     * Wizard page "account": Determines the server and requests the verification code.
     * Returns an empty string on success, otherwise the error message.
     */
    public function UIRequestCode(string $Email, string $Region): string
    {
        $Email = trim($Email);
        if (!filter_var($Email, FILTER_VALIDATE_EMAIL)) {
            return $this->Translate('Please enter a valid email address');
        }
        try {
            $candidates = isset(self::BASE_URLS[$Region]) ? [self::BASE_URLS[$Region]] : array_values(self::BASE_URLS);
            $lastError = $this->Translate('No Roborock server found for this account');
            foreach ($candidates as $candidate) {
                $server = $this->DiscoverServer($Email, $candidate, isset(self::BASE_URLS[$Region]));
                if ($server === null) {
                    continue;
                }
                $response = $this->HttpRequest('POST', $server['url'] . '/api/v4/email/code/send', [
                    'header_clientid: ' . $this->GetClientIDHeader($Email),
                    'header_clientlang: en'
                ], ['email' => $Email, 'type' => 'login', 'platform' => '']);
                $code = $response['code'] ?? 0;
                if ($code === 200) {
                    $this->SetBuffer('WizardServer', json_encode($server + ['email' => $Email]));
                    return '';
                }
                if ($code === 3030) {
                    // Wrong server for this account, try the next one
                    continue;
                }
                $lastError = $this->DescribeApiError($response);
                break;
            }
            return $lastError;
        } catch (Exception $e) {
            $this->SendDebug('RequestCode', $e->getMessage(), 0);
            return $e->getMessage();
        }
    }

    /**
     * Wizard page "code": Logs in with the verification code and loads the home data.
     */
    public function UILogin(string $Email, string $Code): string
    {
        $server = json_decode($this->GetBuffer('WizardServer'), true);
        if (!is_array($server) || $server['email'] !== trim($Email)) {
            return $this->Translate('Please request a new verification code');
        }
        $Code = trim($Code);
        if ($Code === '') {
            return $this->Translate('Please enter the verification code');
        }
        try {
            $clientID = $this->GetClientIDHeader($server['email']);

            $ks = $this->RandomString(16, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
            $sign = $this->HttpRequest('POST', $server['url'] . '/api/v3/key/sign?s=' . $ks, ['header_clientid: ' . $clientID], []);
            if (($sign['code'] ?? 0) !== 200 || !isset($sign['data']['k'])) {
                return $this->DescribeApiError($sign);
            }

            $major = 14;
            $minor = 0;
            try {
                $agreement = $this->HttpRequest('GET', $server['url'] . '/api/v3/app/agreement/latest?country=' . urlencode((string) $server['country']), ['header_clientlang: en']);
                if (($agreement['code'] ?? 0) === 200) {
                    $major = (int) ($agreement['data']['majorVersion'] ?? $major);
                    $minor = (int) ($agreement['data']['minorVersion'] ?? $minor);
                }
            } catch (Exception $e) {
                $this->SendDebug('Agreement', $e->getMessage(), 0);
            }

            $response = $this->HttpRequest('POST', $server['url'] . '/api/v4/auth/email/login/code', [
                'header_clientid: ' . $clientID,
                'x-mercy-ks: ' . $ks,
                'x-mercy-k: ' . $sign['data']['k'],
                'header_clientlang: en',
                'header_appversion: ' . self::APP_VERSION,
                'header_phonesystem: iOS',
                'header_phonemodel: iPhone16,1'
            ], [
                'country'      => $server['country'],
                'countryCode'  => $server['countrycode'],
                'email'        => $server['email'],
                'code'         => $Code,
                'majorVersion' => $major,
                'minorVersion' => $minor
            ]);
            if (($response['code'] ?? 0) !== 200 || !isset($response['data']['rriot'])) {
                return $this->DescribeApiError($response);
            }
            $userData = $response['data'];
            $homeData = $this->FetchHomeData($userData, $server['url'], $server['email']);

            $this->SetBuffer('WizardResult', json_encode([
                'email'    => $server['email'],
                'url'      => $server['url'],
                'userData' => $userData,
                'homeData' => $homeData
            ]));
            return '';
        } catch (Exception $e) {
            $this->SendDebug('Login', $e->getMessage(), 0);
            return $e->getMessage();
        }
    }

    /**
     * onConfirm of the wizard page "code": Stores the session and connects to the cloud.
     */
    public function UIApplyLogin(): void
    {
        $result = json_decode($this->GetBuffer('WizardResult'), true);
        if (!is_array($result)) {
            return;
        }
        $this->WriteAttributeString('Email', $result['email']);
        $this->WriteAttributeString('BaseUrl', $result['url']);
        $this->WriteAttributeString('UserData', json_encode($result['userData']));
        $this->WriteAttributeString('HomeData', json_encode($result['homeData']));
        $this->WriteAttributeString('LocalHosts', '{}');
        $this->SetBuffer('HomeDataTime', (string) time());
        $this->SetBuffer('WizardResult', '');
        $this->SetBuffer('WizardServer', '');

        // A new login may change the MQTT credentials: close the old session, the socket reconnects
        if ($this->GetBuffer('MqttConnected') === '1') {
            $this->MqttSend(RoborockProtocol::MqttDisconnect());
            $this->SetMqttConnected(false);
        }
        // Connects the client socket with the broker of this account
        $this->ApplyChanges();
    }

    public function UIGetSummaryItems(): string
    {
        $items = [];
        $home = $this->GetHomeData();
        if (!$this->IsLoggedIn() || $home === null) {
            $items[] = ['type' => 'Label', 'caption' => $this->Translate('The login was not completed.')];
            return json_encode($items);
        }
        $items[] = ['type' => 'Label', 'caption' => sprintf($this->Translate('Logged in as %s'), $this->ReadAttributeString('Email'))];
        $items[] = ['type' => 'Label', 'caption' => sprintf($this->Translate('Home: %s'), $home['name'] ?? '-')];
        $values = [];
        foreach ($this->GetDeviceList() as $device) {
            $values[] = [
                'name'      => $device['name'],
                'model'     => $device['model'],
                'supported' => $device['supported'] ? $this->Translate('Yes') : $this->Translate('No')
            ];
        }
        $items[] = [
            'type'     => 'List',
            'name'     => 'WizardDevices',
            'caption'  => $this->Translate('Devices'),
            'rowCount' => max(1, min(8, count($values))),
            'columns'  => [
                ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                ['caption' => 'Model', 'name' => 'model', 'width' => '220px'],
                ['caption' => 'Supported', 'name' => 'supported', 'width' => '100px']
            ],
            'values' => $values
        ];
        if (!$this->IsParentConfigured() && $this->GetParentID() > 0) {
            $items[] = ['type' => 'Label', 'caption' => $this->Translate('Last step: Open the interface (client socket) and click "Apply changes" there. The address of the Roborock server is already entered.')];
            $items[] = ['type' => 'OpenObjectButton', 'caption' => $this->Translate('Configure interface'), 'objectID' => $this->GetParentID()];
        }
        $items[] = ['type' => 'Label', 'caption' => $this->Translate('Use the Roborock Configurator to create the vacuum instances.')];
        return json_encode($items);
    }

    public function UIFinish(): void
    {
        $this->ReloadForm();
    }

    public function Logout(): void
    {
        $this->WriteAttributeString('UserData', '');
        $this->WriteAttributeString('HomeData', '');
        $this->WriteAttributeString('LocalHosts', '{}');
        if ($this->HasActiveParent() && $this->GetBuffer('MqttConnected') === '1') {
            $this->MqttSend(RoborockProtocol::MqttDisconnect());
        }
        $this->ApplyChanges();
        $this->ReloadForm();
    }

    public function UpdateHomeData(): string
    {
        $userData = $this->GetUserData();
        if ($userData === null) {
            return $this->Translate('Not logged in');
        }
        try {
            $homeData = $this->FetchHomeData($userData, $this->ReadAttributeString('BaseUrl'), $this->ReadAttributeString('Email'));
        } catch (Exception $e) {
            return $e->getMessage();
        }
        $this->WriteAttributeString('HomeData', json_encode($homeData));
        $this->SetBuffer('HomeDataTime', (string) time());
        if ($this->GetBuffer('MqttConnected') === '1') {
            $this->MqttSubscribe();
        }
        return sprintf($this->Translate('%d devices found'), count($this->GetDeviceList()));
    }

    // -------------------------------------------------------------------------
    // Data flow
    // -------------------------------------------------------------------------

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        try {
            switch ($data['Command'] ?? '') {
                case 'GetDevices':
                    // The local keys stay in this instance
                    $devices = $this->GetDeviceList();
                    foreach ($devices as &$device) {
                        unset($device['localKey']);
                    }
                    return json_encode($devices);
                case 'GetDevice':
                    $device = $this->FindDevice((string) $data['DUID']);
                    if ($device === null) {
                        return json_encode(['error' => 'Unknown device']);
                    }
                    unset($device['localKey']);
                    return json_encode($device);
                case 'GetRooms':
                    if ($data['Refresh'] ?? false) {
                        $this->RefreshHomeDataIfAllowed();
                    }
                    $rooms = [];
                    foreach ($this->GetHomeData()['rooms'] ?? [] as $room) {
                        $rooms[(string) $room['id']] = $room['name'];
                    }
                    return json_encode($rooms);
                case 'RPC':
                    $result = $this->Rpc((string) $data['DUID'], (string) $data['Method'], $data['Params'] ?? [], (string) ($data['Mode'] ?? 'auto'), (string) ($data['Host'] ?? ''));
                    return json_encode($result + ['cloud' => $this->GetCloudState(), 'local' => $this->localReachable]);
                case 'GetConnection':
                    return json_encode(['cloud' => $this->GetCloudState()]);
                case 'GetMap':
                    // Maps are only delivered via MQTT; the result is the gzip compressed map as base64
                    $device = $this->FindDevice((string) $data['DUID']);
                    if ($device === null) {
                        throw new Exception('Unknown device');
                    }
                    return json_encode($this->CloudRpc((string) $data['DUID'], $device['localKey'], 'get_map_v1', [], true));
                case 'GetRoutines':
                    $response = $this->HawkRequest($this->RequireUserData(), '/user/scene/device/' . rawurlencode((string) $data['DUID']));
                    if (!($response['success'] ?? false)) {
                        throw new Exception('Routines could not be loaded: ' . json_encode($response));
                    }
                    $routines = [];
                    foreach ($response['result'] ?? [] as $routine) {
                        if ($routine['enabled'] ?? true) {
                            $routines[] = ['id' => (int) $routine['id'], 'name' => (string) $routine['name']];
                        }
                    }
                    return json_encode($routines);
                case 'RunRoutine':
                    $response = $this->HawkRequest($this->RequireUserData(), '/user/scene/' . (int) $data['RoutineID'] . '/execute', [], 'POST');
                    if (!($response['success'] ?? false)) {
                        throw new Exception('Routine could not be started: ' . json_encode($response));
                    }
                    return json_encode(['result' => 'ok']);
                default:
                    throw new Exception('Unknown command');
            }
        } catch (Exception $e) {
            $this->SendDebug('ForwardData', $e->getMessage(), 0);
            return json_encode(['error' => ['message' => $e->getMessage()], 'cloud' => $this->GetCloudState(), 'local' => $this->localReachable]);
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $chunk = hex2bin($data['Buffer'] ?? '');
        if ($chunk === false || $chunk === '') {
            return '';
        }

        if (!IPS_SemaphoreEnter('RRC_Rx_' . $this->InstanceID, 5000)) {
            return '';
        }
        try {
            $buffer = hex2bin($this->GetBuffer('MqttRx')) . $chunk;
            $packets = RoborockProtocol::MqttParse($buffer);
            $this->SetBuffer('MqttRx', bin2hex($buffer));
        } catch (Exception $e) {
            $this->SendDebug('MQTT', 'Parse error: ' . $e->getMessage(), 0);
            $this->SetBuffer('MqttRx', '');
            $packets = [];
        } finally {
            IPS_SemaphoreLeave('RRC_Rx_' . $this->InstanceID);
        }

        $this->SetBuffer('MqttLastRx', (string) time());
        foreach ($packets as $packet) {
            $this->HandleMqttPacket($packet);
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // MQTT
    // -------------------------------------------------------------------------

    private function MqttConnect(): void
    {
        $userData = $this->GetUserData();
        if ($userData === null || !$this->HasActiveParent()) {
            return;
        }
        $clientID = $this->ReadAttributeString('MqttClientID');
        if ($clientID === '') {
            $clientID = 'symcon_' . bin2hex(random_bytes(8));
            $this->WriteAttributeString('MqttClientID', $clientID);
        }
        [$username, $password] = $this->GetMqttCredentials($userData);
        $this->SetBuffer('MqttRx', '');
        $this->SetBuffer('MqttConnectTime', (string) time());
        $this->SendDebug('MQTT', 'Connect as ' . $username, 0);
        $this->MqttSend(RoborockProtocol::MqttConnect($clientID, $username, $password, self::MQTT_KEEPALIVE));
    }

    private function MqttSubscribe(): void
    {
        $userData = $this->GetUserData();
        if ($userData === null) {
            return;
        }
        $topics = [];
        foreach ($this->GetDeviceList() as $device) {
            $topics[] = $this->GetMqttTopic($userData, 'o', $device['duid']);
        }
        if (count($topics) > 0) {
            $this->MqttSend(RoborockProtocol::MqttSubscribe(random_int(1, 65535), $topics));
        }
    }

    private function MqttKeepAlive(): void
    {
        if (!$this->IsLoggedIn()) {
            return;
        }
        if (!$this->HasActiveParent()) {
            // The client socket reconnects by itself (event control)
            return;
        }
        if ($this->GetBuffer('MqttConnected') !== '1') {
            // Retry the connection if the last attempt is older than 60 seconds
            if (time() - (int) $this->GetBuffer('MqttConnectTime') > 60) {
                $this->MqttConnect();
            }
            return;
        }
        if (time() - (int) $this->GetBuffer('MqttLastRx') > 2 * self::MQTT_KEEPALIVE) {
            $this->SendDebug('MQTT', 'No response from broker, reconnecting', 0);
            $this->SetMqttConnected(false);
            $this->MqttSend(RoborockProtocol::MqttDisconnect());
            return;
        }
        $this->MqttSend(RoborockProtocol::MqttPing());
    }

    private function HandleMqttPacket(array $Packet): void
    {
        switch ($Packet['type']) {
            case RoborockProtocol::MQTT_CONNACK:
                $returnCode = ord($Packet['body'][1] ?? "\xFF");
                if ($returnCode === 0) {
                    $this->SendDebug('MQTT', 'Connected', 0);
                    $this->SetMqttConnected(true);
                    $this->MqttSubscribe();
                    if ($this->GetStatus() === 202) {
                        $this->SetStatus(102);
                    }
                } else {
                    $this->SendDebug('MQTT', 'Connection refused, code ' . $returnCode, 0);
                    $this->LogMessage(sprintf($this->Translate('Cloud connection refused (code %d)'), $returnCode), KL_WARNING);
                    $this->SetStatus(202);
                }
                break;
            case RoborockProtocol::MQTT_PUBLISH:
                $publish = RoborockProtocol::MqttDecodePublish($Packet);
                if ($publish['qos'] === 1) {
                    $this->MqttSend(RoborockProtocol::MqttPubAck($publish['packetID']));
                }
                $parts = explode('/', $publish['topic']);
                $this->HandleDeviceMessage((string) end($parts), $publish['payload']);
                break;
            case RoborockProtocol::MQTT_SUBACK:
                $this->SendDebug('MQTT', 'Subscribed', 0);
                break;
            case RoborockProtocol::MQTT_PINGRESP:
                break;
            default:
                $this->SendDebug('MQTT', 'Unhandled packet type ' . $Packet['type'], 0);
        }
    }

    private function HandleDeviceMessage(string $DUID, string $Payload): void
    {
        $device = $this->FindDevice($DUID);
        if ($device === null) {
            return;
        }
        try {
            $frame = RoborockProtocol::ParseFrame($Payload);
            if ($frame['version'] !== '1.0' || $frame['payload'] === '') {
                return;
            }
            $plain = RoborockProtocol::DecryptV1($frame['payload'], $frame['timestamp'], $device['localKey']);
            if ($frame['protocol'] === RoborockProtocol::PROTOCOL_MAP_RESPONSE) {
                $this->HandleMapResponse($plain);
                return;
            }
        } catch (Exception $e) {
            $this->SendDebug('MQTT', $DUID . ': ' . $e->getMessage(), 0);
            return;
        }
        $this->SendDebug('MQTT ' . $DUID, RoborockProtocol::MaskNetworkInfo($plain), 0);
        $decoded = RoborockProtocol::DecodeV1Payload($plain);
        if ($decoded['rpc'] !== null) {
            $this->StoreRpcResponse((string) $decoded['rpc']['id'], $decoded['rpc']);
        }
        if (count($decoded['dps']) > 0) {
            $this->SendDataToChildren(json_encode([
                'DataID' => self::DATA_TO_CHILDREN,
                'DUID'   => $DUID,
                'Type'   => 'dps',
                'Data'   => $decoded['dps']
            ]));
        }
    }

    /**
     * Map response (protocol 301): endpoint(8) unknown(8) request id(uint16 LE) unknown(6), then AES-CBC with the session nonce.
     */
    private function HandleMapResponse(string $Payload): void
    {
        $userData = $this->GetUserData();
        if ($userData === null || strlen($Payload) < 24) {
            return;
        }
        if (strncmp($Payload, $this->GetEndpoint($userData), 8) !== 0) {
            $this->SendDebug('Map', 'Response for another client, ignored', 0);
            return;
        }
        $requestID = unpack('v', substr($Payload, 16, 2))[1];
        $gzip = openssl_decrypt(substr($Payload, 24), 'aes-128-cbc', hex2bin($this->GetBuffer('Nonce')), OPENSSL_RAW_DATA, str_repeat("\0", 16));
        if ($gzip === false) {
            $this->SendDebug('Map', 'Decryption failed', 0);
            return;
        }
        $this->SendDebug('Map', sprintf('Request %d, %d bytes', $requestID, strlen($gzip)), 0);
        $this->StoreRpcResponse('map' . $requestID, ['map' => base64_encode($gzip)]);
    }

    private function GetEndpoint(array $UserData): string
    {
        return base64_encode(substr(md5($UserData['rriot']['k'], true), 8, 6));
    }

    private function MqttSend(string $Packet): void
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDataToParent(json_encode(['DataID' => self::DATA_TO_IO, 'Buffer' => bin2hex($Packet)]));
    }

    private function SetMqttConnected(bool $Connected): void
    {
        $this->SetBuffer('MqttConnected', $Connected ? '1' : '0');
        if ($Connected) {
            $this->SetBuffer('CloudTimeouts', '0');
            $this->SetBuffer('MqttLastRx', (string) time());
            $this->SetBuffer('Nonce', bin2hex(random_bytes(16)));
        }
    }

    private function GetMqttCredentials(array $UserData): array
    {
        $rriot = $UserData['rriot'];
        return [
            substr(md5($rriot['u'] . ':' . $rriot['k']), 2, 8),
            substr(md5($rriot['s'] . ':' . $rriot['k']), 16)
        ];
    }

    private function GetMqttTopic(array $UserData, string $Direction, string $DUID): string
    {
        [$username] = $this->GetMqttCredentials($UserData);
        return 'rr/m/' . $Direction . '/' . $UserData['rriot']['u'] . '/' . $username . '/' . $DUID;
    }

    // -------------------------------------------------------------------------
    // RPC
    // -------------------------------------------------------------------------

    private function Rpc(string $DUID, string $Method, mixed $Params, string $Mode, string $Host): array
    {
        $device = $this->FindDevice($DUID);
        if ($device === null) {
            throw new Exception('Unknown device ' . $DUID);
        }
        if (!$device['supported']) {
            throw new Exception(sprintf('Protocol %s of this device is not supported', $device['pv']));
        }

        if ($Mode !== 'cloud') {
            if ($Host === '') {
                $Host = $this->GetLocalHost($DUID);
            }
            if ($Host !== '') {
                try {
                    $response = $this->LocalRpc($DUID, $Host, $device['localKey'], $Method, $Params);
                    $this->localReachable = true;
                    return $response;
                } catch (Exception $e) {
                    $this->SendDebug('Local', $DUID . ': ' . $e->getMessage(), 0);
                    $this->localReachable = false;
                    $this->ForgetLocalHost($DUID);
                    if ($Mode === 'local') {
                        throw $e;
                    }
                }
            } elseif ($Mode === 'local') {
                throw new Exception('The IP address of the device is unknown');
            }
        }
        return $this->CloudRpc($DUID, $device['localKey'], $Method, $Params);
    }

    private function LocalRpc(string $DUID, string $Host, string $LocalKey, string $Method, mixed $Params): array
    {
        $semaphore = 'RRC_Local_' . $this->InstanceID . '_' . $DUID;
        if (!IPS_SemaphoreEnter($semaphore, 15000)) {
            throw new Exception('Local connection is busy');
        }
        try {
            $version = $this->GetBuffer('LocalVersion_' . $DUID);
            $this->SendDebug('Local ' . $DUID, $Method . ' ' . json_encode($Params), 0);
            $response = RoborockProtocol::LocalRpc($Host, $LocalKey, $Method, $Params, $version);
            $this->SetBuffer('LocalVersion_' . $DUID, $version);
            $this->SendDebug('Local ' . $DUID, RoborockProtocol::MaskNetworkInfo(json_encode($response)), 0);
            return $response;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function CloudRpc(string $DUID, string $LocalKey, string $Method, mixed $Params, bool $MapResponse = false): array
    {
        $userData = $this->GetUserData();
        if ($userData === null) {
            throw new Exception('Not logged in');
        }
        if ($this->GetBuffer('MqttConnected') !== '1') {
            throw new Exception('Cloud connection is not established');
        }
        $requestID = random_int(10000, 32767);
        $frame = RoborockProtocol::NewFrame('1.0', RoborockProtocol::PROTOCOL_RPC_REQUEST);
        $security = [
            'endpoint' => $this->GetEndpoint($userData),
            'nonce'    => $this->GetBuffer('Nonce')
        ];
        $plain = RoborockProtocol::BuildRpcPayload($requestID, $Method, $Params, $frame['timestamp'], $security);
        $payload = RoborockProtocol::EncryptV1($plain, $frame['timestamp'], $LocalKey);

        $this->SendDebug('Cloud ' . $DUID, $Method . ' ' . json_encode($Params), 0);
        $this->MqttSend(RoborockProtocol::MqttPublish($this->GetMqttTopic($userData, 'i', $DUID), RoborockProtocol::BuildFrame($frame, $payload)));

        $deadline = microtime(true) + ($MapResponse ? 2 * self::RPC_TIMEOUT : self::RPC_TIMEOUT);
        while (microtime(true) < $deadline) {
            $response = $this->TakeRpcResponse(($MapResponse ? 'map' : '') . $requestID);
            if ($response !== null) {
                $this->SetBuffer('CloudTimeouts', '0');
                return $response;
            }
            IPS_Sleep(50);
        }
        $this->HandleCloudTimeout();
        throw new Exception('Timeout waiting for the cloud response to ' . $Method);
    }

    /**
     * The broker sometimes keeps the connection open but stops delivering messages for hours.
     * A new session (DISCONNECT, the socket reconnects) fixes this.
     */
    private function HandleCloudTimeout(): void
    {
        $this->SetBuffer('CloudTimeouts', (string) ((int) $this->GetBuffer('CloudTimeouts') + 1));
        if (time() - (int) $this->GetBuffer('CloudReconnectTime') < self::CLOUD_RECONNECT_INTERVAL) {
            return;
        }
        $this->SetBuffer('CloudReconnectTime', (string) time());
        $this->SendDebug('MQTT', 'Cloud does not respond, starting a new session', 0);
        $this->MqttSend(RoborockProtocol::MqttDisconnect());
        $this->SetMqttConnected(false);
    }

    private function GetCloudState(): int
    {
        if ($this->GetBuffer('MqttConnected') !== '1') {
            return self::CLOUD_DISCONNECTED;
        }
        return ((int) $this->GetBuffer('CloudTimeouts') > 0) ? self::CLOUD_NOT_RESPONDING : self::CLOUD_CONNECTED;
    }

    private function StoreRpcResponse(string $RequestID, array $Response): void
    {
        if (!IPS_SemaphoreEnter('RRC_Rpc_' . $this->InstanceID, 2000)) {
            return;
        }
        $responses = json_decode($this->GetBuffer('RpcResponses'), true) ?: [];
        // Drop responses nobody waits for anymore
        $responses = array_filter($responses, function ($entry) {
            return time() - $entry['time'] < 2 * self::RPC_TIMEOUT;
        });
        $responses[(string) $RequestID] = ['time' => time(), 'response' => $Response];
        $this->SetBuffer('RpcResponses', json_encode($responses));
        IPS_SemaphoreLeave('RRC_Rpc_' . $this->InstanceID);
    }

    private function TakeRpcResponse(string $RequestID): ?array
    {
        $responses = json_decode($this->GetBuffer('RpcResponses'), true) ?: [];
        if (!isset($responses[(string) $RequestID])) {
            return null;
        }
        if (!IPS_SemaphoreEnter('RRC_Rpc_' . $this->InstanceID, 2000)) {
            return null;
        }
        $responses = json_decode($this->GetBuffer('RpcResponses'), true) ?: [];
        $response = $responses[(string) $RequestID]['response'] ?? null;
        unset($responses[(string) $RequestID]);
        $this->SetBuffer('RpcResponses', json_encode($responses));
        IPS_SemaphoreLeave('RRC_Rpc_' . $this->InstanceID);
        return $response;
    }

    private function GetLocalHost(string $DUID): string
    {
        $hosts = json_decode($this->ReadAttributeString('LocalHosts'), true) ?: [];
        if (isset($hosts[$DUID]) && !RoborockProtocol::IsPrivateIP((string) $hosts[$DUID]['ip'])) {
            unset($hosts[$DUID]);
        }
        if (isset($hosts[$DUID]) && (time() - $hosts[$DUID]['time']) < self::LOCAL_HOST_LIFETIME) {
            return $hosts[$DUID]['ip'];
        }
        // Do not query the cloud more than every 10 minutes
        if (time() - (int) $this->GetBuffer('NetworkQuery_' . $DUID) < 600) {
            return $hosts[$DUID]['ip'] ?? '';
        }
        $this->SetBuffer('NetworkQuery_' . $DUID, (string) time());
        $device = $this->FindDevice($DUID);
        try {
            $response = $this->CloudRpc($DUID, $device['localKey'], 'get_network_info', []);
        } catch (Exception $e) {
            // Keep using the last known address, the cloud is only needed to detect a new one
            $this->SendDebug('NetworkInfo', $e->getMessage(), 0);
            return $hosts[$DUID]['ip'] ?? '';
        }
        $ip = (string) ($response['result']['ip'] ?? '');
        if ($ip !== '' && !RoborockProtocol::IsPrivateIP($ip)) {
            $this->SendDebug('NetworkInfo', 'Ignoring an address outside of the local network', 0);
            return $hosts[$DUID]['ip'] ?? '';
        }
        if ($ip !== '') {
            $hosts[$DUID] = ['ip' => $ip, 'time' => time()];
            $this->WriteAttributeString('LocalHosts', json_encode($hosts));
        }
        return $ip;
    }

    private function ForgetLocalHost(string $DUID): void
    {
        $hosts = json_decode($this->ReadAttributeString('LocalHosts'), true) ?: [];
        if (isset($hosts[$DUID])) {
            $hosts[$DUID]['time'] = 0;
            $this->WriteAttributeString('LocalHosts', json_encode($hosts));
        }
    }

    // -------------------------------------------------------------------------
    // Cloud API
    // -------------------------------------------------------------------------

    private function DiscoverServer(string $Email, string $BaseUrl, bool $FixedRegion): ?array
    {
        $response = $this->HttpRequest('POST', $BaseUrl . '/api/v1/getUrlByEmail?' . http_build_query(['email' => $Email, 'needtwostepauth' => 'false']), [], []);
        if (($response['code'] ?? 0) !== 200) {
            if (($response['code'] ?? 0) === 2003) {
                throw new Exception($this->Translate('Please enter a valid email address'));
            }
            return null;
        }
        $data = $response['data'] ?? [];
        if (($data['country'] ?? null) === null && ($data['countrycode'] ?? null) === null) {
            return null;
        }
        // The server of the account must be a Roborock server, otherwise the known base URL is kept
        $url = rtrim((string) ($data['url'] ?? ''), '/');
        if ($FixedRegion || !$this->IsRoborockUrl($url)) {
            $url = $BaseUrl;
        }
        return [
            'url'         => $url,
            'country'     => (string) ($data['country'] ?? ''),
            'countrycode' => (string) ($data['countrycode'] ?? '')
        ];
    }

    private function FetchHomeData(array $UserData, string $BaseUrl, string $Email): array
    {
        $response = $this->HttpRequest('GET', $BaseUrl . '/api/v1/getHomeDetail', [
            'header_clientid: ' . $this->GetClientIDHeader($Email),
            'Authorization: ' . $UserData['token']
        ]);
        if (($response['code'] ?? 0) === 2010) {
            $this->SetStatus(201);
            throw new Exception($this->Translate('The session has expired. Please log in again.'));
        }
        if (($response['code'] ?? 0) !== 200 || !isset($response['data']['rrHomeId'])) {
            throw new Exception($this->DescribeApiError($response));
        }
        $homeID = $response['data']['rrHomeId'];
        foreach (['/v3/user/homes/', '/v2/user/homes/'] as $path) {
            $home = $this->HawkRequest($UserData, $path . $homeID);
            if (($home['success'] ?? false) && is_array($home['result'] ?? null)) {
                return $home['result'];
            }
        }
        throw new Exception($this->Translate('The home data could not be loaded'));
    }

    private function RefreshHomeDataIfAllowed(): void
    {
        if (time() - (int) $this->GetBuffer('HomeDataTime') < self::HOME_DATA_MIN_INTERVAL) {
            return;
        }
        $this->UpdateHomeData();
    }

    private function HawkRequest(array $UserData, string $Path, array $Params = [], string $Method = 'GET'): array
    {
        $rriot = $UserData['rriot'];
        $timestamp = time();
        $nonce = $this->RandomString(8, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_');
        $paramsHash = '';
        if (count($Params) > 0) {
            ksort($Params);
            $pairs = [];
            foreach ($Params as $key => $value) {
                $pairs[] = $key . '=' . $value;
            }
            $paramsHash = md5(implode('&', $pairs));
        }
        $prestr = implode(':', [$rriot['u'], $rriot['s'], $nonce, (string) $timestamp, md5($Path), $paramsHash, '']);
        $mac = base64_encode(hash_hmac('sha256', $prestr, $rriot['h'], true));
        $api = rtrim((string) ($rriot['r']['a'] ?? ''), '/');
        if (!$this->IsRoborockUrl($api)) {
            throw new Exception('Invalid API server of the account');
        }
        $url = $api . $Path . (count($Params) > 0 ? '?' . http_build_query($Params) : '');
        return $this->HttpRequest($Method, $url, [
            sprintf('Authorization: Hawk id="%s",s="%s",ts="%d",nonce="%s",mac="%s"', $rriot['u'], $rriot['s'], $timestamp, $nonce, $mac)
        ]);
    }

    private function HttpRequest(string $Method, string $Url, array $Headers, ?array $Form = null): array
    {
        if (!$this->IsRoborockUrl($Url)) {
            throw new Exception('Only Roborock servers are allowed');
        }
        $this->SendDebug('HTTP', $Method . ' ' . preg_replace('/email=[^&]+/', 'email=***', $Url), 0);
        $curl = curl_init($Url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $Method,
            CURLOPT_HTTPHEADER     => $Headers
        ]);
        // HTTPS only, also for redirects (CURLOPT_PROTOCOLS_STR replaces the deprecated bitmask options)
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        } else {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($Form !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($Form));
        }
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new Exception(sprintf($this->Translate('Connection to the Roborock cloud failed: %s'), $error));
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new Exception(sprintf($this->Translate('Invalid response from the Roborock cloud (HTTP %d)'), $httpCode));
        }
        $this->SendDebug('HTTP', 'HTTP ' . $httpCode . ', code ' . ($json['code'] ?? '-') . ', ' . ($json['msg'] ?? ''), 0);
        return $json;
    }

    private function DescribeApiError(array $Response): string
    {
        $code = $Response['code'] ?? 0;
        $messages = [
            2008 => 'This Roborock account does not exist',
            2018 => 'The verification code is invalid',
            3006 => 'Please accept the current user agreement in the Roborock app (Mi Home accounts are not supported)',
            3009 => 'Please accept the user agreement in the Roborock app',
            3039 => 'This account does not exist in the selected region',
            9002 => 'Too many requests, please try again later'
        ];
        if (isset($messages[$code])) {
            return $this->Translate($messages[$code]);
        }
        return sprintf($this->Translate('Roborock cloud error %s: %s'), (string) $code, (string) ($Response['msg'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function IsRoborockUrl(string $Url): bool
    {
        $parts = parse_url($Url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        return $host === 'roborock.com' || str_ends_with($host, '.roborock.com');
    }

    private function MaskEmail(string $Email): string
    {
        $at = strpos($Email, '@');
        if ($at === false || $at === 0) {
            return $Email === '' ? '' : '***';
        }
        return substr($Email, 0, 1) . '***' . substr($Email, $at);
    }

    private function IsLoggedIn(): bool
    {
        return $this->GetUserData() !== null;
    }

    private function RequireUserData(): array
    {
        $userData = $this->GetUserData();
        if ($userData === null) {
            throw new Exception('Not logged in');
        }
        return $userData;
    }

    private function GetUserData(): ?array
    {
        $userData = json_decode($this->ReadAttributeString('UserData'), true);
        return (is_array($userData) && isset($userData['rriot'])) ? $userData : null;
    }

    private function GetHomeData(): ?array
    {
        $homeData = json_decode($this->ReadAttributeString('HomeData'), true);
        return is_array($homeData) ? $homeData : null;
    }

    private function GetDeviceList(): array
    {
        $home = $this->GetHomeData();
        if ($home === null) {
            return [];
        }
        $products = [];
        foreach ($home['products'] ?? [] as $product) {
            $products[$product['id']] = $product;
        }
        $devices = [];
        foreach (array_merge($home['devices'] ?? [], $home['receivedDevices'] ?? []) as $device) {
            $product = $products[$device['productId'] ?? ''] ?? [];
            $pv = (string) ($device['pv'] ?? '1.0');
            $category = (string) ($product['category'] ?? '');
            $devices[] = [
                'duid'        => (string) $device['duid'],
                'name'        => (string) ($device['name'] ?? $device['duid']),
                'localKey'    => (string) ($device['localKey'] ?? ''),
                'pv'          => $pv,
                'model'       => (string) ($product['model'] ?? ''),
                'productName' => (string) ($product['name'] ?? ''),
                'category'    => $category,
                'firmware'    => (string) ($device['fv'] ?? ''),
                'serial'      => (string) ($device['sn'] ?? ''),
                'online'      => (bool) ($device['online'] ?? false),
                'shared'      => (bool) ($device['share'] ?? false),
                'supported'   => $pv === '1.0' && ($category === '' || $category === 'robot.vacuum.cleaner')
            ];
        }
        return $devices;
    }

    private function FindDevice(string $DUID): ?array
    {
        foreach ($this->GetDeviceList() as $device) {
            if ($device['duid'] === $DUID) {
                return $device;
            }
        }
        return null;
    }

    private function GetClientIDHeader(string $Email): string
    {
        $identifier = $this->ReadAttributeString('DeviceIdentifier');
        if ($identifier === '') {
            $identifier = $this->RandomString(22, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_');
            $this->WriteAttributeString('DeviceIdentifier', $identifier);
        }
        return base64_encode(md5($Email . $identifier, true));
    }

    private function RandomString(int $Length, string $Alphabet): string
    {
        $result = '';
        for ($i = 0; $i < $Length; $i++) {
            $result .= $Alphabet[random_int(0, strlen($Alphabet) - 1)];
        }
        return $result;
    }

    private function GetParentID(): int
    {
        return IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function RegisterParentStatus(): void
    {
        $oldParent = (int) $this->GetBuffer('ParentID');
        $parent = $this->GetParentID();
        if ($oldParent === $parent) {
            return;
        }
        if ($oldParent > 0) {
            $this->UnregisterMessage($oldParent, IM_CHANGESTATUS);
            $this->UnregisterMessage($oldParent, IM_CHANGESETTINGS);
        }
        if ($parent > 0) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
            // The user applies the configuration of the socket, it reconnects without changing its status
            $this->RegisterMessage($parent, IM_CHANGESETTINGS);
        }
        $this->SetBuffer('ParentID', (string) $parent);
    }

}
