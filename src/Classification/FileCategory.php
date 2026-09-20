<?php

namespace TicoScope\Classification;

enum FileCategory: string
{
    case Migration = 'migration';
    case Config = 'config';
    case Route = 'route';
    case QueueJob = 'queue-job';
    case EnvExample = 'env-example';
    case Composer = 'composer';
    case Unclassified = 'unclassified';
}
