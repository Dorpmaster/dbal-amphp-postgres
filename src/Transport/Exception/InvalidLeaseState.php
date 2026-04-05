<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport\Exception;

use LogicException;

class InvalidLeaseState extends LogicException
{
}
