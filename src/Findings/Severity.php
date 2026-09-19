<?php

namespace TicoScope\Findings;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
