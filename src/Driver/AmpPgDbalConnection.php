<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Doctrine\DBAL\Connection;

final class AmpPgDbalConnection extends Connection
{
    public function close(): void
    {
        $driverConnection = $this->_conn;

        if ($driverConnection instanceof AmpPgConnection) {
            $driverConnection->close();
        }

        parent::close();
    }
}
