<?php

declare(strict_types=1);

/**
 * Roborock message protocol (V1 "1.0" and local "L01") and a minimal MQTT 3.1.1 packet codec.
 * Based on python-roborock (roborock/protocol.py, roborock_message.py, protocols/v1_protocol.py).
 */
class RoborockProtocol
{
    public const SALT = 'TXdfu$jyZ#TZHsg4';

    public const LOCAL_PORT = 58867;

    public const PROTOCOL_HELLO_REQUEST = 0;
    public const PROTOCOL_HELLO_RESPONSE = 1;
    public const PROTOCOL_PING_REQUEST = 2;
    public const PROTOCOL_PING_RESPONSE = 3;
    public const PROTOCOL_GENERAL_REQUEST = 4;
    public const PROTOCOL_GENERAL_RESPONSE = 5;
    public const PROTOCOL_RPC_REQUEST = 101;
    public const PROTOCOL_RPC_RESPONSE = 102;
    public const PROTOCOL_MAP_RESPONSE = 301;

    // ---------------------------------------------------------------------
    // Encryption
    // ---------------------------------------------------------------------

    public static function EncodeTimestamp(int $Timestamp): string
    {
        $hex = str_pad(dechex($Timestamp), 8, '0', STR_PAD_LEFT);
        $result = '';
        foreach ([5, 6, 3, 7, 1, 2, 0, 4] as $index) {
            $result .= $hex[$index];
        }
        return $result;
    }

    public static function EncryptV1(string $Plain, int $Timestamp, string $LocalKey): string
    {
        $key = md5(self::EncodeTimestamp($Timestamp) . $LocalKey . self::SALT, true);
        $cipher = openssl_encrypt($Plain, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        if ($cipher === false) {
            throw new Exception('AES encryption failed');
        }
        return $cipher;
    }

    public static function DecryptV1(string $Cipher, int $Timestamp, string $LocalKey): string
    {
        $key = md5(self::EncodeTimestamp($Timestamp) . $LocalKey . self::SALT, true);
        $plain = openssl_decrypt($Cipher, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        if ($plain === false) {
            throw new Exception('AES decryption failed (wrong local key?)');
        }
        return $plain;
    }

    public static function EncryptL01(string $Plain, array $Frame, string $LocalKey, int $ConnectNonce, ?int $AckNonce): string
    {
        [$key, $iv, $aad] = self::L01Parameters($Frame, $LocalKey, $ConnectNonce, $AckNonce);
        $tag = '';
        $cipher = openssl_encrypt($Plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($cipher === false) {
            throw new Exception('AES-GCM encryption failed');
        }
        return $cipher . $tag;
    }

    public static function DecryptL01(string $Cipher, array $Frame, string $LocalKey, int $ConnectNonce, ?int $AckNonce): string
    {
        if (strlen($Cipher) < 16) {
            throw new Exception('L01 payload too short');
        }
        [$key, $iv, $aad] = self::L01Parameters($Frame, $LocalKey, $ConnectNonce, $AckNonce);
        $tag = substr($Cipher, -16);
        $plain = openssl_decrypt(substr($Cipher, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            throw new Exception('AES-GCM decryption failed');
        }
        return $plain;
    }

    private static function L01Parameters(array $Frame, string $LocalKey, int $ConnectNonce, ?int $AckNonce): array
    {
        $key = hash('sha256', self::EncodeTimestamp($Frame['timestamp']) . $LocalKey . self::SALT, true);
        $iv = substr(hash('sha256', pack('NNN', $Frame['seq'], $Frame['random'], $Frame['timestamp']), true), 0, 12);
        $aad = pack('N', $Frame['seq']) . pack('N', $ConnectNonce);
        if ($AckNonce !== null) {
            $aad .= pack('N', $AckNonce);
        }
        $aad .= pack('N', $Frame['random']) . pack('N', $Frame['timestamp']);
        return [$key, $iv, $aad];
    }

    // ---------------------------------------------------------------------
    // Frames
    // ---------------------------------------------------------------------

    /**
     * Builds a frame: version(3) seq(4) random(4) timestamp(4) protocol(2) [length(2) payload] crc32(4).
     * The payload must already be encrypted. A null payload omits the length field.
     */
    public static function BuildFrame(array $Frame, ?string $Payload): string
    {
        $data = $Frame['version'] . pack('NNNn', $Frame['seq'], $Frame['random'], $Frame['timestamp'], $Frame['protocol']);
        if ($Payload !== null) {
            $data .= pack('n', strlen($Payload)) . $Payload;
        }
        return $data . pack('N', crc32($data));
    }

    /**
     * Parses one frame without length prefix. Returns the header fields and the still encrypted payload.
     */
    public static function ParseFrame(string $Data): array
    {
        if (strlen($Data) < 17) {
            throw new Exception('Frame too short');
        }
        $header = unpack('Nseq/Nrandom/Ntimestamp/nprotocol', substr($Data, 3, 14));
        $frame = [
            'version'   => substr($Data, 0, 3),
            'seq'       => $header['seq'],
            'random'    => $header['random'],
            'timestamp' => $header['timestamp'],
            'protocol'  => $header['protocol'],
            'payload'   => ''
        ];
        // Frames without payload consist of the header and an optional CRC
        if (strlen($Data) <= 21) {
            return $frame;
        }
        $length = unpack('n', substr($Data, 17, 2))[1];
        if (strlen($Data) < 19 + $length) {
            throw new Exception('Frame payload incomplete');
        }
        $frame['payload'] = substr($Data, 19, $length);
        if (strlen($Data) >= 23 + $length) {
            $crc = unpack('N', substr($Data, 19 + $length, 4))[1];
            if ($crc !== crc32(substr($Data, 0, 19 + $length))) {
                throw new Exception('CRC mismatch');
            }
        }
        return $frame;
    }

    public static function NewFrame(string $Version, int $Protocol, ?int $Seq = null, ?int $Random = null): array
    {
        return [
            'version'   => $Version,
            'seq'       => $Seq ?? random_int(100000, 999999),
            'random'    => $Random ?? random_int(10000, 99999),
            'timestamp' => time(),
            'protocol'  => $Protocol
        ];
    }

    // ---------------------------------------------------------------------
    // V1 RPC payloads
    // ---------------------------------------------------------------------

    public static function BuildRpcPayload(int $RequestID, string $Method, $Params, int $Timestamp, ?array $Security): string
    {
        $inner = [
            'id'     => $RequestID,
            'method' => $Method,
            'params' => $Params ?? []
        ];
        if ($Security !== null) {
            $inner['security'] = $Security;
        }
        return json_encode([
            'dps' => ['101' => json_encode($inner, JSON_UNESCAPED_SLASHES)],
            't'   => $Timestamp
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decodes the decrypted JSON of a V1 message.
     * Returns ['rpc' => [id, result, error] | null, 'dps' => [code => value] (pushes)].
     */
    public static function DecodeV1Payload(string $Plain): array
    {
        $json = json_decode($Plain, true);
        $result = ['rpc' => null, 'dps' => []];
        if (!is_array($json) || !isset($json['dps']) || !is_array($json['dps'])) {
            return $result;
        }
        foreach ($json['dps'] as $code => $value) {
            if ((string) $code === '102' || (string) $code === '101') {
                $inner = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($inner) && isset($inner['id'])) {
                    $result['rpc'] = $inner;
                }
                continue;
            }
            $result['dps'][(string) $code] = $value;
        }
        return $result;
    }

    // ---------------------------------------------------------------------
    // Local TCP connection (port 58867)
    // ---------------------------------------------------------------------

    /**
     * Executes one RPC call over the local network. Opens and closes the connection.
     * $Version is '1.0', 'L01' or '' (try 1.0 first, then L01); it is updated with the working version.
     */
    public static function LocalRpc(string $Host, string $LocalKey, string $Method, $Params, string &$Version, float $Timeout = 5.0)
    {
        $versions = ($Version === '') ? ['1.0', 'L01'] : [$Version];
        $lastError = null;
        foreach ($versions as $tryVersion) {
            $socket = @stream_socket_client('tcp://' . $Host . ':' . self::LOCAL_PORT, $errno, $errstr, $Timeout);
            if ($socket === false) {
                throw new Exception(sprintf('Local connection to %s failed: %s', $Host, $errstr));
            }
            stream_set_timeout($socket, (int) ceil($Timeout));
            try {
                $connectNonce = random_int(10000, 32767);
                $hello = self::NewFrame($tryVersion, self::PROTOCOL_HELLO_REQUEST, 1, $connectNonce);
                self::LocalWrite($socket, self::BuildFrame($hello, null));
                $response = self::LocalReadFrame($socket, $Timeout);
                if ($response === null || $response['protocol'] !== self::PROTOCOL_HELLO_RESPONSE) {
                    $lastError = new Exception('No hello response for protocol ' . $tryVersion);
                    continue;
                }
                $ackNonce = $response['random'];
                $Version = $tryVersion;

                $requestID = random_int(10000, 32767);
                $frame = self::NewFrame($tryVersion, self::PROTOCOL_GENERAL_REQUEST);
                $plain = self::BuildRpcPayload($requestID, $Method, $Params, $frame['timestamp'], null);
                $payload = ($tryVersion === 'L01')
                    ? self::EncryptL01($plain, $frame, $LocalKey, $connectNonce, $ackNonce)
                    : self::EncryptV1($plain, $frame['timestamp'], $LocalKey);
                self::LocalWrite($socket, self::BuildFrame($frame, $payload));

                $deadline = microtime(true) + $Timeout;
                while (microtime(true) < $deadline) {
                    $response = self::LocalReadFrame($socket, $deadline - microtime(true));
                    if ($response === null) {
                        break;
                    }
                    if ($response['payload'] === '') {
                        continue;
                    }
                    $plain = ($tryVersion === 'L01')
                        ? self::DecryptL01($response['payload'], $response, $LocalKey, $connectNonce, $ackNonce)
                        : self::DecryptV1($response['payload'], $response['timestamp'], $LocalKey);
                    $decoded = self::DecodeV1Payload($plain);
                    if (($decoded['rpc']['id'] ?? null) === $requestID) {
                        return $decoded['rpc'];
                    }
                }
                throw new Exception('Timeout waiting for local response');
            } finally {
                fclose($socket);
            }
        }
        throw $lastError ?? new Exception('Local connection failed');
    }

    private static function LocalWrite($Socket, string $Frame): void
    {
        $data = pack('N', strlen($Frame)) . $Frame;
        if (fwrite($Socket, $data) !== strlen($data)) {
            throw new Exception('Writing to local connection failed');
        }
    }

    private static function LocalReadFrame($Socket, float $Timeout): ?array
    {
        $length = self::LocalReadBytes($Socket, 4, $Timeout);
        if ($length === null) {
            return null;
        }
        $length = unpack('N', $length)[1];
        if ($length < 17 || $length > 1048576) {
            throw new Exception('Invalid local frame length ' . $length);
        }
        $data = self::LocalReadBytes($Socket, $length, $Timeout);
        if ($data === null) {
            return null;
        }
        return self::ParseFrame($data);
    }

    private static function LocalReadBytes($Socket, int $Length, float $Timeout): ?string
    {
        $data = '';
        $deadline = microtime(true) + max($Timeout, 0.1);
        while (strlen($data) < $Length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read = [$Socket];
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1000000)) < 1) {
                return null;
            }
            $chunk = fread($Socket, $Length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($Socket))) {
                return null;
            }
            $data .= $chunk;
        }
        return $data;
    }

    // ---------------------------------------------------------------------
    // MQTT 3.1.1 packets
    // ---------------------------------------------------------------------

    public const MQTT_CONNECT = 1;
    public const MQTT_CONNACK = 2;
    public const MQTT_PUBLISH = 3;
    public const MQTT_SUBSCRIBE = 8;
    public const MQTT_SUBACK = 9;
    public const MQTT_PINGREQ = 12;
    public const MQTT_PINGRESP = 13;
    public const MQTT_DISCONNECT = 14;

    public static function MqttConnect(string $ClientID, string $Username, string $Password, int $KeepAlive): string
    {
        $variable = self::MqttString('MQTT') . chr(4) . chr(0xC2) . pack('n', $KeepAlive);
        $payload = self::MqttString($ClientID) . self::MqttString($Username) . self::MqttString($Password);
        return self::MqttPacket(0x10, $variable . $payload);
    }

    public static function MqttSubscribe(int $PacketID, array $Topics): string
    {
        $body = pack('n', $PacketID);
        foreach ($Topics as $topic) {
            $body .= self::MqttString($topic) . chr(0);
        }
        return self::MqttPacket(0x82, $body);
    }

    public static function MqttPublish(string $Topic, string $Payload): string
    {
        return self::MqttPacket(0x30, self::MqttString($Topic) . $Payload);
    }

    public static function MqttPing(): string
    {
        return chr(0xC0) . chr(0);
    }

    public static function MqttDisconnect(): string
    {
        return chr(0xE0) . chr(0);
    }

    /**
     * Extracts complete packets from the receive buffer.
     * Returns a list of ['type', 'flags', 'body'] and removes them from $Buffer.
     */
    public static function MqttParse(string &$Buffer): array
    {
        $packets = [];
        while (strlen($Buffer) >= 2) {
            $multiplier = 1;
            $length = 0;
            $position = 1;
            do {
                if ($position >= strlen($Buffer)) {
                    return $packets;
                }
                $byte = ord($Buffer[$position++]);
                $length += ($byte & 127) * $multiplier;
                $multiplier *= 128;
                if ($multiplier > 128 * 128 * 128 * 128) {
                    throw new Exception('Malformed MQTT length');
                }
            } while (($byte & 128) !== 0);
            if (strlen($Buffer) < $position + $length) {
                return $packets;
            }
            $first = ord($Buffer[0]);
            $packets[] = [
                'type'  => $first >> 4,
                'flags' => $first & 0x0F,
                'body'  => substr($Buffer, $position, $length)
            ];
            $Buffer = substr($Buffer, $position + $length);
        }
        return $packets;
    }

    /**
     * Splits the body of a PUBLISH packet into topic and payload.
     */
    public static function MqttDecodePublish(array $Packet): array
    {
        $body = $Packet['body'];
        $topicLength = unpack('n', substr($body, 0, 2))[1];
        $topic = substr($body, 2, $topicLength);
        $offset = 2 + $topicLength;
        $qos = ($Packet['flags'] >> 1) & 3;
        $packetID = 0;
        if ($qos > 0) {
            $packetID = unpack('n', substr($body, $offset, 2))[1];
            $offset += 2;
        }
        return ['topic' => $topic, 'payload' => substr($body, $offset), 'qos' => $qos, 'packetID' => $packetID];
    }

    public static function MqttPubAck(int $PacketID): string
    {
        return chr(0x40) . chr(2) . pack('n', $PacketID);
    }

    private static function MqttString(string $Value): string
    {
        return pack('n', strlen($Value)) . $Value;
    }

    private static function MqttPacket(int $Header, string $Body): string
    {
        $length = strlen($Body);
        $encoded = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $byte |= 128;
            }
            $encoded .= chr($byte);
        } while ($length > 0);
        return chr($Header) . $encoded . $Body;
    }
}
