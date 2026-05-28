<?php

namespace App\Exceptions;

/**
 * Thrown when switching to sw_cf mode but CF zone is still pending NS activation.
 * The exception message contains the NS servers that need to be added at the registrar.
 * Caught in SwitchTrafficController to show operator a helpful message instead of an error.
 */
class PendingCfActivationException extends \RuntimeException {}
