<?php

declare(strict_types=1);

// CRM route groups are split by capability so the module stays navigable as
// sales workflows expand independently of lead and pipeline management.
require __DIR__.'/routes-core.php';
require __DIR__.'/routes-sales.php';
