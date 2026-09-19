<?php

namespace TicoScope\Diff;

enum ChangeType: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}
