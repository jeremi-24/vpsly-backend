<?php

namespace App\Enums;

enum LogType: string
{
    case INFO = 'info';
    case SUCCESS = 'success';
    case ERROR = 'error';
    case DEBUG = 'debug';
}
