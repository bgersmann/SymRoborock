<?php

declare(strict_types=1);

class RoborockConfigurator extends IPSModuleStrict
{
    private const DATA_TO_CLOUD = '{5028C495-ECD8-40BE-9DFB-23CA2C094D00}';
    private const VACUUM_MODULE = '{107CA736-DCB6-41EC-8F73-6ACD2E5E2178}';

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Status pushes are only relevant for the vacuum instances
        $this->SetReceiveDataFilter('.*"Configurator":true.*');
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        if (!$this->HasActiveParent()) {
            $form['actions'][] = [
                'type'  => 'PopupAlert',
                'popup' => [
                    'items' => [[
                        'type'    => 'Label',
                        'caption' => $this->Translate('The Roborock Cloud instance is not active. Please log in there first.')
                    ]]
                ]
            ];
            return json_encode($form);
        }

        $devices = json_decode($this->SendDataToParent(json_encode([
            'DataID'  => self::DATA_TO_CLOUD,
            'Command' => 'GetDevices'
        ])), true);
        if (!is_array($devices) || isset($devices['error'])) {
            $devices = [];
        }

        // Existing instances on the same cloud instance
        $instances = [];
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        foreach (IPS_GetInstanceListByModuleID(self::VACUUM_MODULE) as $instanceID) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] === $parentID) {
                $instances[IPS_GetProperty($instanceID, 'DUID')] = $instanceID;
            }
        }

        $values = [];
        foreach ($devices as $device) {
            $instanceID = $instances[$device['duid']] ?? 0;
            unset($instances[$device['duid']]);
            $value = [
                'address'    => $device['duid'],
                'name'       => $device['name'],
                'model'      => $device['productName'] !== '' ? $device['productName'] : $device['model'],
                'online'     => $device['online'] ? $this->Translate('Yes') : $this->Translate('No'),
                'supported'  => $device['supported'] ? $this->Translate('Yes') : sprintf($this->Translate('No (protocol %s)'), $device['pv']),
                'instanceID' => $instanceID
            ];
            if ($device['supported']) {
                $value['create'] = [
                    'moduleID'      => self::VACUUM_MODULE,
                    'configuration' => ['DUID' => $device['duid']],
                    'name'          => $device['name']
                ];
            }
            $values[] = $value;
        }
        // Instances whose device no longer exists in the account
        foreach ($instances as $duid => $instanceID) {
            $values[] = [
                'address'    => $duid,
                'name'       => IPS_GetName($instanceID),
                'model'      => '',
                'online'     => '',
                'supported'  => '',
                'instanceID' => $instanceID
            ];
        }

        $form['actions'][0]['values'] = $values;
        return json_encode($form);
    }
}
