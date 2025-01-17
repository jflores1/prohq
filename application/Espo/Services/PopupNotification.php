<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Services;

use Espo\Core\{
    ServiceFactory,
    Utils\Metadata,
    Utils\Log,
};

use Espo\{
    Entities\User,
};

use stdClass;
use Throwable;

class PopupNotification
{
    private $metadata;

    private $serviceFactory;

    private $user;

    private $log;

    public function __construct(Metadata $metadata, ServiceFactory $serviceFactory, User $user, Log $log)
    {
        $this->metadata = $metadata;
        $this->serviceFactory = $serviceFactory;
        $this->user = $user;
        $this->log = $log;
    }

    public function getGroupedList(): stdClass
    {
        $data = $this->metadata->get(['app', 'popupNotifications']) ?? [];

        $data = array_filter($data, function ($item) {
            if (!($item['grouped'] ?? false)) {
                return false;
            }

            if (!($item['serviceName'] ?? null)) {
                return false;
            }

            if (!($item['methodName'] ?? null)) {
                return false;
            }

            $portalDisabled = $item['portalDisabled'] ?? false;

            if ($portalDisabled && $this->user->isPortal()) {
                return false;
            }

            return true;
        });

        $result = (object) [];

        foreach ($data as $type => $item) {
            $serviceName = $item['serviceName'];
            $methodName = $item['methodName'];

            try {
                $service = $this->serviceFactory->create($serviceName);

                $result->$type = $service->$methodName($this->user->id);
            }
            catch (Throwable $e) {
                $this->log->error("Popup notifications: " . $e->getMessage());
            }
        }

        return $result;
    }
}
