<?php
declare (strict_types=1);

namespace think\addons;

use think\Exception;

class AddonException extends Exception
{
    public function __construct($message, $code = 0, $data = '')
    {
        $this->message = $message;
        $this->code    = $code;
        $this->data    = $data;
    }
}