<?php

namespace App\Billing;

use RuntimeException;

/** Step 6b.3: a SIGTAP zip refused, with the reason in words for the network admin. */
final class SigtapImportException extends RuntimeException {}
