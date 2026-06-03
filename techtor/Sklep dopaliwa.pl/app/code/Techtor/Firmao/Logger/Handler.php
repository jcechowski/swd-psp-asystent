<?php

declare(strict_types=1);

namespace Techtor\Firmao\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $loggerType = Logger::INFO;
    protected $fileName = '/var/log/firmao.log';
}
